<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\StructuredFields\StructuredFieldValues;
use Shipard\Core\StructuredFields\StructuredSchema;
use Shipard\Module\Docs\Core\OwnCompanyResolver;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Sestavení snapshotu podání (issue #55, D15). Jediná autorita, která
 * zapisuje do `economy_vat_filing_items` a výstupních řádků per typ.
 *
 * Snapshot jde **stejnou cestou jako živý report**: doklady z
 * `VatDocumentSelection`, výpočet týmiž čistými kalkulátory
 * (`VatReturnCalculator`, `ControlStatementCalculator`,
 * `RecapitulativeStatementCalculator`), sekce KH z rozpadu enginem. Žádná
 * druhá výpočetní větev — jediné, co snapshot přidává, je zaokrouhlení
 * podaných hodnot (`FilingRounding`) a materializace mapování.
 *
 * Rozdíl proti živému reportu je v přísnosti: kód DPH bez mapování živý
 * report jen hlásí, tady je **tvrdá chyba** — z podání nesmí nic tiše
 * vypadnout.
 *
 * Sestavení je idempotentní (DELETE + INSERT celého podání), takže
 * „Přepočítat" nad konceptem nevyrábí duplicity. Podané podání composer
 * odmítne.
 *
 * **Transakci vlastní volající** — composer běží i z
 * `FilingDocument::afterPersist`, tedy uvnitř save transakce, a MariaDB
 * nemá vnořené transakce (vzor VatPeriodRecalculator).
 */
final class FilingComposer
{
    /** Pojistka proti zacyklení řetězu dodatečných podání. */

    /**
     * Zálohový koeficient odpočtu použitý při výpočtu ř. 52 — do snapshotu
     * (`result.return.coefficient`), protože XML ho vypisuje jako
     * `koef_p20_nov` a živý resolver by po změně koeficientu vydal jiné
     * číslo, než jaké se podávalo.
     *
     * @var ?array{value: float, source: string, year: int}
     */
    private ?array $returnCoefficient = null;

    /** Čtení snapshotu podání (řádky, řetěz kumulativního stavu) — sdílené se zaúčtováním. */
    private ?FilingSnapshotLoader $snapshots = null;

    public function __construct(
        private readonly \Dibi\Connection $db,
        private readonly ?ConfigRuntime $config,
    ) {}

    /**
     * Přepíše snapshot podání ve stavu Sestaveno.
     *
     * @return array{items: int, rows: int, isEmpty: bool} počty zapsaných řádků
     */
    public function compose(int $filingId): array
    {
        $filing = $this->loadFiling($filingId);
        if ($filing === null) {
            throw new \DomainException("Podání #{$filingId} nenalezeno");
        }
        if ((int) $filing['docState'] !== FilingDocument::DOC_STATE_COMPOSED) {
            throw new \DomainException(
                "Podání #{$filingId} není ve stavu Sestaveno — snapshot podaného ani zrušeného"
                . ' podání se nepřepočítává.',
            );
        }

        $period = $this->loadPeriod((int) $filing['report_period']);
        if ($period === null) {
            throw new \DomainException("Podání #{$filingId} míří na neexistující daňové tvrzení");
        }

        $mapping = VatOutputsMapping::fromConfig($this->config);
        if ($mapping === null || $this->config === null) {
            throw new \RuntimeException(
                'Chybí kompilovaná konfigurace mapování DPH výstupů (economy.vat.reports.cz)'
                . ' — spusťte ds-upgrade.',
            );
        }

        $type   = (string) $period['report_type'];
        $kind   = (string) $filing['filing_kind'];
        $column = ReportPeriodDocument::HEAD_COLUMN_BY_TYPE[$type] ?? null;
        if ($column === null) {
            throw new \DomainException("Neznámý typ tvrzení '{$type}'");
        }

        $this->returnCoefficient = null;

        $dsConnection = new DataSourceConnection($this->db);
        $docs         = (new VatDocumentSelection($dsConnection, $this->config))->load((int) $period['id'], $column);
        $vatCodes     = (new VatRateResolver($this->config))->getVatCodes('cz', null, null, true);
        $csCalculator = new ControlStatementCalculator($mapping, $vatCodes);

        $this->clear($filingId);

        $itemCount = $this->writeItems($filingId, $docs, $mapping, $csCalculator, $vatCodes);

        $messages = [];
        $rowCount = match ($type) {
            'return' => $this->writeReturnRows($filingId, $filing, $kind, $mapping, $period, $docs, $dsConnection, $messages),
            'cs'     => $this->writeControlStatementRows($filingId, $csCalculator, $docs, $messages),
            'rs'     => $this->writeRecapitulativeRows($filingId, $mapping, $type, $docs, $messages),
        };
        $result = $this->buildResult($filingId, $type, $kind, $docs, $itemCount, $rowCount);

        if ($type === 'return') {
            $this->appendCrossCheck($dsConnection, $docs, $vatCodes, $result, $messages);
        }

        $update = [
            'result'   => self::encodeJson($result),
            'messages' => self::encodeJson($messages === [] ? null : $messages),
        ];
        $header = $this->buildHeader($filing, $period, $type, $result);
        if ($header !== null) {
            $update['header'] = $header;
        }
        $this->db->update(FilingDocument::TABLE, $update)->where('id = %i', $filingId)->execute();

        return ['items' => $itemCount, 'rows' => $rowCount, 'isEmpty' => (bool) $result['isEmpty']];
    }

    /**
     * Přepíše hlavičku konceptu čerstvým předvyplněním z profilu podatele,
     * vlastní firmy a registrace — akce „Načíst hlavičku z profilu"
     * (#55 F3-5). Ruční úpravy v tabu Hlavička tím zaniknou; to je smysl
     * akce, ne vedlejší účinek, a potvrzení je věc UI. Podané ani zrušené
     * podání se nemění: hlavička je součást snapshotu.
     */
    public function resetHeader(int $filingId): void
    {
        $filing = $this->loadFiling($filingId);
        if ($filing === null) {
            throw new \DomainException("Podání #{$filingId} nenalezeno");
        }
        if ((int) $filing['docState'] !== FilingDocument::DOC_STATE_COMPOSED) {
            throw new \DomainException(
                "Podání #{$filingId} není ve stavu Sestaveno — hlavička podaného ani zrušeného"
                . ' podání se nemění.',
            );
        }

        $period = $this->loadPeriod((int) $filing['report_period']);
        if ($period === null) {
            throw new \DomainException("Podání #{$filingId} míří na neexistující daňové tvrzení");
        }

        $header = $this->prefillHeader($period, (string) $period['report_type'], self::decodeJson($filing['result'] ?? null));
        if ($header === null) {
            throw new \RuntimeException(
                'Chybí kompilované schéma hlavičky podání (economy.vat.filingHeaderCz*) — spusťte ds-upgrade.',
            );
        }

        $this->db->update(FilingDocument::TABLE, ['header' => $header])->where('id = %i', $filingId)->execute();
    }

    /** Smaže snapshot podání (přepočet i úklid po smazání konceptu). */
    public function clear(int $filingId): void
    {
        foreach (FilingDocument::SNAPSHOT_TABLES as $table) {
            $this->db->delete($table)->where('filing = %i', $filingId)->execute();
        }
    }

    // ── Dokladová úroveň ────────────────────────────────────────────────────

    /**
     * Items: per doklad × řádek rekapitulace, s materializovaným mapováním.
     * Kód bez záznamu v mapování vyhodí `forCode()` jako DomainException —
     * sestavení selže a transakce se rollbackne.
     *
     * @param list<array<string, mixed>> $docs
     * @param array<string, array<string, mixed>> $vatCodes
     */
    private function writeItems(
        int $filingId,
        array $docs,
        VatOutputsMapping $mapping,
        ControlStatementCalculator $csCalculator,
        array $vatCodes,
    ): int {
        $count = 0;
        foreach ($docs as $doc) {
            foreach ($doc['recap'] ?? [] as $recapRow) {
                $code    = (string) $recapRow['vat_code'];
                $outputs = $mapping->forCode($code);
                $dp3     = $outputs['dp3'];
                $kh      = $outputs['kh'];
                $sh      = $outputs['sh'];
                $section = $csCalculator->sectionForCode($doc, $code);

                $this->db->insert('economy_vat_filing_items', [
                    'filing'             => $filingId,
                    'doc_head'           => (int) $doc['id'],
                    'doc_type'           => (string) $doc['doc_type'],
                    'doc_number'         => (string) $doc['doc_number'],
                    'partner_doc_number' => (string) $doc['partner_doc_number'],
                    'total_amount_dom'   => (float) $doc['total_amount_dom'],
                    'vat_duzp'           => $doc['vat_duzp'],
                    'vat_dppd'           => $doc['vat_dppd'],
                    'partner_vat_id'     => $this->partnerVatId($doc, $code, $section, $vatCodes),
                    'vat_code'           => $code,
                    'vat_pct'            => (float) $recapRow['vat_pct'],
                    'base_dom'           => (float) $recapRow['base_dom'],
                    'tax_dom'            => (float) $recapRow['tax_dom'],
                    'is_reverse_pair'    => !empty($recapRow['is_reverse_pair']) ? 1 : 0,
                    'dp3_row'            => $dp3 !== null ? (int) $dp3['row'] : null,
                    'dp3_col'            => $dp3 !== null ? ($dp3['col'] ?? null) : null,
                    'kh_group'           => $kh !== null ? (string) $kh['group'] : null,
                    'kh_section'         => $section,
                    'kh_kod_pred_pl'     => $kh !== null && isset($kh['kodPredPl']) ? (int) $kh['kodPredPl'] : null,
                    'sh_kod'             => $sh !== null ? (int) $sh['kod'] : null,
                ])->execute();
                $count++;
            }
        }
        return $count;
    }

    /**
     * DIČ protistrany tak, jak ho vidí výkaz: u přijatých plnění dodavatel,
     * u našich odběratel. Sekce KH je autorita; bez sekce (řádek jen do
     * přiznání) rozhoduje směr kódu DPH z `world.vat`.
     *
     * @param array<string, mixed> $doc
     * @param array<string, array<string, mixed>> $vatCodes
     */
    private function partnerVatId(array $doc, string $code, ?string $section, array $vatCodes): string
    {
        $received = $section !== null
            ? in_array($section, ControlStatementCalculator::RECEIVED_SECTIONS, true)
            : (string) ($vatCodes[$code]['direction'] ?? 'input') === 'input';

        return $received
            ? (string) ($doc['supplier_vat_id'] ?? '')
            : (string) ($doc['customer_vat_id'] ?? '');
    }

    // ── Výstupní řádky: přiznání ────────────────────────────────────────────

    /**
     * @param array<string, mixed> $filing
     * @param array<string, mixed> $period
     * @param list<array<string, mixed>> $docs
     * @param list<array<string, mixed>> $messages
     */
    private function writeReturnRows(
        int $filingId,
        array $filing,
        string $kind,
        VatOutputsMapping $mapping,
        array $period,
        array $docs,
        DataSourceConnection $dsConnection,
        array &$messages,
    ): int {
        $year        = (int) substr(self::isoDate($period['date_begin']), 0, 4);
        $coefficient = (new DeductionCoefficientResolver($dsConnection))
            ->provisional((int) $period['vat_registration'], $year) + ['year' => $year];

        $this->returnCoefficient = $coefficient;

        $calculated = (new VatReturnCalculator($mapping))->calculate($docs, $coefficient['value']);
        $unit       = $mapping->roundingUnit('return');

        $exact = FilingRounding::exactRows($calculated);
        $filed = FilingRounding::vatReturnFiled($calculated, $coefficient['value'], $unit);

        // Dodatečné přiznání vykazuje rozdíl proti poslední známé daňové
        // povinnosti — tedy proti KUMULATIVNÍMU podanému stavu, ne proti
        // hodnotám jednoho předchozího podání (to samo mohlo být dodatečné
        // a nést jen deltu).
        if ($kind === FilingDocument::KIND_SUPPLEMENTARY
            && $mapping->supplementaryMode('return') === 'diff'
        ) {
            $previousId = (int) ($filing['previous_filing'] ?? 0);
            if ($previousId <= 0) {
                throw new \DomainException(
                    'Dodatečné přiznání nemá předchozí podání, proti kterému by se vykázal rozdíl.',
                );
            }
            $filed = FilingRounding::vatReturnDiff($filed, $this->cumulativeFiledRows($previousId));
        }

        $this->returnCoefficientMessage($coefficient, $exact, $messages);

        $rows = array_unique(array_merge(array_keys($exact), array_keys($filed)));
        sort($rows);
        $empty = ['base' => 0.0, 'taxFull' => 0.0, 'taxReduced' => 0.0];
        foreach ($rows as $row) {
            $e = $exact[$row] ?? $empty;
            $f = $filed[$row] ?? $empty;
            $this->db->insert('economy_vat_filing_return_rows', [
                'filing'            => $filingId,
                'row'               => $row,
                'is_computed'       => in_array($row, FilingRounding::COMPUTED_ROWS, true) ? 1 : 0,
                'base'              => $e['base'],
                'tax_full'          => $e['taxFull'],
                'tax_reduced'       => $e['taxReduced'],
                'base_filed'        => $f['base'],
                'tax_full_filed'    => $f['taxFull'],
                'tax_reduced_filed' => $f['taxReduced'],
            ])->execute();
        }
        return count($rows);
    }

    /**
     * Koeficient odpočtu do zpráv — jen když je co krátit (stejná logika
     * jako živý report: u firem bez osvobozených plnění by šuměl).
     *
     * @param array{value: float, source: string, year: int} $coefficient
     * @param array<int, array{base: float, taxFull: float, taxReduced: float}> $exact
     * @param list<array<string, mixed>> $messages
     */
    private function returnCoefficientMessage(array $coefficient, array $exact, array &$messages): void
    {
        if (abs($exact[46]['taxReduced'] ?? 0.0) < 0.005) {
            return;
        }
        $messages[] = [
            'code'   => 'vatReturn.deductionCoefficient',
            'value'  => $coefficient['value'],
            'source' => $coefficient['source'],
            'year'   => $coefficient['year'],
        ];
    }

    /**
     * Kumulativní podaný stav podání (řádné a opravné = plná náhrada,
     * dodatečné se přičítá) — sdílené se zaúčtováním přiznání.
     *
     * @return array<int, array{base: float, taxFull: float, taxReduced: float}>
     */
    private function cumulativeFiledRows(int $filingId): array
    {
        return $this->snapshots()->cumulativeFiledRows($filingId);
    }

    private function snapshots(): FilingSnapshotLoader
    {
        return $this->snapshots ??= new FilingSnapshotLoader($this->db);
    }

    // ── Výstupní řádky: kontrolní hlášení ───────────────────────────────────

    /**
     * @param list<array<string, mixed>> $docs
     * @param list<array<string, mixed>> $messages
     */
    private function writeControlStatementRows(
        int $filingId,
        ControlStatementCalculator $calculator,
        array $docs,
        array &$messages,
    ): int {
        $calculated = $calculator->calculate($docs);
        $count      = 0;

        foreach ($calculated['sections'] as $section => $sectionRows) {
            foreach ($sectionRows as $row) {
                $this->db->insert('economy_vat_filing_cs_rows', [
                    'filing'         => $filingId,
                    'section'        => (string) $section,
                    'row_kind'       => $row['docId'] === null ? 'aggregate' : 'detail',
                    'doc_head'       => $row['docId'],
                    'doc_number'     => (string) ($row['evidNumber'] ?? ''),
                    'partner_vat_id' => (string) ($row['vatId'] ?? ''),
                    'vat_dppd'       => $row['dppd'],
                    'kod_pred_pl'    => $row['kodPredPl'],
                    'base1'          => $row['base1'],
                    'tax1'           => $row['tax1'],
                    'base2'          => $row['base2'],
                    'tax2'           => $row['tax2'],
                    'base3'          => $row['base3'],
                    'tax3'           => $row['tax3'],
                ])->execute();
                $count++;
            }
        }

        foreach ($calculated['errors'] as $error) {
            $messages[] = ['code' => 'controlStatement.' . $error['code']] + $error;
        }
        return $count;
    }

    // ── Výstupní řádky: souhrnné hlášení ────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $docs
     * @param list<array<string, mixed>> $messages
     */
    private function writeRecapitulativeRows(
        int $filingId,
        VatOutputsMapping $mapping,
        string $type,
        array $docs,
        array &$messages,
    ): int {
        $calculated = (new RecapitulativeStatementCalculator($mapping))->calculate($docs);
        $unit       = $mapping->roundingUnit($type);

        foreach ($calculated['rows'] as $row) {
            $this->db->insert('economy_vat_filing_rs_rows', [
                'filing'         => $filingId,
                'kod'            => (int) $row['kod'],
                'partner_vat_id' => (string) $row['vatId'],
                'count'          => (int) $row['count'],
                'value'          => (float) $row['value'],
                'value_filed'    => FilingRounding::recapitulativeValueFiled((float) $row['value'], $unit),
            ])->execute();
        }

        foreach ($calculated['errors'] as $error) {
            $messages[] = ['code' => 'recapitulativeStatement.' . $error['code']] + $error;
        }
        return count($calculated['rows']);
    }

    // ── Souhrn a křížová kontrola ───────────────────────────────────────────

    /**
     * Souhrn podání pro viewer a seznam — to, co uživatel potřebuje vidět
     * bez čtení řádků.
     *
     * @param list<array<string, mixed>> $docs
     * @return array<string, mixed>
     */
    private function buildResult(
        int $filingId,
        string $type,
        string $kind,
        array $docs,
        int $itemCount,
        int $rowCount,
    ): array {
        $result = [
            'type'      => $type,
            'kind'      => $kind,
            'isEmpty'   => $itemCount === 0,
            'docCount'  => count($docs),
            'itemCount' => $itemCount,
            'rowCount'  => $rowCount,
        ];

        if ($type === 'return') {
            $filed = $this->loadFiledRows($filingId);
            foreach ([62, 63, 64, 65, 66] as $row) {
                $result['return']['row' . $row] = $filed[$row]['taxFull'] ?? 0.0;
            }
            // Koeficient ř. 52 patří do snapshotu — XML ho vypisuje jako
            // `koef_p20_nov` a po pozdější změně koeficientu by živý
            // resolver vydal jiné číslo, než jaké se podávalo.
            if ($this->returnCoefficient !== null) {
                $result['return']['coefficient'] = round($this->returnCoefficient['value'], 4);
                $result['return']['coefficientSource'] = $this->returnCoefficient['source'];
            }
            return $result;
        }

        if ($type === 'cs') {
            $rows = $this->db->fetchAll(
                'SELECT [section], COUNT(*) AS [cnt], SUM([base1] + [base2] + [base3]) AS [base],'
                . ' SUM([tax1] + [tax2] + [tax3]) AS [tax]'
                . ' FROM [economy_vat_filing_cs_rows] WHERE [filing] = %i GROUP BY [section] ORDER BY [section]',
                $filingId,
            );
            foreach ($rows as $row) {
                $result['cs']['sections'][(string) $row['section']] = [
                    'rows' => (int) $row['cnt'],
                    'base' => round((float) $row['base'], 2),
                    'tax'  => round((float) $row['tax'], 2),
                ];
            }
            $result['cs']['sections'] ??= [];
            $result['cs']['dp3Base']    = $this->returnRowBases($filingId);
            return $result;
        }

        $row = $this->db->fetch(
            'SELECT COUNT(*) AS [cnt], SUM([count]) AS [supplies], SUM([value]) AS [value],'
            . ' SUM([value_filed]) AS [value_filed]'
            . ' FROM [economy_vat_filing_rs_rows] WHERE [filing] = %i',
            $filingId,
        );
        $result['rs'] = [
            'rows'       => (int) ($row['cnt'] ?? 0),
            'supplies'   => (int) ($row['supplies'] ?? 0),
            'value'      => round((float) ($row['value'] ?? 0.0), 2),
            'valueFiled' => round((float) ($row['value_filed'] ?? 0.0), 2),
        ];
        return $result;
    }

    /**
     * Křížová kontrola proti deníku — stav v okamžiku sestavení. Rozdíly
     * podání neblokují (deník může být rozvázaný z jiného důvodu), ale
     * musí být vidět, že se podávalo s rozdílem.
     *
     * @param list<array<string, mixed>> $docs
     * @param array<string, array<string, mixed>> $vatCodes
     * @param array<string, mixed> $result
     * @param list<array<string, mixed>> $messages
     */
    private function appendCrossCheck(
        DataSourceConnection $dsConnection,
        array $docs,
        array $vatCodes,
        array &$result,
        array &$messages,
    ): void {
        $check = (new VatJournalCrossCheck($dsConnection, $vatCodes))->check($docs);
        $result['crossCheck'] = [
            'differences'      => count($check['differences']),
            'journalErrorRows' => $check['journalErrorRows'],
        ];
        foreach ($check['differences'] as $difference) {
            $messages[] = ['code' => 'vatReturn.journalMismatch'] + $difference;
        }
        if ($check['journalErrorRows'] > 0) {
            $messages[] = [
                'code' => 'vatReturn.journalErrorRows',
                'rows' => $check['journalErrorRows'],
            ];
        }
    }

    /**
     * Základy daně per řádek přiznání spočítané nad dokladovou úrovní
     * **tohoto** podání. Kontrolní hlášení z nich staví větu C, která není
     * součtem svých řádků, ale kontrolou proti přiznání (#55 X11):
     * `obrat23` = ř. 1, `celk_zd_a2` = Σ ř. 3, 4, 5, 6, 9, 12, 13 atd.
     * Který řádek jde do kterého atributu, říká mapovací config —
     * snapshot drží jen fakta.
     *
     * @return array<string, float> číslo řádku (string kvůli JSON) → základ
     */
    private function returnRowBases(int $filingId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [dp3_row], SUM([base_dom]) AS [base] FROM [economy_vat_filing_items]'
            . ' WHERE [filing] = %i AND [dp3_row] IS NOT NULL GROUP BY [dp3_row] ORDER BY [dp3_row]',
            $filingId,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) (int) $row['dp3_row']] = round((float) $row['base'], 2);
        }
        return $out;
    }

    // ── Hlavička podání ─────────────────────────────────────────────────────

    /**
     * Hlavička podání při sestavení (#55 X2): předvyplní se jen prázdná.
     * Vrací JSON pro sloupec `header`, nebo `null` když se hlavička nemá
     * měnit.
     *
     * **Přepočet konceptu hlavičku nepřepisuje** — uživatelské úpravy
     * v tabu Hlavička jsou to jediné, co snapshot nese ručně, a přepočet je
     * běžná operace nad konceptem. Obnovu z profilu dělá `resetHeader()`.
     *
     * @param array<string, mixed> $filing
     * @param array<string, mixed> $period
     * @param array<string, mixed> $result
     */
    private function buildHeader(array $filing, array $period, string $type, array $result): ?string
    {
        $stored = StructuredFieldValues::decode($filing['header'] ?? null);
        if ($stored !== null && !StructuredFieldValues::isEmpty($stored)) {
            return null;
        }
        return $this->prefillHeader($period, $type, $result);
    }

    /**
     * Čerstvé předvyplnění hlavičky: věta P z profilu podatele na
     * registraci, identita z vlastní firmy, DIČ z registrace a defaulty
     * věty D. `null` bez zkompilovaného schématu.
     *
     * @param array<string, mixed> $period
     * @param array<string, mixed> $result snapshot `result` (kvůli `trans`)
     */
    private function prefillHeader(array $period, string $type, array $result): ?string
    {
        $cfgItem = FilingHeaderSchema::forReportType($type);
        $schema  = $cfgItem !== null ? StructuredSchema::fromCfgItem($this->config, $cfgItem) : null;
        if ($schema === null) {
            return null;
        }

        $registration = $this->loadRegistration((int) ($period['vat_registration'] ?? 0));
        $profile      = StructuredFieldValues::decode($registration['filing_profile'] ?? null) ?? [];

        $values = FilingHeaderSchema::prefill(
            $schema,
            $profile,
            $this->ownCompanyIdentity((string) ($profile['typ_ds'] ?? '')),
            $this->headerFilingDefaults($registration, $type, $result),
        );

        return StructuredFieldValues::encode($values, $schema);
    }

    /**
     * Identita subjektu pro větu P — obchodní jméno u právnické osoby,
     * jméno a příjmení u fyzické. Profil ji nenese (je to údaj vlastní
     * firmy), typ subjektu ale ano. Bez nastavené vlastní firmy zůstanou
     * pole prázdná a doplní je uživatel.
     *
     * @return array<string, mixed>
     */
    private function ownCompanyIdentity(string $subjectType): array
    {
        $person = (new OwnCompanyResolver($this->db))->getOwnPersonData();
        if ($person === null) {
            return [];
        }

        if ($subjectType === 'F') {
            return [
                'prijmeni' => (string) ($person['last_name'] ?? ''),
                'jmeno'    => (string) ($person['first_name'] ?? ''),
                'titul'    => (string) ($person['title_before'] ?? ''),
            ];
        }
        return ['zkrobchjm' => (string) ($person['full_name'] ?? '')];
    }

    /**
     * Údaje o podání do věty D a DIČ do věty P.
     *
     * `trans` = vznikla daňová povinnost: bereme daň na výstupu celkem
     * (ř. 62) — povinnost přiznat daň vzniká z výstupu bez ohledu na to,
     * jestli podání končí vlastní daní, nebo nadměrným odpočtem.
     * Uživatel ho může v hlavičce přepnout.
     *
     * @param array<string, mixed> $registration
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function headerFilingDefaults(array $registration, string $type, array $result): array
    {
        $defaults = ['dic' => self::taxNumberDigits((string) ($registration['vat_id'] ?? ''))];

        if ($type === 'return') {
            $defaults['trans'] = abs((float) ($result['return']['row62'] ?? 0.0)) >= 0.005;
        }
        return $defaults;
    }

    /** Číselná část DIČ — XSD `dic` je `[0-9]{1,10}`, tedy bez prefixu státu. */
    private static function taxNumberDigits(string $vatId): string
    {
        return (string) preg_replace('/\D+/', '', $vatId);
    }

    // ── DB přístup ──────────────────────────────────────────────────────────

    /** @return ?array<string, mixed> */
    private function loadFiling(int $filingId): ?array
    {
        $row = $this->db->fetch('SELECT * FROM %n WHERE [id] = %i', FilingDocument::TABLE, $filingId);
        return $row !== null ? $row->toArray() : null;
    }

    /** @return ?array<string, mixed> */
    private function loadPeriod(int $periodId): ?array
    {
        $row = $this->db->fetch(
            'SELECT [id], [report_type], [name], [vat_registration], [date_begin], [date_end]'
            . ' FROM [economy_vat_report_periods] WHERE [id] = %i',
            $periodId,
        );
        return $row !== null ? $row->toArray() : null;
    }

    /** @return array<string, mixed> prázdné pole, když registrace chybí */
    private function loadRegistration(int $registrationId): array
    {
        if ($registrationId <= 0) {
            return [];
        }
        $row = $this->db->fetch(
            'SELECT [id], [vat_id], [filing_profile] FROM [economy_codebooks_vat_registrations]'
            . ' WHERE [id] = %i',
            $registrationId,
        );
        return $row !== null ? $row->toArray() : [];
    }

    /**
     * Podané hodnoty řádků přiznání per číslo řádku.
     *
     * @return array<int, array{base: float, taxFull: float, taxReduced: float}>
     */
    private function loadFiledRows(int $filingId): array
    {
        return $this->snapshots()->filedRows($filingId);
    }

    /** Dibi neumí PHP pole pro `json` sloupce — serializace je na nás. */
    private static function encodeJson(mixed $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> prázdné pole pro NULL i nevalidní JSON */
    private static function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function isoDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return substr((string) $value, 0, 10);
    }
}
