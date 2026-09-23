<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Economy\Codebooks\FiscalMonthLookup;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Base class for all document types (issued invoice, received invoice, …).
 *
 * Polymorphism: docs_core_heads has `doc_type` (enumString) which resolves
 * to a specific subclass via cfgItem docs.core.docTypes (`subclass` attr).
 * In Phase 1/2 the only concrete subclass is DocsHeadsDocument; Phase 6
 * adds IssuedInvoiceDocument / ReceivedInvoiceDocument in docs.invoicesOut
 * / docs.invoicesIn.
 *
 * The orchestration pipeline runs in `beforeSave`:
 *   1. denormalize doc_type (+ cash_desk for bound types) from number_series
 *   1b. resolve partner_balance (+ payment_terminal) — osoba pro saldokonto
 *      (PartnerBalanceResolver, #72; běží i ve validate, idempotentní)
 *   2. apply date defaults (accounting_date, vat_duzp, vat_dppd, due_date;
 *      nedaňový typ — docTypes[].tax_document: false — DUZP/DPPD nuluje)
 *   3. apply home_currency from DS config
 *   4. resolve fiscal_year/fiscal_month (vat_period/cs_period/rs_period plní
 *      economy.vat přes beforeSave documentEventHandler — docs.core o nich neví)
 *   5. calculateRowPrice + calculateRowVat for each row
 *   6. buildVatRecapitulation (with reverse charge pairs)
 *   7. sumTotals + apply rounding + reconcileRowsToRecap (obě měny)
 *      + apply exchange rate to *_dom
 *   8. processStateTransition (assignNumber {0,10}→{40}, releaseNumber 80→10)
 *   9. maintainSnapshots (buildSnapshots when partner changes / first time)
 *  10. applyPaymentReferenceDefault from sequence_number
 */
abstract class DocDocument extends Document
{
    /** Default split for due_date when partner has no payment_term_days. */
    private const DEFAULT_PAYMENT_TERM_DAYS = 14;

    /** Snapshots are built/refreshed when entering Done or while being edited. */
    private const SNAPSHOT_STATES = [40, 80];

    /** Deleted doc state — period lookups skip only this; archived periods stay resolvable by date. */
    private const DOC_STATE_DELETED = 90;

    /**
     * Vazba řady (docTypes[].series_binding) → sloupec hlavičky, do kterého
     * se hodnota z řady denormalizuje. Sklad zatím na hlavičce sloupec nemá
     * (přijde se skladovými doklady), proto tu není.
     */
    private const BINDING_HEAD_COLUMNS = ['cash_desk' => 'cash_desk'];

    /**
     * True while saving a migrated document (`_importNumber` present).
     * Import never builds snapshots from today's person data — that would
     * be factually wrong for historical documents. Not building from the
     * directory does NOT mean leaving them empty, though: the partner side
     * is persisted verbatim from the canonical payload when present
     * (`_importPartnerSnapshot`, era-correct data assembled by the
     * exporter), and the own side builds normally (own company + head's
     * bank_account/vat_registration). See buildImportSnapshots().
     */
    private bool $importMode = false;

    /**
     * Dobový snapshot partnera z canonical payloadu (`_importPartnerSnapshot`),
     * konzumovaný v beforeSave. Null = payload snapshot nenese (kanonické
     * zdroje bez stran) — partnerský sloupec zůstává NULL.
     *
     * @var array<string, mixed>|null
     */
    private ?array $importPartnerSnapshot = null;

    /**
     * Mez dorovnání řádků na **převzatou** rekapitulaci — základ pro
     * `max(0,02; 0,01 × počet řádků skupiny)`. Nad ní se řádky nedorovnávají
     * a validace vydá warning `rows_recap_mismatch`: takový rozdíl už není
     * haléřové zaokrouhlení, ale chybějící nebo špatně zadané řádky.
     */
    protected const DECLARED_RECAP_TOLERANCE = 0.02;

    /**
     * True, když se v posledním `beforeSave` použila **převzatá** rekapitulace
     * (a je neprázdná). Pak je autoritou celého dokladu: součty hlavičky se
     * berou jen z ní, řádky mimo ni se nepřičítají — jinak by se u importu
     * (kód řádku se s kódem rekapitulace nemusí krýt) základ započítal dvakrát.
     */
    private bool $recapDeclared = false;

    private ?VatRateResolver $vatRateResolver = null;
    private ?OwnCompanyResolver $ownCompanyResolver = null;
    private ?PersonSnapshotBuilder $personSnapshotBuilder = null;

    /**
     * Rows after the compute pipeline (prices, VAT, _dom amounts) from the
     * last beforeSave run. afterPersist persists their computed columns to
     * DB — Phase 2 (accounting engine) reads row values from DB, so they
     * must stay current even for rows managed via the sub-form.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $computedRows = [];

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty($data['number_series'])) {
            $result->addError('number_series', 'Číselná řada je povinná', 'required');
        }
        if (empty($data['issue_date'])) {
            $result->addError('issue_date', 'Datum vystavení je povinné', 'required');
        }
        if (empty($data['accounting_date'])) {
            $result->addError('accounting_date', 'Účetní datum je povinné', 'required');
        }
        // Ruční zařazení do kontrolního hlášení (extension economy.vat,
        // cfgItem economy.vat.controlStatementModes, #77): enumInt se při
        // uložení proti číselníku nekontroluje, hodnoty 0–3 jsou 1:1 se
        // starým vatCS. docs.core na economy.vat nezávisí — proto rozsah
        // natvrdo, ne konstanta kalkulátoru.
        if (isset($data['cs_mode']) && $data['cs_mode'] !== ''
            && !in_array((int) $data['cs_mode'], [0, 1, 2, 3], true)
        ) {
            $result->addError('cs_mode', 'Neplatný režim zařazení do kontrolního hlášení', 'invalid_value');
        }

        // validate() běží před beforeSave — typ, pokladna z řady a směr se
        // kontrolují nad denormalizovanými hodnotami (idempotentní, 1 SELECT).
        $this->denormalizeFromSeries($data);
        $this->validateBindingAndDirection($data, $result);
        // Plátce (osoba pro saldokonto) se odvozuje už tady, aby validace
        // brány / plátce (CashDeskDocumentBase) běžely nad odvozenou hodnotou.
        $this->resolvePartnerBalance($data);
        $this->validatePaymentIntermediary($data, $result);

        $newState = (int) ($data['docState'] ?? 10);

        if (in_array($newState, [40, 80], true)) {
            if ($this->headPartnerRequired() && empty($data['partner'])) {
                $result->addError('partner', 'Partner je povinný', 'required');
            }
            $vatMode = (int) ($data['vat_mode'] ?? 1);
            if ($vatMode !== 0 && empty($data['vat_registration'])) {
                $result->addError('vat_registration', 'Registrace DPH je povinná', 'required');
            }
            // Header-only save (řádky spravuje sub-form, v payloadu nejsou)
            // nesmí falešně padat na no_rows — fallback čte řádky z DB.
            if (count($this->resolveRowsForCompute($data)) === 0) {
                $result->addError('rows', 'Doklad musí mít alespoň jeden řádek', 'no_rows');
            }
            if (!empty($data['doc_currency']) && !empty($data['home_currency'])
                && $data['doc_currency'] !== $data['home_currency']
                && empty($data['exchange_rate'])
            ) {
                $result->addError('exchange_rate', 'Kurz je povinný pro cizí měnu', 'required');
            }

            // Own company must be configured before confirming any document
            if ($this->db !== null) {
                $resolver = $this->ownCompanyResolver();
                if ($resolver->getOwnPersonId() === null) {
                    $result->addError(
                        ValidationError::FIELD_FORM,
                        'Není nastavena vlastní firma. Otevři Osoby a označ záznam jako vlastní firmu.',
                        'no_own_company',
                    );
                }
            }
        }

        if ($newState === 40) {
            $this->validateRowOperations($data, $result);
        }

        $this->validateDeclaredRecap($data, $result);

        return $result;
    }

    /**
     * Kontroly **převzaté** rekapitulace (spec `docs/vat-calculation.md` § 5).
     * Obě jsou warningy — uložení neblokují: rekapitulace dodavatele je fakt
     * i když je haléřově „špatně" (nárok na odpočet je částka z faktury),
     * úkolem kontroly je nesrovnalost zviditelnit.
     *
     * - `vat_recap_inconsistent` — řádek si vnitřně neodpovídá (`base + tax`
     *   ≠ `total`, daň ≠ sazba ze základu, u kódu ze sazebníku neznámá sazba
     *   k DUZP). Oddaňovací páry a nulové sazby se přeskakují.
     * - `rows_recap_mismatch` — Σ řádkových cen per (kód, sazba) neodpovídá
     *   rekapitulaci (v mode 1 základu, v mode 2 celkem). Mez je stejná jako
     *   u dorovnání řádků ({@see reconcileRowsToRecap}), takže warning padne
     *   právě tehdy, když se řádky odmítly dorovnat a invariant „Σ řádků =
     *   rekapitulace" pro tu skupinu neplatí.
     *
     * Přepočítaná rekapitulace kontroly nepotřebuje — vzniká z řádků, takže
     * jim vyhoví konstrukčně.
     *
     * @param array<string, mixed> $data
     */
    protected function validateDeclaredRecap(array &$data, ValidationResult $result): void
    {
        if ((int) ($data['vat_recap_source'] ?? 0) !== 1) {
            return;
        }
        $recap = isset($data['vatRecap']) && is_array($data['vatRecap']) && $data['vatRecap'] !== []
            ? $data['vatRecap']
            : $this->loadVatRecapFromDb($data['id'] ?? null);
        if ($recap === []) {
            return;
        }

        $resolved = $this->resolveVatCodesForDoc($data);
        $vatCodes = $resolved['codes'] ?? null;
        $country  = $resolved['country'] ?? null;
        $duzp     = (string) ($data['vat_duzp'] ?? $data['issue_date'] ?? '');

        foreach ($recap as $r) {
            if (!is_array($r) || !empty($r['is_reverse_pair'])) {
                continue;
            }
            $pct = (float) ($r['vat_pct'] ?? 0);
            if ($pct === 0.0) {
                continue;
            }
            $base  = round((float) ($r['base'] ?? 0), 2);
            $tax   = round((float) ($r['tax'] ?? 0), 2);
            $total = round((float) ($r['total'] ?? 0), 2);
            $code  = (string) ($r['vat_code'] ?? '');
            $noPayTax = !empty(($vatCodes[$code] ?? [])['noPayTax']);

            $problems = [];
            // U noPayTax (PDP, EU pořízení, osvobozená plnění) se daň
            // dodavateli neplatí — celkem je základ, daň je informativní.
            $expectedTotal = $noPayTax ? $base : round($base + $tax, 2);
            if (abs($expectedTotal - $total) > 0.02) {
                $problems[] = $noPayTax
                    ? "celkem {$total} ≠ základ {$base} (daň se neplatí dodavateli)"
                    : "základ {$base} + daň {$tax} ≠ celkem {$total}";
            }
            if (!($noPayTax && $tax === 0.0)) {
                $expectedTax = round($base * $pct / 100.0, 2);
                if (abs($tax - $expectedTax) > max(0.05, abs($base) * 0.001)) {
                    $problems[] = "daň {$tax} neodpovídá sazbě {$pct} % ze základu {$base}"
                        . " (očekáváno ~{$expectedTax})";
                }
            }
            if ($country !== null && $duzp !== '' && $code !== '') {
                try {
                    $codePct = $this->vatRateResolver()->resolveVatPct($country, $code, $duzp);
                    if (abs($codePct - $pct) > 0.001) {
                        $problems[] = "sazba {$pct} % nepatří ke kódu {$code} k datu {$duzp}"
                            . " (sazebník má {$codePct} %)";
                    }
                } catch (\LogicException) {
                    // Kód bez sazby k datu (oddaňovací kódy, historické
                    // kombinace) — sazbu z rekapitulace nemáme čím ověřit.
                }
            }
            if ($problems !== []) {
                $result->addWarning(
                    'recap',
                    "Rekapitulace DPH, řádek {$code} {$pct} %: " . implode('; ', $problems) . '.',
                    'vat_recap_inconsistent',
                );
            }
        }

        $this->checkRowsAgainstDeclaredRecap($data, $recap, $result);
    }

    /**
     * Σ řádkových cen per (kód, sazba) proti převzaté rekapitulaci — viz
     * `rows_recap_mismatch` v {@see validateDeclaredRecap}.
     *
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $recap
     */
    private function checkRowsAgainstDeclaredRecap(
        array $data,
        array $recap,
        ValidationResult $result,
    ): void {
        $rows = $this->resolveRowsForCompute($data);
        if ($rows === []) {
            return;
        }
        $fromTotal = (int) ($data['vat_mode'] ?? 1) === 2;

        $sums = [];
        foreach ($rows as $row) {
            if ((int) ($row['row_kind'] ?? 1) !== 1 || empty($row['vat_code'])) {
                continue;
            }
            $key = $this->vatGroupKey($row['vat_code'], $row['vat_pct'] ?? 0);
            $sums[$key] ??= ['sum' => 0.0, 'count' => 0];
            $sums[$key]['sum'] += DocRowCalculator::computePrice($row)['net_total'];
            $sums[$key]['count']++;
        }

        foreach ($recap as $r) {
            if (!is_array($r) || !empty($r['is_reverse_pair'])) {
                continue;
            }
            $key = $this->vatGroupKey($r['vat_code'] ?? '', $r['vat_pct'] ?? 0);
            $rowSum = round($sums[$key]['sum'] ?? 0.0, 2);
            $count  = $sums[$key]['count'] ?? 0;
            $expected = round((float) ($fromTotal ? ($r['total'] ?? 0) : ($r['base'] ?? 0)), 2);
            $limit = max(self::DECLARED_RECAP_TOLERANCE, 0.01 * $count);

            if (abs($rowSum - $expected) <= $limit) {
                continue;
            }
            $label = $fromTotal ? 'celkem' : 'základu';
            $result->addWarning(
                'rows',
                sprintf(
                    'Součet řádků %s %s %% (%s) neodpovídá %s v rekapitulaci (%s)'
                        . ' — řádky mohou být neúplné nebo špatně zadané.',
                    (string) ($r['vat_code'] ?? ''),
                    (string) (float) ($r['vat_pct'] ?? 0),
                    number_format($rowSum, 2, ',', ' '),
                    $label,
                    number_format($expected, 2, ',', ' '),
                ),
                'rows_recap_mismatch',
            );
        }
    }

    /**
     * Pokladna a směr podle typu dokladu (docs.core.docTypes):
     *
     * - typ se `series_binding: cash_desk` → `cash_desk` musí přijít z řady
     *   (řada bez pokladny = chyba formuláře `series_binding_missing`);
     * - nevázaný typ → `cash_desk` smí být vyplněná jen při platbě
     *   v hotovosti (`payment_method = 0`), jinak `cash_desk_requires_cash_payment`;
     * - typ s `trade_dir_column: cash_dir` → `cash_dir` je 1 nebo 2;
     *   ostatní typy musí mít 0.
     *
     * Neznámý typ (bez cfg) se nekontroluje — číselná řada už chybí výše.
     */
    protected function validateBindingAndDirection(array $data, ValidationResult $result): void
    {
        $docTypes = $this->config?->cfgItem('docs.core.docTypes');
        $docTypeKey = (string) ($data['doc_type'] ?? '');
        if (!is_array($docTypes) || !is_array($docTypes[$docTypeKey] ?? null)) {
            return;
        }
        $docType = $docTypes[$docTypeKey];

        if (($docType['series_binding'] ?? null) === 'cash_desk') {
            if (empty($data['cash_desk'])) {
                $result->addError(
                    ValidationError::FIELD_FORM,
                    'Číselná řada nemá přiřazenou pokladnu',
                    'series_binding_missing',
                );
            }
        } elseif (!empty($data['cash_desk']) && (int) ($data['payment_method'] ?? 1) !== 0) {
            $result->addError(
                'cash_desk',
                'Pokladnu lze zadat jen při platbě v hotovosti',
                'cash_desk_requires_cash_payment',
            );
        }

        $cashDir = (int) ($data['cash_dir'] ?? 0);
        if (($docType['trade_dir_column'] ?? null) === 'cash_dir') {
            if (CashDirection::tryFrom($cashDir)?->tradeDir() === null) {
                $result->addError(
                    'cash_dir',
                    'Směr pokladního dokladu musí být příjem nebo výdej',
                    'invalid_value',
                );
            }
        } elseif ($cashDir !== 0) {
            $result->addError(
                'cash_dir',
                'Směr pokladního dokladu se u tohoto typu dokladu nepoužívá',
                'invalid_value',
            );
        }
    }

    /**
     * Je hlavičkový partner povinný při potvrzení (stavy 40/80)?
     * Faktury ano; účetní doklad (cmnbkp) ne — partner žije per řádek
     * (zápočet má dva partnery, mzda závazek bez hlavičkového partnera).
     */
    protected function headPartnerRequired(): bool
    {
        return true;
    }

    /**
     * Záchytná síť pro pohyby řádků při přechodu do 40 (V pořádku): řádky
     * uložené před zavedením pohybů nebo importem nešly přes
     * DocRowsDocument::validate, proto se tady zvalidují všechny znovu.
     * Chyby s konvencí `rows.{index}.{column}`.
     */
    protected function validateRowOperations(array &$data, ValidationResult $result): void
    {
        $cfg = $this->config?->cfgItem('docs.core.rowOperations');
        if (!is_array($cfg)) {
            return;
        }

        // Idempotentní — validate() i beforeSave() denormalizují také; tady
        // kvůli přímým voláním z podtříd / testů.
        $this->denormalizeFromSeries($data);
        $docType = (string) ($data['doc_type'] ?? '');
        if ($docType === '') {
            return;
        }

        $cashDir = (int) ($data['cash_dir'] ?? 0);
        foreach ($this->resolveRowsForCompute($data) as $i => $row) {
            foreach (DocRowOperationRules::validateRow($row, $docType, $cfg, $cashDir) as $err) {
                $result->addError("rows.{$i}.{$err['column']}", $err['message'], $err['code']);
            }
        }
    }

    /**
     * Import mód (#55 D26) zrcadlí doklad z cizího systému, needituje ho —
     * zámek období (documentLockProviders) obchází. Tentýž marker čte
     * beforeSave; tady je v `$data` ještě přítomný, protože gateway se ptá
     * před ním. Přiřazení ukazatelů do zamčené instance handlerem
     * (DocsHeadsVatPeriodHandler) import mód nijak neomezuje.
     */
    public function isLockExempt(array $data): bool
    {
        return is_array($data['_importNumber'] ?? null);
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        // Import mode marker — virtual field, must be consumed + removed before
        // SQL. TableGateway::insertRow does not filter unknown columns, so a
        // leftover `_importNumber` would reach the INSERT and blow up with
        // "unknown column". Pull it out first, branch on it at the end.
        $importNumber = $data['_importNumber'] ?? null;
        unset($data['_importNumber']);
        // Reset per save — the instance may be reused across documents.
        $this->importMode = is_array($importNumber);

        // Dobový snapshot partnera — druhé virtuální pole importu; stejně
        // jako `_importNumber` nesmí dojít do SQL. Mimo import mód se
        // ignoruje (transform ho mimo import ani neposílá).
        $snapshotPayload = $data['_importPartnerSnapshot'] ?? null;
        unset($data['_importPartnerSnapshot']);
        $this->importPartnerSnapshot = $this->importMode && is_array($snapshotPayload)
            ? $snapshotPayload
            : null;

        $this->trackStateChange($data, $originalData);

        $this->denormalizeFromSeries($data);
        $this->resolvePartnerBalance($data);
        $this->applyDateDefaults($data);
        $this->applyHomeCurrency($data);
        $this->resolveAccountingPeriods($data);

        // Resolve rows for computation. Two scenarios:
        //   1. Client sent rows in payload (e.g. future mass-edit flow) — use them.
        //   2. Header-only save — rows are managed via sub-form, so they're
        //      not in the payload. Load current state from DB so totals and
        //      VAT recap reflect reality.
        // We compute on a local variable and never write rows back to $data:
        // TableGateway only syncs child sets that are present in $data, so
        // omitting them protects existing DB rows from being wiped.
        $vatMode = (int) ($data['vat_mode'] ?? 1);
        $resolved = $this->resolveVatCodesForDoc($data);
        $vatCodes = $resolved['codes'] ?? null;
        $rowsForCompute = $this->resolveRowsForCompute($data);
        foreach ($rowsForCompute as &$row) {
            $this->calculateRowPrice($row);
            $this->calculateRowVat($row, $vatMode, $vatCodes);
        }
        unset($row);

        $declared = $this->useDeclaredRecap($data, $originalData);
        $recap = $declared
            ? $this->takeOverVatRecapitulation($data, $resolved)
            : $this->buildVatRecapitulation($data, $rowsForCompute, $resolved);
        $this->recapDeclared = $declared && $recap !== [];
        $data['vatRecap'] = $recap;
        $tolerance = $this->recapDeclared ? self::DECLARED_RECAP_TOLERANCE : null;

        $this->sumTotals($data, $recap, $rowsForCompute);
        $this->applyTotalRounding($data);
        // Dorovnání řádků na rekapitulaci v měně dokladu (I5) — v domácí
        // měně ho dělá applyDomesticAmounts nad už dorovnanými cur hodnotami,
        // takže při kurzu 1 jsou obě měny shodné.
        $this->reconcileRowsToRecap($rowsForCompute, $recap, '', $tolerance);
        $this->applyDomesticAmounts($data, $rowsForCompute, $recap, $vatCodes, $tolerance);

        // Propagate computed values back into the payload child set so the
        // gateway's child sync writes them (covers new rows without id).
        // Only when the key already exists — adding it would trigger child
        // sync and wipe DB rows on header-only saves.
        if (array_key_exists('rows', $data) && is_array($data['rows'])) {
            $data['rows'] = $rowsForCompute;
        }
        $this->computedRows = $rowsForCompute;

        // Number assignment: import mode forces the document's own number;
        // otherwise normal state-transition assignment from the series counter.
        if (is_array($importNumber)) {
            $this->applyImportNumber($data, $importNumber);
        } else {
            $this->processStateTransition($data, $originalData);
        }

        $this->maintainSnapshots($data, $originalData);
        $this->applyPaymentReferenceDefault($data);
    }

    /**
     * Get the rows we should compute on. If the client provided rows in the
     * payload, use those. Otherwise read current state from the database.
     * Returns an empty array for new records (no id yet).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function resolveRowsForCompute(array $data): array
    {
        if (array_key_exists('rows', $data) && is_array($data['rows'])) {
            return $data['rows'];
        }
        if (empty($data['id']) || $this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT * FROM [docs_core_rows] WHERE [doc_head] = %i ORDER BY [order_pos]',
            (int) $data['id'],
        );
        return array_map(
            fn($r) => $r instanceof \Dibi\Row ? $r->toArray() : (array) $r,
            $rows,
        );
    }

    /**
     * Eviduje čas poslední změny `docState`. Vstup pro alert check
     * `docs.core.stale_in_repair` (doklady visící v 80 V opravě > 24 h).
     *
     * - Nové záznamy (`$originalData === null`): nastav na NOW, ať od prvního
     *   uložení existuje validní hodnota.
     * - Update s nezměněným `docState`: zachovej původní hodnotu — klient ji
     *   v payloadu nemá nastavovat (sloupec je `system: true`).
     * - Update se změněným `docState`: nastav na NOW.
     */
    protected function trackStateChange(array &$data, ?array $originalData): void
    {
        $this->stateTransition = null;

        if ($originalData === null) {
            $data['doc_state_changed_at'] = date('Y-m-d H:i:s');
            // Nový záznam vzniklý rovnou mimo Koncept (import) je taky
            // přechod — old = 0, ať se importované doklady ve 40 zaúčtují.
            $newState = (int) ($data['docState'] ?? 10);
            if ($newState !== 10) {
                $this->stateTransition = ['old' => 0, 'new' => $newState];
            }
            return;
        }

        $newState = (int) ($data['docState'] ?? $originalData['docState'] ?? 10);
        $oldState = (int) ($originalData['docState'] ?? 10);

        if ($newState !== $oldState) {
            $data['doc_state_changed_at'] = date('Y-m-d H:i:s');
            $this->stateTransition = ['old' => $oldState, 'new' => $newState];
            return;
        }

        // Same state — preserve original. Fallback to NOW if pre-backfill row
        // somehow still has NULL (defensive, should not happen post-upgrade).
        $data['doc_state_changed_at'] = $originalData['doc_state_changed_at']
            ?? date('Y-m-d H:i:s');
    }

    public function afterPersist(array $data): void
    {
        if ($this->db === null || empty($data['id'])) {
            return;
        }

        $this->ensureDocNumberPlaceholder($data);
        $this->persistRowComputedColumns();
    }

    private function ensureDocNumberPlaceholder(array $data): void
    {
        $current = $this->db?->fetch(
            'SELECT [doc_number] FROM [docs_core_heads] WHERE [id] = %i',
            (int) $data['id'],
        );
        if ($current === null) {
            return;
        }

        $docNumber = (string) ($current['doc_number'] ?? '');
        if ($docNumber !== '') {
            return;
        }

        $placeholder = '!' . str_pad((string) $data['id'], 10, '0', STR_PAD_LEFT);
        $this->executeSql(
            'UPDATE [docs_core_heads] SET [doc_number] = %s WHERE [id] = %i',
            $placeholder,
            (int) $data['id'],
        );
    }

    /**
     * Persist computed row columns from the last beforeSave run by direct
     * per-id UPDATEs — never through the child-sync payload (missing 'rows'
     * key must keep protecting DB rows from a wipe). Rows without id (new
     * rows in a full-sync payload) are written by the gateway's child sync
     * instead, because beforeSave propagates computed values into
     * $data['rows'].
     *
     * Public: DocRowsDocument::recomputeHeader calls it inside its own
     * transaction after re-running the compute pipeline.
     */
    public function persistRowComputedColumns(): void
    {
        if ($this->db === null) {
            return;
        }
        foreach ($this->computedRows as $row) {
            $rowId = (int) ($row['id'] ?? 0);
            if ($rowId === 0) {
                continue;
            }
            $this->executeSql(
                'UPDATE [docs_core_rows] SET %a WHERE [id] = %i',
                [
                    'vat_base'       => $row['vat_base']       ?? null,
                    'vat_amount'     => $row['vat_amount']     ?? null,
                    'vat_total'      => $row['vat_total']      ?? null,
                    'vat_base_dom'   => $row['vat_base_dom']   ?? null,
                    'vat_amount_dom' => $row['vat_amount_dom'] ?? null,
                    'vat_total_dom'  => $row['vat_total_dom']  ?? null,
                ],
                $rowId,
            );
        }
    }

    // ── Defaults ────────────────────────────────────────────────────────────

    /**
     * Denormalizace z číselné řady: `doc_type` vždy; pro typ se
     * `series_binding` i vázaná entita (BINDING_HEAD_COLUMNS) — hodnota
     * z řady přepíše payload bez ohledu na to, co poslal klient (pokladna
     * je u vázaných typů systémová jako doc_type). Pro nevázané typy se
     * `cash_desk` z payloadu nechává (uživatelský u hotově placené faktury).
     */
    /**
     * Osoba pro saldokonto (`partner_balance`, #72 D2) + terminál / brána
     * (`payment_terminal`): jediná autorita je PartnerBalanceResolver, volaný
     * z validate() i beforeSave() (idempotentní). V import módu se explicitně
     * poslaný ruční plátce respektuje — ve validate import poznáme podle
     * `_importNumber` v payloadu, v beforeSave podle `$importMode`.
     *
     * @return string zdroj hodnoty (PartnerBalanceResolver::SOURCE_*)
     */
    protected function resolvePartnerBalance(array &$data): string
    {
        $import = $this->importMode || $this->isLockExempt($data);
        return (new PartnerBalanceResolver($this->db, $this->config))->resolve($data, $import);
    }

    /**
     * Platba bránou (payment_method 5) na prodejním dokladu vyžaduje vybranou
     * bránu — bez ní by pohledávka neměla plátce. Ostatní kombinace bez chyby:
     * DS bez terminálů funguje jako dřív (plátce = partner).
     */
    protected function validatePaymentIntermediary(array $data, ValidationResult $result): void
    {
        if (PartnerBalanceResolver::isSalesDirection($data, $this->config)
            && (int) ($data['payment_method'] ?? 1) === PartnerBalanceResolver::METHOD_GATEWAY
            && empty($data['payment_terminal'])
        ) {
            $result->addError(
                'payment_terminal',
                'Platba bránou vyžaduje vybranou platební bránu — založ ji v číselníku Platební terminály a brány',
                'payment_terminal_required',
            );
        }
    }

    protected function denormalizeFromSeries(array &$data): void
    {
        if (empty($data['number_series']) || $this->db === null) {
            return;
        }
        $row = $this->db->fetch(
            'SELECT [doc_type], [cash_desk], [warehouse] FROM [docs_core_number_series] WHERE [id] = %i',
            (int) $data['number_series'],
        );
        if ($row === null) {
            return;
        }
        $series = $row->toArray();
        if (isset($series['doc_type'])) {
            $data['doc_type'] = (string) $series['doc_type'];
        }

        $docTypes = $this->config?->cfgItem('docs.core.docTypes');
        $binding = is_array($docTypes) ? ($docTypes[$data['doc_type']]['series_binding'] ?? null) : null;
        if (is_string($binding) && isset(self::BINDING_HEAD_COLUMNS[$binding])) {
            $value = $series[$binding] ?? null;
            $data[self::BINDING_HEAD_COLUMNS[$binding]] = $value !== null ? (int) $value : null;
        }
    }

    protected function applyDateDefaults(array &$data): void
    {
        // Nedaňový doklad (zálohová faktura, #79 D1) DUZP ani DPPD nemá —
        // nuluje se i hodnota z payloadu / importu. Rozhoduje atribut typu,
        // ne subclass: DocHeadRecomputer instancuje base a jede tudy taky.
        $taxDocument = DocTypes::isTaxDocument($this->config, (string) ($data['doc_type'] ?? ''));
        if (!$taxDocument) {
            $data['vat_duzp'] = null;
            $data['vat_dppd'] = null;
        }
        if (!empty($data['issue_date'])) {
            if (empty($data['accounting_date'])) {
                $data['accounting_date'] = $data['issue_date'];
            }
            if ($taxDocument && empty($data['vat_duzp'])) {
                $data['vat_duzp'] = $data['issue_date'];
            }
        }
        if ($taxDocument && !empty($data['vat_duzp']) && empty($data['vat_dppd'])) {
            $data['vat_dppd'] = $data['vat_duzp'];
        }
        if (!empty($data['issue_date']) && empty($data['due_date'])) {
            $days = $this->resolvePartnerPaymentTermDays($data['partner'] ?? null)
                ?? self::DEFAULT_PAYMENT_TERM_DAYS;
            $data['due_date'] = (new \DateTimeImmutable((string) $data['issue_date']))
                ->modify("+{$days} days")
                ->format('Y-m-d');
        }
    }

    protected function resolvePartnerPaymentTermDays(mixed $partnerId): ?int
    {
        if ($partnerId === null || $this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [payment_term_days] FROM [base_persons_persons] WHERE [id] = %i',
            (int) $partnerId,
        );
        if ($row === null) {
            return null;
        }
        $days = $row['payment_term_days'] ?? null;
        return $days !== null ? (int) $days : null;
    }

    protected function applyHomeCurrency(array &$data): void
    {
        if (empty($data['home_currency'])) {
            // Settings `economy.homeCurrency` (ds-setup.md §5.2); nerozhodnutý
            // klíč → 'czk', tedy dnešní chování. Store injektuje TableGateway.
            $value = $this->settings?->get('economy.homeCurrency');
            $data['home_currency'] = is_string($value) && $value !== '' ? $value : 'czk';
        }
        if (empty($data['doc_currency'])) {
            $data['doc_currency'] = $data['home_currency'];
        }
    }

    // ── Accounting period resolvers ─────────────────────────────────────────

    /**
     * Doklad otevíracího / uzávěrkového období (`fiscal_period_type`, #69
     * D20) jde do jednodenního měsíce Otevření / Uzavření roku účetního
     * data, ne do běžného měsíce; ostatní doklady do běžného měsíce.
     */
    protected function resolveAccountingPeriods(array &$data): void
    {
        if (!empty($data['accounting_date'])) {
            $data['fiscal_year']  = $this->resolveFiscalYearId((string) $data['accounting_date']);
            $periodType = FiscalMonthLookup::PERIOD_TYPE_BY_CODE[(string) ($data['fiscal_period_type'] ?? '')] ?? null;
            $data['fiscal_month'] = $periodType !== null && $data['fiscal_year'] !== null && $this->db !== null
                ? FiscalMonthLookup::monthIdForYearAndType($this->db, (int) $data['fiscal_year'], $periodType)
                : $this->resolveFiscalMonthId((string) $data['accounting_date']);
        }
        // Zařazení do instancí tvrzení DPH (vat_period/cs_period/rs_period) je
        // věc economy.vat — DocsHeadsVatPeriodHandler (beforeSave event).
    }

    protected function resolveFiscalYearId(string $accountingDate): ?int
    {
        if ($this->db === null || $accountingDate === '') {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id] FROM [economy_codebooks_fiscal_years]
             WHERE [date_begin] <= %d AND [date_end] >= %d
               AND [docState] != %i
             ORDER BY [date_begin] DESC
             LIMIT 1',
            $accountingDate, $accountingDate,
            self::DOC_STATE_DELETED,
        );
        return $row !== null ? (int) $row['id'] : null;
    }

    /**
     * Běžný měsíc (period_type 1) obsahující účetní datum — sdílený dotaz
     * FiscalMonthLookup, tentýž používá FiscalMonthLockProvider pro nový
     * měsíc dokladu před uložením (#55 D27).
     */
    protected function resolveFiscalMonthId(string $accountingDate): ?int
    {
        if ($this->db === null || $accountingDate === '') {
            return null;
        }
        return FiscalMonthLookup::monthIdForDate($this->db, $accountingDate);
    }

    // ── Row calculations ────────────────────────────────────────────────────

    /**
     * Cena řádku — tenká obálka nad DocRowCalculator::computePrice (sdílený
     * s živým přepočtem v DocRowsForm, #71). Záměrně zachovává dočasnou
     * mutaci `total_price` = cena PO slevě: na ní stojí calculateRowVat
     * (dostává $row), buildVatRecapitulation (fallback na total_price)
     * i sumTotals. Do DB z řádku jdou jen vat_* (persistRowComputedColumns),
     * takže zlevněná hodnota se neuloží. Kdo mutaci „opraví", rozbije součty
     * i rekapitulaci — hlídají to DocDocument*Test.
     */
    protected function calculateRowPrice(array &$row): void
    {
        if ((int) ($row['row_kind'] ?? 1) !== 1) {
            $row['total_price'] = null;
            return;
        }
        $price = DocRowCalculator::computePrice($row);
        $row['unit_price']  = $price['unit_price'];
        $row['total_price'] = $price['net_total'];
    }

    /**
     * DPH řádku — obálka nad DocRowCalculator::computeVat nad `total_price`
     * po slevě (viz calculateRowPrice).
     *
     * @param array<string, array<string, mixed>>|null $vatCodes VAT code
     *        definitions for the document's country (from resolveVatCodesForDoc).
     *        Null = country/config unresolved → compute without code semantics.
     */
    protected function calculateRowVat(array &$row, int $vatMode, ?array $vatCodes = null): void
    {
        $vat = DocRowCalculator::computeVat(
            (float) ($row['total_price'] ?? 0),
            $row,
            $vatMode,
            $vatCodes,
        );
        $row['vat_base']   = $vat['vat_base'];
        $row['vat_amount'] = $vat['vat_amount'];
        $row['vat_total']  = $vat['vat_total'];
    }

    // ── VAT recapitulation ──────────────────────────────────────────────────

    /**
     * Rekapitulace DPH per (kód, sazba) — autorita dokladu (viz
     * `docs/vat-calculation.md`). Metodu výpočtu vybírá `vat_calc_source`
     * dokladu: `0 z hlavičky` (norma § 37 odst. 1 ZDPH — daň se počítá
     * jednou ze součtu řádkových cen ve sazbě) nebo `1 z řádků` (historický
     * režim — součet řádkových, samostatně zaokrouhlených hodnot).
     *
     * @param array<int, array<string, mixed>> $rowsOverride Pre-computed rows; falls back to $data['rows'] when empty.
     * @return array<int, array<string, mixed>>
     */
    protected function buildVatRecapitulation(array &$data, array $rowsOverride = [], ?array $resolved = null): array
    {
        $rows = $rowsOverride !== []
            ? $rowsOverride
            : ($data['rows'] ?? []);
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $resolved ??= $this->resolveVatCodesForDoc($data);
        if ($resolved === null) {
            return [];
        }
        $vatCodes = $resolved['codes'];
        $vatMode = (int) ($data['vat_mode'] ?? 1);
        $calcSource = (int) ($data['vat_calc_source'] ?? 0);

        // 1. Group rows by (vat_code, vat_pct) — co se sčítá, určuje metoda
        $grouped = $calcSource === 1
            ? $this->groupRowVatForRecap($rows)
            : $this->groupRowPricesForRecap($rows);

        // 2. For each group build primary line + optional reverse charge pair
        $recap = [];
        $sortOrder = 0;
        $exchRate = (float) ($data['exchange_rate'] ?? 1.0);
        if ($exchRate <= 0) {
            $exchRate = 1.0;
        }
        $vatRoundingMode = (int) ($data['vat_rounding_mode'] ?? 0);

        foreach ($grouped as $entry) {
            $code = $entry['vat_code'];
            if (!isset($vatCodes[$code])) {
                // Řádek s neexistujícím DPH kódem je datová chyba, kterou musí
                // uživatel opravit — ne tichá ztráta skupiny ze součtů.
                throw new \DomainException("Neznámý DPH kód '{$code}' — opravte řádky dokladu.");
            }
            $codeDef = $vatCodes[$code];

            // Samovyměření (kód s reverseVatCode): primární řádek nese
            // spočtenou daň — je to nárok na odpočet (DPH přiznání ř. 43/44)
            // a předloha pro stranu MD v účetnictví. Do total se ale počítá
            // jen daň placená dodavateli, u noPayTax tedy zůstává jen základ.
            $selfAssessed = !empty($codeDef['reverseVatCode'])
                && isset($vatCodes[(string) $codeDef['reverseVatCode']]);

            $amounts = $calcSource === 1
                ? $this->recapAmountsFromRows($entry)
                : $this->recapAmountsFromHeader($entry, $vatMode, $codeDef, $selfAssessed, $vatRoundingMode);
            $base = $amounts['base'];
            $tax  = $amounts['tax'];
            $payableTax = empty($codeDef['noPayTax']) ? $tax : 0.0;

            $primary = [
                'vat_code'        => $code,
                'vat_pct'         => $entry['vat_pct'],
                'base'            => $base,
                'tax'             => $tax,
                'total'           => round($base + $payableTax, 2),
                'sum_base'        => (int) ($codeDef['sumBase']  ?? 1),
                'sum_tax'         => (int) ($codeDef['sumTax']   ?? 1),
                'sum_total'       => (int) ($codeDef['sumTotal'] ?? 1),
                'is_reverse_pair' => 0,
                'order_pos'       => $sortOrder++,
            ];
            $primary['base_dom']  = round($primary['base']  * $exchRate, 2);
            $primary['tax_dom']   = round($primary['tax']   * $exchRate, 2);
            $primary['total_dom'] = round($primary['total'] * $exchRate, 2);
            $recap[] = $primary;

            // Reverse charge — generate paired (oddanění) row. Samovyměření je
            // z definice ve stejné sazbě jako nárok na odpočet, takže pár dědí
            // vat_pct i daň primární skupiny — žádný rate resolver není třeba.
            if ($selfAssessed) {
                $reverseCodeKey = (string) $codeDef['reverseVatCode'];
                $reverseDef     = $vatCodes[$reverseCodeKey];

                $paired = [
                    'vat_code'        => $reverseCodeKey,
                    'vat_pct'         => $entry['vat_pct'],
                    'base'            => $base,
                    'tax'             => $tax,
                    'total'           => round($base + $tax, 2),
                    'sum_base'        => (int) ($reverseDef['sumBase']  ?? 1),
                    'sum_tax'         => (int) ($reverseDef['sumTax']   ?? 1),
                    'sum_total'       => (int) ($reverseDef['sumTotal'] ?? 1),
                    'is_reverse_pair' => 1,
                    'order_pos'       => $sortOrder++,
                ];
                $paired['base_dom']  = round($paired['base']  * $exchRate, 2);
                $paired['tax_dom']   = round($paired['tax']   * $exchRate, 2);
                $paired['total_dom'] = round($paired['total'] * $exchRate, 2);
                $recap[] = $paired;
            }
        }

        return $recap;
    }

    /**
     * Seskupení pro metodu `0 z hlavičky`: sčítá **ceny řádků** (`total_price`
     * po slevě — viz calculateRowPrice). Co cena znamená, řeší `vat_mode`
     * v recapAmountsFromHeader (mode 1 základ, mode 2 celkem s daní).
     *
     * Do rekapitulace patří položkový řádek s DPH kódem; `vat_pct` smí být
     * legitimně 0 (osvobozeno / 0% kódy), takže filtrovat přes
     * `empty($row['vat_pct'])` nelze — základ té skupiny by ze recapu
     * i ze součtů hlavičky zmizel.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array{vat_code: string, vat_pct: float, price: float, base: float, tax: float}>
     */
    private function groupRowPricesForRecap(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            if ((int) ($row['row_kind'] ?? 1) !== 1 || empty($row['vat_code'])) {
                continue;
            }
            $key = $this->vatGroupKey($row['vat_code'], $row['vat_pct'] ?? 0);
            $grouped[$key] ??= [
                'vat_code' => (string) $row['vat_code'],
                'vat_pct'  => (float) ($row['vat_pct'] ?? 0),
                'price'    => 0.0,
                'base'     => 0.0,
                'tax'      => 0.0,
            ];
            $grouped[$key]['price'] += (float) ($row['total_price'] ?? $row['vat_base'] ?? 0);
        }
        return $grouped;
    }

    /**
     * Seskupení pro historickou metodu `1 z řádků`: sčítá řádkové,
     * samostatně zaokrouhlené `vat_base` / `vat_amount` (calculateRowVat).
     * Sémantiku kódu (noPayTax, samovyměření) i režimu už nesou řádkové
     * hodnoty, takže recapAmountsFromRows je jen jejich součet.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array{vat_code: string, vat_pct: float, price: float, base: float, tax: float}>
     */
    private function groupRowVatForRecap(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            if ((int) ($row['row_kind'] ?? 1) !== 1 || empty($row['vat_code'])) {
                continue;
            }
            $key = $this->vatGroupKey($row['vat_code'], $row['vat_pct'] ?? 0);
            $grouped[$key] ??= [
                'vat_code' => (string) $row['vat_code'],
                'vat_pct'  => (float) ($row['vat_pct'] ?? 0),
                'price'    => 0.0,
                'base'     => 0.0,
                'tax'      => 0.0,
            ];
            $grouped[$key]['base'] += (float) ($row['vat_base'] ?? $row['total_price'] ?? 0);
            $grouped[$key]['tax']  += (float) ($row['vat_amount'] ?? 0);
        }
        return $grouped;
    }

    /**
     * Metoda `0 z hlavičky` (norma § 37 odst. 1 ZDPH): daň se počítá **jednou**
     * ze součtu cen skupiny, ne po řádcích. `vat_rounding_mode` se aplikuje na
     * dokladové úrovni — při `vat_mode` 1 na daň, při `vat_mode` 2 na základ
     * (daň je pak rozdíl, aby celková částka dokladu byla přesně Σ řádkových
     * cen).
     *
     * `noPayTax` (tuzemská PDP, EU pořízení, osvobozená plnění) zůstává zdola:
     * cena je základ i celkem, daň je informativní nárok na odpočet jen
     * u samovyměření — i v mode 2, kde by zpětný rozpočet byl chybný
     * (shodně s DocRowCalculator::computeVat).
     *
     * @param array{vat_pct: float, price: float} $entry
     * @param array<string, mixed> $codeDef
     * @return array{base: float, tax: float}
     */
    private function recapAmountsFromHeader(
        array $entry,
        int $vatMode,
        array $codeDef,
        bool $selfAssessed,
        int $vatRoundingMode,
    ): array {
        $pct = (float) $entry['vat_pct'];
        $sum = round((float) $entry['price'], 2);

        if (!empty($codeDef['noPayTax'])) {
            return [
                'base' => $sum,
                'tax'  => $selfAssessed
                    ? $this->applyRounding($sum * $pct / 100.0, $vatRoundingMode)
                    : 0.0,
            ];
        }

        if ($vatMode === 2) {
            $base = $this->applyRounding($sum / (1.0 + $pct / 100.0), $vatRoundingMode);
            return ['base' => $base, 'tax' => round($sum - $base, 2)];
        }

        return [
            'base' => $sum,
            'tax'  => $this->applyRounding($sum * $pct / 100.0, $vatRoundingMode),
        ];
    }

    /**
     * Metoda `1 z řádků` (historický režim — řádek = samostatná účtenka
     * v jednom pokladním lístku): rekapitulace je součet řádkových hodnot,
     * jak je spočetl calculateRowVat. Součet už respektuje režim i sémantiku
     * kódu, takže se tu nic nedopočítává; `total` skládá volající z base
     * a placené daně.
     *
     * @param array{base: float, tax: float} $entry
     * @return array{base: float, tax: float}
     */
    private function recapAmountsFromRows(array $entry): array
    {
        return [
            'base' => round((float) $entry['base'], 2),
            'tax'  => round((float) $entry['tax'], 2),
        ];
    }

    /**
     * Má se rekapitulace **převzít** místo přepočtu? (`vat_recap_source = 1`,
     * spec `docs/vat-calculation.md` § 5.)
     *
     * Rozhodnutí I3 — přechody zdroje řeší porovnání se stavem v DB:
     * - `přepočítaná → převzatá` = false: startovní převzatá vznikne kopií
     *   aktuální přepočítané (přegeneruje se z řádků a uloží se pod novým
     *   zdrojem), editace se odemkne až pro další uložení;
     * - `převzatá → přepočítaná` = false: recap se přegeneruje z řádků;
     * - uložení už převzatého dokladu = true, i když payload rekapitulaci
     *   nenese (uložení hlavičky, interní přepočet z DocRowsDocument).
     *
     * Bez `$originalData` (applier, interní přepočet) rozhoduje payload:
     * přišla-li rekapitulace, převezme se; u už uloženého dokladu (má `id`)
     * se převezme ta v DB. Nový doklad se zdrojem 1 a bez rekapitulace
     * spadne na přepočet — nemá co převzít.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $originalData
     */
    /**
     * Bude mít doklad po uložení neprázdnou rekapitulaci DPH? Totéž pravidlo,
     * podle kterého beforeSave rekapitulaci staví (`buildVatRecapitulation`
     * / `useDeclaredRecap`), jen bez výpočtu částek a bez DB: režim DPH
     * ≠ 0, registrace DPH a aspoň jeden položkový řádek (`row_kind` 1)
     * s kódem DPH, nebo převzatá rekapitulace v payloadu. Payload bez klíče
     * `rows` (header-only save, přechod stavu) řádky nemění — rozhoduje
     * uložený stav (`$storedRecap`), stejně jako `resolveRowsForCompute`
     * spadne na řádky z DB.
     *
     * Používá `VatPeriodLockProvider` (economy.vat, #55 D23) k rozhodnutí,
     * zda se doklad **po uložení** bude počítat do zamčené instance — proto
     * statická a čistá, aby se pravidlo od beforeSave nerozjelo.
     *
     * @param array<string, mixed> $data nový stav dokladu
     * @param bool $storedRecap má uložený doklad řádky v docs_core_vat_recap
     */
    public static function willHaveVatRecap(array $data, bool $storedRecap): bool
    {
        if ((int) ($data['vat_mode'] ?? 1) === 0 || empty($data['vat_registration'])) {
            return false;
        }
        $declared = (int) ($data['vat_recap_source'] ?? 0) === 1;
        if ($declared && isset($data['vatRecap']) && is_array($data['vatRecap']) && $data['vatRecap'] !== []) {
            return true;
        }
        if (!array_key_exists('rows', $data) || !is_array($data['rows'])) {
            return $storedRecap;
        }
        foreach ($data['rows'] as $row) {
            if (is_array($row) && (int) ($row['row_kind'] ?? 1) === 1 && !empty($row['vat_code'])) {
                return true;
            }
        }
        // Převzatá rekapitulace u už uloženého dokladu žije v DB, řádky
        // ji nenesou (useDeclaredRecap → takeOver z DB).
        return $declared && $storedRecap;
    }

    protected function useDeclaredRecap(array $data, ?array $originalData): bool
    {
        if ((int) ($data['vat_recap_source'] ?? 0) !== 1) {
            return false;
        }
        if ($originalData !== null) {
            return (int) ($originalData['vat_recap_source'] ?? 0) === 1;
        }
        if (isset($data['vatRecap']) && is_array($data['vatRecap']) && $data['vatRecap'] !== []) {
            return true;
        }
        return !empty($data['id']);
    }

    /**
     * Převzatá rekapitulace: vstup se **nepřepočítává**, jen normalizuje.
     * Zdroj je payload (formulář, applier), jinak stav v DB (uložení
     * hlavičky bez dotčené rekapitulace).
     *
     * Z definice kódu se vždy doplní flagy sčítání (`sum_*`) — autoritou je
     * definice, ne vstup; `total` se dopočte jen když ho vstup nenese
     * (u `noPayTax` bez placené daně = základ). `id` řádku se zachovává, aby
     * child sync `TableGateway` aktualizoval na místě a ručně editovaná
     * rekapitulace nepřišla o identitu. Domácí měnu dopočítá
     * `applyDomesticAmounts` jako u přepočítané (I2).
     *
     * Neznámý DPH kód je datová chyba k opravě — stejně jako u přepočítané
     * rekapitulace končí `DomainException` (gateway z něj dělá domain error).
     * Řádek bez kódu se zahodit nedá jinak než přeskočením: `vat_code` je
     * NOT NULL a bez kódu nejdou určit ani flagy sčítání.
     *
     * @param array<string, mixed> $data
     * @param array{country: string, codes: array<string, array<string, mixed>>}|null $resolved
     * @return array<int, array<string, mixed>>
     */
    protected function takeOverVatRecapitulation(array &$data, ?array $resolved = null): array
    {
        $incoming = isset($data['vatRecap']) && is_array($data['vatRecap']) && $data['vatRecap'] !== []
            ? $data['vatRecap']
            : $this->loadVatRecapFromDb($data['id'] ?? null);
        if ($incoming === []) {
            return [];
        }

        $resolved ??= $this->resolveVatCodesForDoc($data);
        $vatCodes = $resolved['codes'] ?? null;

        $exchRate = (float) ($data['exchange_rate'] ?? 1.0);
        if ($exchRate <= 0) {
            $exchRate = 1.0;
        }

        $recap = [];
        $sortOrder = 0;
        foreach ($incoming as $r) {
            if (!is_array($r)) {
                continue;
            }
            $code = trim((string) ($r['vat_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            if ($vatCodes !== null && !isset($vatCodes[$code])) {
                throw new \DomainException(
                    "Neznámý DPH kód '{$code}' v rekapitulaci — opravte rekapitulaci dokladu.",
                );
            }
            $codeDef = $vatCodes[$code] ?? [];

            $base = round((float) ($r['base'] ?? 0), 2);
            $tax  = round((float) ($r['tax'] ?? 0), 2);
            $hasTotal = array_key_exists('total', $r) && $r['total'] !== null && $r['total'] !== '';
            $total = $hasTotal
                ? round((float) $r['total'], 2)
                : round($base + (empty($codeDef['noPayTax']) ? $tax : 0.0), 2);

            $line = [
                'vat_code'        => $code,
                'vat_pct'         => (float) ($r['vat_pct'] ?? 0),
                'base'            => $base,
                'tax'             => $tax,
                'total'           => $total,
                'sum_base'        => (int) ($codeDef['sumBase']  ?? 1),
                'sum_tax'         => (int) ($codeDef['sumTax']   ?? 1),
                'sum_total'       => (int) ($codeDef['sumTotal'] ?? 1),
                'is_reverse_pair' => empty($r['is_reverse_pair']) ? 0 : 1,
                'order_pos'       => $sortOrder++,
            ];
            if (!empty($r['id'])) {
                $line['id'] = (int) $r['id'];
            }
            $line['base_dom']  = round($base  * $exchRate, 2);
            $line['tax_dom']   = round($tax   * $exchRate, 2);
            $line['total_dom'] = round($total * $exchRate, 2);

            $recap[] = $line;
        }

        return $recap;
    }

    /**
     * Rekapitulace uložená u dokladu — stejný fallback jako
     * resolveRowsForCompute pro řádky.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function loadVatRecapFromDb(mixed $headId): array
    {
        $headId = (int) $headId;
        if ($headId <= 0 || $this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT * FROM [docs_core_vat_recap] WHERE [doc_head] = %i ORDER BY [order_pos], [id]',
            $headId,
        );
        return array_map(
            fn($r) => $r instanceof \Dibi\Row ? $r->toArray() : (array) $r,
            $rows,
        );
    }

    protected function resolveCountryFromVatRegistration(mixed $vatRegId): ?string
    {
        if ($vatRegId === null || $this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [country] FROM [economy_codebooks_vat_registrations] WHERE [id] = %i',
            (int) $vatRegId,
        );
        return $row !== null ? (string) $row['country'] : null;
    }

    /**
     * Rozliší zemi + definice DPH kódů dokladu na jednom místě, aby řádkový
     * výpočet (`calculateRowVat`, noPayTax) i rekapitulace sdílely stejný
     * lookup. Vrací `null`, když zemi nelze dohledat z `vat_registration`
     * nebo chybí DPH konfigurace — volající to berou jako „počítej řádky bez
     * sémantiky kódů, rekapitulace je prázdná".
     *
     * @return array{country: string, codes: array<string, array<string, mixed>>}|null
     */
    private function resolveVatCodesForDoc(array $data): ?array
    {
        $countryCode = $this->resolveCountryFromVatRegistration($data['vat_registration'] ?? null);
        if ($countryCode === null) {
            return null;
        }
        try {
            $vatCodes = $this->vatRateResolver()->getVatCodes(
                $countryCode,
                direction: null,
                place: null,
                includeHidden: true,
            );
        } catch (\LogicException) {
            return null;
        }
        return ['country' => $countryCode, 'codes' => $vatCodes];
    }

    // ── Totals, rounding, exchange ──────────────────────────────────────────

    /**
     * Zda mají součty hlavičky zahrnout i řádky mimo DPH rekapitulaci (řádky
     * bez kódu, doklady z období neplátcovství). Faktury ano — jinak by
     * doklad bez DPH měl total_* = 0 a deník bez strany 321. Subclassy s
     * vlastní logikou součtů (cmnbkp sčítá z řádků) hook vypnou.
     */
    protected function headTotalsIncludeRowsOutsideRecap(): bool
    {
        // Převzatá rekapitulace je autorita celého dokladu — viz $recapDeclared.
        return !$this->recapDeclared;
    }

    /**
     * Součet řádkových hodnot těch item řádků, jejichž skupina
     * (`vatGroupKey`) není v rekapitulaci — tj. řádky bez `vat_code`, nebo
     * všechny řádky když je recap prázdný (neplátce / nedohledaná země).
     * Po Z3 mají bezkódové řádky vat_amount 0 a vat_total = vat_base, takže
     * fallback je konzistentní se součty z recapu.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $recap
     * @return array{base: float, vat: float, total: float, base_dom: float, vat_dom: float}
     */
    private function sumRowsOutsideRecap(array $rows, array $recap): array
    {
        $recapKeys = [];
        foreach ($recap as $r) {
            $recapKeys[$this->vatGroupKey($r['vat_code'] ?? '', $r['vat_pct'] ?? 0)] = true;
        }

        $sum = ['base' => 0.0, 'vat' => 0.0, 'total' => 0.0, 'base_dom' => 0.0, 'vat_dom' => 0.0];
        foreach ($rows as $row) {
            if ((int) ($row['row_kind'] ?? 1) !== 1) {
                continue;
            }
            if (isset($recapKeys[$this->vatGroupKey($row['vat_code'] ?? '', $row['vat_pct'] ?? 0)])) {
                continue;
            }
            $sum['base']     += (float) ($row['vat_base'] ?? 0);
            $sum['vat']      += (float) ($row['vat_amount'] ?? 0);
            $sum['total']    += (float) ($row['vat_total'] ?? 0);
            $sum['base_dom'] += (float) ($row['vat_base_dom'] ?? 0);
            $sum['vat_dom']  += (float) ($row['vat_amount_dom'] ?? 0);
        }
        return $sum;
    }

    /**
     * Součty hlavičky z rekapitulace DPH. Doplněné o řádky mimo rekapitulaci
     * (bezkódové / doklady bez DPH) přes sumRowsOutsideRecap, jinak by měly
     * total_* = 0. Subclassy bez DPH (cmnbkp) mají vlastní override.
     *
     * @param array<int, array<string, mixed>> $rows Řádky po compute pipeline.
     */
    protected function sumTotals(array &$data, array $recap, array $rows = []): void
    {
        $base = 0.0;
        $vat  = 0.0;
        $total = 0.0;

        foreach ($recap as $r) {
            if (!empty($r['sum_base']))  { $base  += (float) $r['base']; }
            if (!empty($r['sum_tax']))   { $vat   += (float) $r['tax']; }
            if (!empty($r['sum_total'])) { $total += (float) $r['total']; }
        }

        if ($this->headTotalsIncludeRowsOutsideRecap()) {
            $extra = $this->sumRowsOutsideRecap($rows, $recap);
            $base  += $extra['base'];
            $vat   += $extra['vat'];
            $total += $extra['total'];
        }

        $data['total_base']     = round($base, 2);
        $data['total_vat']      = round($vat, 2);
        $data['total_amount']   = round($total, 2);
        $data['total_rounding'] = 0.0;
    }

    protected function applyTotalRounding(array &$data): void
    {
        $original = (float) ($data['total_amount'] ?? 0);
        $mode = (int) ($data['total_rounding_mode'] ?? 0);
        $rounded = $this->applyRounding($original, $mode);
        $data['total_amount']   = $rounded;
        $data['total_rounding'] = round($rounded - $original, 2);
    }

    /** Sémantika kódů (krok, směr, chování u dobropisů): {@see RoundingModes}. */
    protected function applyRounding(float $amount, int $mode): float
    {
        return RoundingModes::apply($amount, $mode);
    }

    /**
     * Domácí měna pro řádky, rekapitulaci a hlavičku + dorovnání řádků
     * v domácí měně.
     *
     * Závazné jsou head totals: rekapitulace se nedopočítává (base_dom/tax_dom
     * = round(cur × rate) z buildVatRecapitulation), head se sčítá z ní a
     * řádky se dorovnávají na rekapitulaci. Výsledné invarianty (testované):
     *
     *   Σ rows.vat_base_dom   (per vat_code+pct) == recap.base_dom
     *   Σ rows.vat_amount_dom (per vat_code+pct) == recap.tax_dom
     *   Σ recap.base_dom (sum_base=1) == total_base_dom
     *   Σ recap.tax_dom  (sum_tax=1)  == total_vat_dom
     *   total_base_dom + total_vat_dom + total_rounding_dom == total_amount_dom
     *
     * total_rounding_dom je odvozený (amount − base − vat), ne kurzový —
     * absorbuje haléřový rozdíl, takže poslední invariant platí konstrukčně.
     * Dorovnání v měně dokladu dělá {@see reconcileRowsToRecap} už v
     * beforeSave (I5), takže při rate = 1 jsou diffy tady nulové a _dom je
     * kopie cur hodnot.
     *
     * @param array<int, array<string, mixed>> $rows Rows after calculateRowPrice/Vat
     * @param array<int, array<string, mixed>> $recap From buildVatRecapitulation
     * @param array<string, array<string, mixed>>|null $vatCodes Definice kódů
     *        země dokladu (z resolveVatCodesForDoc); null = dohledá si je sám.
     * @param float|null $tolerance Mez dorovnání — viz reconcileRowsToRecap.
     */
    protected function applyDomesticAmounts(
        array &$data,
        array &$rows,
        array $recap,
        ?array $vatCodes = null,
        ?float $tolerance = null,
    ): void {
        $exchRate = (float) ($data['exchange_rate'] ?? 1.0);
        if ($exchRate <= 0) {
            $exchRate = 1.0;
        }

        // 1. Per-row domestic amounts; text rows stay NULL
        foreach ($rows as &$row) {
            if ((int) ($row['row_kind'] ?? 1) !== 1) {
                $row['vat_base_dom']   = null;
                $row['vat_amount_dom'] = null;
                $row['vat_total_dom']  = null;
                continue;
            }
            $row['vat_base_dom']   = round((float) ($row['vat_base'] ?? 0)   * $exchRate, 2);
            $row['vat_amount_dom'] = round((float) ($row['vat_amount'] ?? 0) * $exchRate, 2);
        }
        unset($row);

        // 2. Head: base/vat as recap sums (same sum flags as sumTotals — NOT
        //    an independent rate conversion), amount by rate, rounding derived.
        //    Řádky mimo rekapitulaci (bezkódové) se přičtou z jejich _dom
        //    hodnot spočtených v kroku 1 — stejný fallback jako sumTotals.
        $baseDom = 0.0;
        $vatDom  = 0.0;
        foreach ($recap as $r) {
            if (!empty($r['sum_base'])) { $baseDom += (float) ($r['base_dom'] ?? 0); }
            if (!empty($r['sum_tax']))  { $vatDom  += (float) ($r['tax_dom']  ?? 0); }
        }
        if ($this->headTotalsIncludeRowsOutsideRecap()) {
            $extra = $this->sumRowsOutsideRecap($rows, $recap);
            $baseDom += $extra['base_dom'];
            $vatDom  += $extra['vat_dom'];
        }
        $data['total_base_dom']     = round($baseDom, 2);
        $data['total_vat_dom']      = round($vatDom, 2);
        $data['total_amount_dom']   = round((float) ($data['total_amount'] ?? 0) * $exchRate, 2);
        $data['total_rounding_dom'] = round(
            (float) $data['total_amount_dom']
            - (float) $data['total_base_dom']
            - (float) $data['total_vat_dom'],
            2,
        );

        // 3. Dorovnání řádků na rekapitulaci v domácí měně (v měně dokladu
        //    proběhlo už v beforeSave — I5).
        $this->reconcileRowsToRecap($rows, $recap, '_dom', $tolerance);

        // 4. Celkem na řádku z dorovnaných částí
        $this->finalizeRowVatTotals($rows, $data, $exchRate, $vatCodes);
    }

    /**
     * Dorovnání řádkových `vat_base` / `vat_amount` na rekapitulaci per
     * skupina (kód, sazba) — top-down, v jedné měně (spec `docs/vat-calculation.md`
     * § 7). Rekapitulace je autorita; řádkové hodnoty jsou z ní odvozené,
     * takže haléřový rozdíl dokladové metody (i kurzového přepočtu) absorbuje
     * **poslední řádek skupiny s nenulovou hodnotou v měně dokladu** — cena
     * řádku (`total_price`) se nikdy nemění, jen odvozený rozpad.
     *
     * Nenulovost se v obou průchodech testuje na cur hodnotách: řádek s nulou
     * v měně dokladu má nulu i v domácí, takže cíl dorovnání je pro obě měny
     * týž řádek a `vat_total` zůstává konzistentní.
     *
     * Oddaňovací páry reverse charge nemají řádky dokladu — přeskakují se.
     *
     * @param array<int, array<string, mixed>> $rows Řádky po compute pipeline
     * @param array<int, array<string, mixed>> $recap Rekapitulace dokladu
     * @param string $suffix `''` = měna dokladu, `'_dom'` = domácí měna
     * @param float|null $tolerance Mez dorovnání na skupinu; efektivní mez je
     *        `max($tolerance, 0,01 × počet řádků skupiny)`. `null` = bez meze
     *        (přepočítaná rekapitulace — rozdíl je konstrukčně haléřový).
     *        U převzaté rekapitulace se předává 0,02: větší rozdíl znamená
     *        chybějící nebo špatně zadané řádky, ty se nedorovnávají a uložení
     *        vydá `rows_recap_mismatch`.
     */
    protected function reconcileRowsToRecap(
        array &$rows,
        array $recap,
        string $suffix = '',
        ?float $tolerance = null,
    ): void {
        $rowBaseKey   = 'vat_base' . $suffix;
        $rowAmountKey = 'vat_amount' . $suffix;
        $recapBaseKey = 'base' . $suffix;
        $recapTaxKey  = 'tax' . $suffix;

        foreach ($recap as $r) {
            if (!empty($r['is_reverse_pair'])) {
                continue;
            }
            $key = $this->vatGroupKey($r['vat_code'] ?? '', $r['vat_pct'] ?? 0);

            $sumBase = 0.0;
            $sumAmount = 0.0;
            $count = 0;
            $lastIdx = null;
            $lastBaseIdx = null;
            $lastAmountIdx = null;
            foreach ($rows as $i => $row) {
                if ((int) ($row['row_kind'] ?? 1) !== 1
                    || empty($row['vat_code'])
                    || $this->vatGroupKey($row['vat_code'], $row['vat_pct'] ?? 0) !== $key
                ) {
                    continue;
                }
                $sumBase   += (float) ($row[$rowBaseKey] ?? 0);
                $sumAmount += (float) ($row[$rowAmountKey] ?? 0);
                $count++;
                $lastIdx = $i;
                if ((float) ($row['vat_base'] ?? 0) !== 0.0)   { $lastBaseIdx = $i; }
                if ((float) ($row['vat_amount'] ?? 0) !== 0.0) { $lastAmountIdx = $i; }
            }
            if ($lastIdx === null) {
                continue;
            }
            $limit = $tolerance === null ? null : max($tolerance, 0.01 * $count);

            $diffBase = round((float) ($r[$recapBaseKey] ?? 0) - $sumBase, 2);
            if ($diffBase !== 0.0 && ($limit === null || abs($diffBase) <= $limit)) {
                $t = $lastBaseIdx ?? $lastIdx;
                $rows[$t][$rowBaseKey] = round((float) ($rows[$t][$rowBaseKey] ?? 0) + $diffBase, 2);
            }
            $diffAmount = round((float) ($r[$recapTaxKey] ?? 0) - $sumAmount, 2);
            if ($diffAmount !== 0.0 && ($limit === null || abs($diffAmount) <= $limit)) {
                $t = $lastAmountIdx ?? $lastIdx;
                $rows[$t][$rowAmountKey] = round((float) ($rows[$t][$rowAmountKey] ?? 0) + $diffAmount, 2);
            }
        }
    }

    /**
     * `vat_total` / `vat_total_dom` řádku po dorovnání obou měn. Politiku
     * drží {@see DocRowCalculator::computeVat}: celkem řádku je součet částí
     * jen tam, kde daň je součástí placené ceny (mode 1 s běžným kódem).
     * U `noPayTax` (tuzemská PDP, EU pořízení, osvobozená plnění) a v mode 2
     * je autoritou cena řádku — informativní daň se do celkem nepřičítá,
     * domácí celkem je proto kurzový přepočet celkem v měně dokladu.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $data
     * @param array<string, array<string, mixed>>|null $vatCodes
     */
    private function finalizeRowVatTotals(
        array &$rows,
        array $data,
        float $exchRate,
        ?array $vatCodes,
    ): void {
        $vatMode = (int) ($data['vat_mode'] ?? 1);
        $vatCodes ??= $this->resolveVatCodesForDoc($data)['codes'] ?? null;

        foreach ($rows as &$row) {
            if ((int) ($row['row_kind'] ?? 1) !== 1) {
                continue;
            }
            $codeDef = $vatCodes[(string) ($row['vat_code'] ?? '')] ?? null;
            $noPayTax = $codeDef !== null && !empty($codeDef['noPayTax']);

            if ($vatMode !== 2 && !$noPayTax) {
                $row['vat_total'] = round(
                    (float) ($row['vat_base'] ?? 0) + (float) ($row['vat_amount'] ?? 0),
                    2,
                );
            }
            $row['vat_total_dom'] = $noPayTax
                ? round((float) ($row['vat_total'] ?? 0) * $exchRate, 2)
                : round(
                    (float) ($row['vat_base_dom'] ?? 0) + (float) ($row['vat_amount_dom'] ?? 0),
                    2,
                );
        }
        unset($row);
    }

    /**
     * Normalized group key matching buildVatRecapitulation's grouping —
     * pct via float cast so '21.00' (DB) and 21.0 (recap) compare equal.
     */
    private function vatGroupKey(mixed $vatCode, mixed $vatPct): string
    {
        return (string) $vatCode . '|' . (string) (float) $vatPct;
    }

    // ── Number assignment ───────────────────────────────────────────────────

    protected function processStateTransition(array &$data, ?array $originalData): void
    {
        // Jediné místo pravdy pro detekci přechodu je trackStateChange (běží
        // v beforeSave jako první, se správným fallbackem na originál).
        // Data-save bez změny stavu nesmí nikdy sáhnout na číslo dokladu.
        $t = $this->stateTransition;
        if ($t === null) {
            return;
        }
        // Číslo se přiděluje při každém opuštění Konceptu do 40 — vedle
        // UI přechodu 10→40 i přímý insert ve finálním stavu (old = 0,
        // exchange apply „Vystavit a uzavřít“). Import mode se sem nedostane
        // (beforeSave větví na applyImportNumber dřív).
        if (in_array($t['old'], [0, 10], true) && $t['new'] === 40) {
            $this->assignDocumentNumber($data);
            return;
        }
        // Návrat V opravě → Koncept uvolní číslo (jen poslední doklad v řadě).
        if ($t['old'] === 80 && $t['new'] === 10) {
            $this->releaseDocumentNumber($data, $originalData);
        }
    }

    /**
     * Import mode: store the document's own number + sequence verbatim and sync
     * the series counter to the highest used sequence. Replaces
     * assignDocumentNumber for migrated documents.
     *
     * Counter sync uses GREATEST so it is:
     *   - idempotent (re-importing the same doc never lowers the counter),
     *   - order-independent (importing 7, then 3, leaves counter at 7),
     *   - hole-tolerant (deleted source docs leave gaps; counter still ends at
     *     the true maximum so the next new doc continues correctly).
     *
     * The counter key (number_series, fiscal_year) is computed identically to
     * assignDocumentNumber — same reset_scope handling, same NULL-safe `<=>`
     * match — otherwise the sync would miss and the next new doc would not
     * continue from the imported sequence.
     *
     * Explicit sequenceNumber = null means the number sits outside the series
     * formula (migrated duplicate keys get a suffixed docNumber): stored with
     * sequence_number = NULL — UNIQUE treats NULLs as distinct, so any count
     * of them coexists in unq_series_seq — and the counter is NOT synced (an
     * out-of-formula number must never advance the series).
     *
     * @param array<string, mixed> $importNumber {docNumber: string, sequenceNumber: int|null}
     */
    protected function applyImportNumber(array &$data, array $importNumber): void
    {
        $docNumber = (string) ($importNumber['docNumber'] ?? '');

        if (array_key_exists('sequenceNumber', $importNumber)
            && $importNumber['sequenceNumber'] === null
            && $docNumber !== '') {
            $data['doc_number']      = $docNumber;
            $data['sequence_number'] = null;
            return;
        }

        $sequence = (int) ($importNumber['sequenceNumber'] ?? 0);

        if ($docNumber === '' || $sequence <= 0) {
            // Defensive: malformed import payload — fall back to normal
            // assignment rather than persisting an empty/placeholder number.
            $this->processStateTransition($data, null);
            return;
        }

        $data['doc_number']      = $docNumber;
        $data['sequence_number'] = $sequence;

        $seriesId = (int) ($data['number_series'] ?? 0);
        if ($seriesId === 0 || $this->db === null) {
            return;
        }

        // Mirror assignDocumentNumber's counter key: per (number_series,
        // fiscal_year) for reset_scope = 'fiscal_year', else fiscal_year = NULL.
        $resetScope = $this->numberSeriesResetScope($seriesId);
        $fyId = ($resetScope === 'fiscal_year')
            ? ($data['fiscal_year'] ?? null)   // already resolved by resolveAccountingPeriods()
            : null;

        // Two-step, NULL-safe (matches assignDocumentNumber): INSERT IGNORE the
        // counter row, then bump it via GREATEST. A single ON DUPLICATE KEY
        // UPDATE would not fire for fiscal_year = NULL rows (UNIQUE treats NULL
        // as distinct in MariaDB).
        $this->executeSql(
            'INSERT IGNORE INTO [docs_core_number_counters]
                ([number_series], [fiscal_year], [last_assigned])
             VALUES (%i, %iN, 0)',
            $seriesId, $fyId,
        );
        $this->executeSql(
            'UPDATE [docs_core_number_counters]
             SET [last_assigned] = GREATEST([last_assigned], %i)
             WHERE [number_series] = %i AND [fiscal_year] <=> %iN',
            $sequence, $seriesId, $fyId,
        );
    }

    /**
     * Read a number series' reset_scope (default 'fiscal_year'). Used by
     * applyImportNumber to compute the same counter key as
     * assignDocumentNumber.
     */
    protected function numberSeriesResetScope(int $seriesId): string
    {
        if ($this->db === null || $seriesId === 0) {
            return 'fiscal_year';
        }
        $row = $this->db->fetch(
            'SELECT [reset_scope] FROM [docs_core_number_series] WHERE [id] = %i',
            $seriesId,
        );
        return $row !== null ? (string) ($row['reset_scope'] ?? 'fiscal_year') : 'fiscal_year';
    }

    protected function assignDocumentNumber(array &$data): void
    {
        if ($this->db === null) {
            throw new \LogicException('No DB connection available');
        }
        $seriesId = (int) ($data['number_series'] ?? 0);
        if ($seriesId === 0) {
            throw new \LogicException('Cannot assign number — number_series missing');
        }

        $seriesRow = $this->db->fetch(
            'SELECT * FROM [docs_core_number_series] WHERE [id] = %i',
            $seriesId,
        );
        if ($seriesRow === null) {
            throw new \LogicException("Number series id={$seriesId} not found");
        }
        $series = $seriesRow->toArray();

        $resetScope = (string) ($series['reset_scope'] ?? 'fiscal_year');
        $fyId = ($resetScope === 'fiscal_year')
            ? $this->resolveFiscalYearId((string) ($data['accounting_date'] ?? ''))
            : null;

        // Uvnitř transakce Applieru (externalTransaction) se vlastní begin
        // nesmí otevřít — implicitně by ji commitnul (MariaDB, viz
        // Document::$externalTransaction). FOR UPDATE zámek counteru funguje
        // ve vnější transakci stejně, jen se drží do jejího commitu.
        $ownTx = !$this->externalTransaction;
        if ($ownTx) {
            $this->db->begin();
        }
        try {
            // Idempotent counter init
            $this->executeSql(
                'INSERT IGNORE INTO [docs_core_number_counters]
                 ([number_series], [fiscal_year], [last_assigned])
                 VALUES (%i, %iN, 0)',
                $seriesId, $fyId,
            );

            // Lock + read counter (NULL-safe equality for fiscal_year)
            $row = $this->db->fetch(
                'SELECT [last_assigned] FROM [docs_core_number_counters]
                 WHERE [number_series] = %i AND [fiscal_year] <=> %iN
                 FOR UPDATE',
                $seriesId, $fyId,
            );
            $current = (int) ($row['last_assigned'] ?? 0);
            $newSeq = $current + 1;

            $this->executeSql(
                'UPDATE [docs_core_number_counters]
                 SET [last_assigned] = %i
                 WHERE [number_series] = %i AND [fiscal_year] <=> %iN',
                $newSeq, $seriesId, $fyId,
            );

            $data['sequence_number'] = $newSeq;
            $data['fiscal_year']     = $fyId;
            $data['doc_number']      = $this->resolvePattern(
                (string) $series['doc_number_pattern'],
                $data,
                $series,
            );

            if ($ownTx) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    /**
     * Přechod →10 (Koncept) uvolňuje číslo dokladu a smí ho provést jen
     * poslední doklad v řadě (releaseDocumentNumber jinak padá
     * DomainException). Neposlednímu dokladu se proto přechod →10 v UI
     * vůbec nenabízí. Doklad bez čísla (defenzivně) nabídku nemění —
     * release je pro něj no-op.
     */
    public function filterStateTransitions(array $transitions, array $row): array
    {
        $offersDraft = array_filter(
            $transitions,
            fn(array $t): bool => (int) ($t['state'] ?? 0) === 10,
        ) !== [];
        if (!$offersDraft || $this->db === null) {
            return $transitions;
        }

        $seriesId = (int) ($row['number_series'] ?? 0);
        $sequence = (int) ($row['sequence_number'] ?? 0);
        if ($seriesId === 0 || $sequence === 0) {
            return $transitions;
        }

        // Stejný dotaz jako guard v releaseDocumentNumber.
        $maxRow = $this->db->fetch(
            'SELECT MAX([sequence_number]) AS [max_seq]
             FROM [docs_core_heads]
             WHERE [number_series] = %i AND [fiscal_year] <=> %iN',
            $seriesId, $row['fiscal_year'] ?? null,
        );
        if ((int) ($maxRow['max_seq'] ?? 0) === $sequence) {
            return $transitions;
        }

        return array_values(array_filter(
            $transitions,
            fn(array $t): bool => (int) ($t['state'] ?? 0) !== 10,
        ));
    }

    protected function releaseDocumentNumber(array &$data, ?array $originalData): void
    {
        if ($this->db === null || $originalData === null) {
            throw new \LogicException('Cannot release number without original data');
        }

        $seriesId = (int) ($originalData['number_series'] ?? 0);
        $fyId     = $originalData['fiscal_year'] ?? null;
        $sequence = (int) ($originalData['sequence_number'] ?? 0);

        if ($seriesId === 0 || $sequence === 0) {
            $data['sequence_number'] = null;
            $data['doc_number']      = '';
            return;
        }

        $maxRow = $this->db->fetch(
            'SELECT MAX([sequence_number]) AS [max_seq]
             FROM [docs_core_heads]
             WHERE [number_series] = %i AND [fiscal_year] <=> %iN',
            $seriesId, $fyId,
        );
        $maxSeq = (int) ($maxRow['max_seq'] ?? 0);

        if ($maxSeq !== $sequence) {
            throw new \DomainException(
                "Doklad #{$sequence} není poslední v řadě (poslední je #{$maxSeq}). "
                . "Vrácení do Konceptu by vytvořilo díru v sekvenci.",
            );
        }

        // Stejný kontrakt jako assignDocumentNumber: uvnitř externí transakce
        // žádný vlastní begin/commit.
        $ownTx = !$this->externalTransaction;
        if ($ownTx) {
            $this->db->begin();
        }
        try {
            $this->executeSql(
                'UPDATE [docs_core_number_counters]
                 SET [last_assigned] = [last_assigned] - 1
                 WHERE [number_series] = %i AND [fiscal_year] <=> %iN AND [last_assigned] = %i',
                $seriesId, $fyId, $sequence,
            );

            $data['sequence_number'] = null;
            $data['fiscal_year']     = null;
            $data['doc_number']      = !empty($data['id'])
                ? '!' . str_pad((string) $data['id'], 10, '0', STR_PAD_LEFT)
                : '';
            $data['supplier_snapshot'] = null;
            $data['customer_snapshot'] = null;

            if ($ownTx) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $series
     */
    protected function resolvePattern(string $pattern, array $data, array $series): string
    {
        $resolved = preg_replace_callback(
            '/%(D|C|y|Y|3|4|5|6)/',
            function (array $m) use ($data, $series): string {
                return match ($m[1]) {
                    'D' => $this->getDocIdCode((string) ($data['doc_type'] ?? '')),
                    'C' => (string) ($series['doc_number_code'] ?? ''),
                    'y' => substr($this->getFiscalYearLabel($data), -2),
                    'Y' => $this->getFiscalYearLabel($data),
                    '3' => str_pad((string) ($data['sequence_number'] ?? 0), 3, '0', STR_PAD_LEFT),
                    '4' => str_pad((string) ($data['sequence_number'] ?? 0), 4, '0', STR_PAD_LEFT),
                    '5' => str_pad((string) ($data['sequence_number'] ?? 0), 5, '0', STR_PAD_LEFT),
                    '6' => str_pad((string) ($data['sequence_number'] ?? 0), 6, '0', STR_PAD_LEFT),
                    default => $m[0],
                };
            },
            $pattern,
        );
        return $resolved ?? $pattern;
    }

    private function getDocIdCode(string $docType): string
    {
        if ($docType === '' || $this->config === null) {
            return '';
        }
        $cfg = $this->config->cfgItem('docs.core.docTypes');
        return is_array($cfg) && isset($cfg[$docType]['doc_id_code'])
            ? (string) $cfg[$docType]['doc_id_code']
            : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function getFiscalYearLabel(array $data): string
    {
        if (empty($data['fiscal_year']) || $this->db === null) {
            if (!empty($data['accounting_date'])) {
                return substr((string) $data['accounting_date'], 0, 4);
            }
            return date('Y');
        }
        $row = $this->db->fetch(
            'SELECT [doc_number_prefix], [name] FROM [economy_codebooks_fiscal_years] WHERE [id] = %i',
            (int) $data['fiscal_year'],
        );
        if ($row === null) {
            return date('Y');
        }
        $name = (string) ($row['name'] ?? '');
        if (preg_match('/^(\d{4})/', $name, $matches)) {
            return $matches[1];
        }
        return date('Y');
    }

    // ── Snapshots ───────────────────────────────────────────────────────────

    protected function maintainSnapshots(array &$data, ?array $originalData): void
    {
        // Chybějící docState v payloadu = stav se nemění (gateway ho injektuje,
        // fallback na originál kryje volání mimo gateway — recomputeHeader).
        $newState = (int) ($data['docState'] ?? $originalData['docState'] ?? 10);
        if (!in_array($newState, self::SNAPSHOT_STATES, true)) {
            return;
        }

        // Import mód: nestavět z dnešního adresáře — dobová data by přepsal
        // dnešními. Partnerská strana přijde dobová v payloadu (je-li),
        // vlastní strana se staví standardně.
        if ($this->importMode) {
            $this->buildImportSnapshots($data);
            return;
        }

        $partnerChanged = ($data['partner'] ?? null) !== ($originalData['partner'] ?? null);
        $needsBuild = empty($data['supplier_snapshot'])
                    || empty($data['customer_snapshot'])
                    || $partnerChanged;

        if (!$needsBuild) {
            return;
        }

        $this->buildSnapshots($data);
    }

    protected function buildSnapshots(array &$data): void
    {
        $tradeDir = self::resolveTradeDir($data, $this->config);
        if ($tradeDir === null) {
            return;
        }

        $partnerSnap = $this->buildPersonSnapshot(
            personId:  (int) ($data['partner'] ?? 0),
            addressId: $data['partner_address'] ?? null,
            bankAccountId: null,
            vatRegistrationId: null,
        );
        $ownSnap = $this->buildOwnSnapshot($data);

        $this->assignSnapshots($data, $tradeDir, $partnerSnap, $ownSnap);
    }

    /**
     * Import mód: partnerská strana = dobový snapshot z payloadu (je-li;
     * jinak sloupec zůstává NULL — kanonické zdroje bez stran), vlastní
     * strana standardně z dnešního adresáře (vlastní firma se nemění, DIČ
     * nese vat_registration z hlavičky dokladu).
     */
    protected function buildImportSnapshots(array &$data): void
    {
        $tradeDir = self::resolveTradeDir($data, $this->config);
        if ($tradeDir === null) {
            return;
        }
        $this->assignSnapshots(
            $data,
            $tradeDir,
            $this->importPartnerSnapshot ?? [],
            $this->buildOwnSnapshot($data),
        );
    }

    /**
     * Směr obchodu dokladu — jediná autorita pro snapshoty, DocRowsForm
     * (směr DPH kódů), DocsHeadsViewer (strany detailu) i DocumentApplier.
     *
     *   1 = výstup (my dodavatel, partner odběratel), 2 = vstup (my odběratel).
     *   null = typ bez stran (cmnbkp) nebo neznámý typ — snapshoty se nestaví.
     *
     * Typ s pevným `trade_dir` 1/2 ho vrací; typ s `trade_dir: 0` a
     * `trade_dir_column` čte směr per doklad ze sloupce hlavičky (zatím jen
     * `cash_dir`: příjem → 1, výdej → 2, nepoužito → null).
     *
     * @param array<string, mixed> $data hlavička (potřebuje `doc_type` + případný `trade_dir_column`)
     */
    public static function resolveTradeDir(array $data, ?ConfigRuntime $config): ?int
    {
        $docTypes = $config?->cfgItem('docs.core.docTypes');
        $docTypeKey = (string) ($data['doc_type'] ?? '');
        if (!is_array($docTypes) || !is_array($docTypes[$docTypeKey] ?? null)) {
            return null;
        }
        $docType = $docTypes[$docTypeKey];

        $tradeDir = (int) ($docType['trade_dir'] ?? 0);
        if ($tradeDir === 1 || $tradeDir === 2) {
            return $tradeDir;
        }

        $column = $docType['trade_dir_column'] ?? null;
        if ($tradeDir === 0 && $column === 'cash_dir') {
            return CashDirection::tryFrom((int) ($data['cash_dir'] ?? 0))?->tradeDir();
        }

        return null;
    }

    /**
     * Snapshot vlastní firmy: HQ adresa + bank_account/vat_registration
     * z hlavičky dokladu.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function buildOwnSnapshot(array $data): array
    {
        $own = $this->ownCompanyResolver();
        $ownPersonId = $own->getOwnPersonId();
        if ($ownPersonId === null) {
            throw new \DomainException(
                'Není nastavena vlastní firma (base_persons_persons.is_own = 1).',
            );
        }
        $ownHqAddress = $own->getOwnHeadquartersAddress();

        return $this->buildPersonSnapshot(
            personId:  $ownPersonId,
            addressId: $ownHqAddress !== null ? (int) $ownHqAddress['id'] : null,
            bankAccountId: $data['bank_account'] ?? null,
            vatRegistrationId: $data['vat_registration'] ?? null,
        );
    }

    /**
     * Zapíše snapshoty do sloupců dle `trade_dir`.
     *
     * Snapshots must hit the database as JSON strings, not PHP arrays.
     * The columns are typed `json` in JSONC (= LONGTEXT in MariaDB), and
     * dibi has no automatic array→JSON serialization for that type — if
     * we pass a 2-D array, dibi treats it as a multi-row insert payload
     * and produces broken SQL. DocsHeadsForm::decodeSnapshot reverses
     * this on read.
     *
     * @param array<string, mixed> $partnerSnap
     * @param array<string, mixed> $ownSnap
     */
    private function assignSnapshots(array &$data, int $tradeDir, array $partnerSnap, array $ownSnap): void
    {
        if ($tradeDir === 1) {
            // Output (issued invoice) — we are supplier
            $data['supplier_snapshot'] = $this->encodeSnapshot($ownSnap);
            $data['customer_snapshot'] = $this->encodeSnapshot($partnerSnap);
        } else {
            // Input (received invoice) — we are customer
            $data['supplier_snapshot'] = $this->encodeSnapshot($partnerSnap);
            $data['customer_snapshot'] = $this->encodeSnapshot($ownSnap);
        }
    }

    /**
     * Encode a snapshot array as JSON string for storage in a `json` column.
     * Returns null for empty snapshots so the column ends up NULL rather than
     * an empty JSON object string.
     *
     * @param array<string, mixed> $snap
     */
    private function encodeSnapshot(array $snap): ?string
    {
        if ($snap === []) {
            return null;
        }
        $json = json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }

    /**
     * Thin delegation to the shared PersonSnapshotBuilder — kept as a method
     * so existing subclasses and tests overriding/calling it keep working.
     *
     * @return array<string, mixed>
     */
    protected function buildPersonSnapshot(
        int $personId,
        mixed $addressId,
        mixed $bankAccountId,
        mixed $vatRegistrationId,
    ): array {
        if ($this->db === null || $personId === 0) {
            return [];
        }
        return $this->personSnapshotBuilder()->build(
            $personId,
            $addressId,
            $bankAccountId,
            $vatRegistrationId,
        );
    }

    // ── Other defaults ──────────────────────────────────────────────────────

    protected function applyPaymentReferenceDefault(array &$data): void
    {
        if (!empty($data['payment_reference'])) {
            return;
        }
        if (!empty($data['sequence_number'])) {
            $data['payment_reference'] = (string) $data['sequence_number'];
        }
    }

    /**
     * Thin wrapper around Dibi\Connection::query() to make it overridable in
     * tests (Connection::query() is `final` so PHPUnit cannot mock it).
     */
    protected function executeSql(mixed ...$args): void
    {
        $this->db?->query(...$args);
    }

    // ── Lazy service factories ──────────────────────────────────────────────

    protected function vatRateResolver(): VatRateResolver
    {
        if ($this->vatRateResolver === null) {
            if ($this->config === null) {
                throw new \LogicException('VatRateResolver requires ConfigRuntime injection');
            }
            $this->vatRateResolver = new VatRateResolver($this->config);
        }
        return $this->vatRateResolver;
    }

    protected function ownCompanyResolver(): OwnCompanyResolver
    {
        if ($this->ownCompanyResolver === null) {
            if ($this->db === null) {
                throw new \LogicException('OwnCompanyResolver requires Dibi connection');
            }
            $this->ownCompanyResolver = new OwnCompanyResolver($this->db);
        }
        return $this->ownCompanyResolver;
    }

    protected function personSnapshotBuilder(): PersonSnapshotBuilder
    {
        if ($this->personSnapshotBuilder === null) {
            if ($this->db === null) {
                throw new \LogicException('PersonSnapshotBuilder requires Dibi connection');
            }
            $this->personSnapshotBuilder = new PersonSnapshotBuilder($this->db);
        }
        return $this->personSnapshotBuilder;
    }
}
