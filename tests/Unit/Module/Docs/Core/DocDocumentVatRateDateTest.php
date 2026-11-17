<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

/**
 * Datum sazby DPH ve výpočtu a kontrolách dokladu (#159 D1, D5, D6, D8, D9,
 * D15, U3–U8, U11): procento řádku a řádku rekapitulace se odvozuje z kódu
 * a data sazby, vstupní hodnota se přepíše. Pravidla data testuje
 * `VatRateDatesTest`; tady jde o jejich zapojení do `DocDocument`.
 *
 * Číselník je skutečný `vat-cz.jsonc` — Základní 20 % do 2012 a 21 % od
 * 2013, Druhá snížená (`cz-302`) 10 % jen 2015–2023.
 */
class DocDocumentVatRateDateTest extends TestCase
{
    /** @var array<int, array<string, mixed>> Řádky, které vrací mock DB. */
    private array $dbRows = [];

    /** @var array<int, array<string, mixed>> Rekapitulace, kterou vrací mock DB. */
    private array $dbRecap = [];

    private function doc(bool $withConfig = true): TestableDocsHeadsDocument
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['country' => 'cz', 'id' => 1]));
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql) {
                $source = str_contains($sql, 'docs_core_vat_recap') ? $this->dbRecap : $this->dbRows;
                return array_map(static fn(array $r) => new Row($r), $source);
            },
        );

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        if ($withConfig) {
            $doc->setConfig(ConfigRuntimeFactory::fromItems([
                'world.vat.cz' => JsoncParser::parseFile(
                    dirname(__DIR__, 5) . '/modules/world/vat/config/vat-cz.jsonc',
                ),
            ]));
        }
        return $doc;
    }

    /** @param array<string, mixed> $extra */
    private function row(string $code, float $pct, float $price, array $extra = []): array
    {
        return $extra + [
            'row_kind'        => 1,
            'quantity'        => 1,
            'unit_price'      => $price,
            'price_calc_mode' => 0,
            'vat_code'        => $code,
            'vat_pct'         => $pct,
        ];
    }

    /** @param array<string, mixed> $extra */
    private function head(array $extra = []): array
    {
        return $extra + [
            'vat_mode'            => 1,
            'vat_registration'    => 1,
            'issue_date'          => '2013-01-07',
            'vat_duzp'            => '2013-01-04',
            'vat_dppd'            => '2013-01-04',
            'exchange_rate'       => 1.0,
            'total_rounding_mode' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function save(TestableDocsHeadsDocument $doc, array &$data, ?array $original = null): array
    {
        $doc->beforeSavePub($data, $original);
        return [$doc->getComputedRows(), $data['vatRecap']];
    }

    /** @return list<array<string, mixed>> */
    private function findings(TestableDocsHeadsDocument $doc, array $data, string $code): array
    {
        $result = $doc->validate($data);
        $out = [];
        foreach ($result->getErrors() as $error) {
            $out[] = $error->toArray() + ['severity' => 'error'];
        }
        foreach ($result->getWarnings() as $warning) {
            $out[] = $warning->toArray() + ['severity' => 'warning'];
        }
        return array_values(array_filter($out, static fn (array $f): bool => $f['code'] === $code));
    }

    // ── D1, D3: automatika ───────────────────────────────────────────────

    public function testReceivedInvoiceAcrossRateChangeUsesObligationDate(): void
    {
        $doc = $this->doc();
        // DUZP 4. 1. 2013 (období odpočtu), DPPD 31. 12. 2012 (plnění dodavatele).
        $data = $this->head([
            'vat_dppd' => '2012-12-31',
            'rows'     => [$this->row('cz-110', 21.0, 1000.0)],
        ]);

        [$rows, $recap] = $this->save($doc, $data);

        $this->assertSame(20.0, (float) $rows[0]['vat_pct'], 'procento ze vstupu se přepíše sazbou k DPPD');
        $this->assertSame(200.0, (float) $rows[0]['vat_amount']);
        $this->assertCount(1, $recap);
        $this->assertSame(20.0, (float) $recap[0]['vat_pct']);
        $this->assertSame(200.0, (float) $recap[0]['tax']);
        $this->assertSame(20.0, (float) $data['rows'][0]['vat_pct'], 'do payloadu pro child sync');
        $this->assertNull($rows[0]['vat_rate_date'], 'zdroj 0 datum řádku nedrží');
        $this->assertNull($recap[0]['vat_rate_date']);
    }

    // ── D4: vlastní datum dokladu ────────────────────────────────────────

    public function testDocumentSourceUsesOwnDateAndFallsBackToAutomatic(): void
    {
        $doc = $this->doc();
        $data = $this->head([
            'vat_rate_source' => 1,
            'vat_rate_date'   => '2012-12-31',
            'rows'            => [$this->row('cz-110', 21.0, 1000.0)],
        ]);
        [$rows, $recap] = $this->save($doc, $data);
        $this->assertSame(20.0, (float) $rows[0]['vat_pct']);
        $this->assertSame(20.0, (float) $recap[0]['vat_pct']);
        $this->assertSame('2012-12-31', $data['vat_rate_date'], 'vlastní datum dokladu zůstává');

        $data = $this->head([
            'vat_rate_source' => 1,
            'vat_rate_date'   => null,
            'rows'            => [$this->row('cz-110', 20.0, 1000.0)],
        ]);
        [$rows] = $this->save($doc, $data);
        $this->assertSame(21.0, (float) $rows[0]['vat_pct'], 'bez data platí do potvrzení automatika');
    }

    // ── D5, U5: vlastní datum na řádcích ─────────────────────────────────

    public function testFinalInvoiceWithAdvanceDeductionInOldRate(): void
    {
        $doc = $this->doc();
        $data = $this->head([
            'issue_date' => '2014-03-12', 'vat_duzp' => '2014-03-10', 'vat_dppd' => '2014-03-10',
            'vat_rate_source' => 2,
            'rows' => [
                $this->row('cz-120', 21.0, 5000.0, ['vat_rate_date' => null]),
                $this->row('cz-120', 21.0, 3000.0, ['vat_rate_date' => '']),
                // Odpočet zálohy zdaněné v roce 2012.
                $this->row('cz-120', 21.0, -2000.0, ['vat_rate_date' => '2012-11-20']),
            ],
        ]);

        [$rows, $recap] = $this->save($doc, $data);

        $this->assertSame([21.0, 21.0, 20.0], array_map(static fn ($r) => (float) $r['vat_pct'], $rows));
        $this->assertSame([null, null, '2012-11-20'], array_column($rows, 'vat_rate_date'));
        $this->assertSame(-400.0, (float) $rows[2]['vat_amount']);

        $this->assertCount(2, $recap, 'dvě skupiny téhož kódu');
        $this->assertSame(['cz-120', 'cz-120'], array_column($recap, 'vat_code'));
        $this->assertSame([21.0, 20.0], array_map(static fn ($r) => (float) $r['vat_pct'], $recap));
        $this->assertSame(8000.0, (float) $recap[0]['base']);
        $this->assertSame(-2000.0, (float) $recap[1]['base']);
        $this->assertNull($recap[0]['vat_rate_date'], 'skupina běžných řádků bez data');
        $this->assertSame('2012-11-20', $recap[1]['vat_rate_date'], 'datum prvního řádku skupiny');
        $this->assertNull($data['vat_rate_date'], 'zdroj 2 vlastní datum dokladu nedrží');
    }

    public function testRowDateResolvesCodeWithoutCurrentRate(): void
    {
        $doc = $this->doc();
        $head = [
            'issue_date' => '2024-02-05', 'vat_duzp' => '2024-02-01', 'vat_dppd' => '2024-02-01',
            'vat_rate_source' => 2,
        ];
        $data = $this->head($head + [
            'rows' => [$this->row('cz-302', 0.0, -1000.0, ['vat_rate_date' => '2023-11-30'])],
        ]);
        [$rows] = $this->save($doc, $data);
        $this->assertSame(10.0, (float) $rows[0]['vat_pct']);
        $this->assertSame([], $this->findings($doc, $data + ['docState' => 40], 'vat_rate_missing'));
    }

    // ── D9, D15, U4: kód bez sazby k datu ────────────────────────────────

    public function testCodeWithoutRateIsWarningInDraftAndErrorOnConfirmation(): void
    {
        $doc = $this->doc();
        $data = $this->head([
            'issue_date' => '2024-02-05', 'vat_duzp' => '2024-02-01', 'vat_dppd' => '2024-02-01',
            'rows' => [$this->row('cz-110', 21.0, 100.0), $this->row('cz-302', 10.0, 1000.0)],
        ]);

        $draft = $this->findings($doc, $data + ['docState' => 10], 'vat_rate_missing');
        $this->assertCount(1, $draft);
        $this->assertSame('warning', $draft[0]['severity']);
        $this->assertSame('rows.1.vat_code', $draft[0]['column']);

        $repair = $this->findings($doc, $data + ['docState' => 80], 'vat_rate_missing');
        $this->assertSame('warning', $repair[0]['severity'], 'V opravě jako koncept');

        $confirmed = $this->findings($doc, $data + ['docState' => 40], 'vat_rate_missing');
        $this->assertCount(1, $confirmed);
        $this->assertSame('error', $confirmed[0]['severity']);

        // Uložení konceptu procento řádku nechává, jak je (není z čeho odvodit).
        [$rows] = $this->save($doc, $data);
        $this->assertSame(10.0, (float) $rows[1]['vat_pct']);
        $this->assertSame(21.0, (float) $rows[0]['vat_pct']);
    }

    public function testCodeWithoutRateIsErrorInImportMode(): void
    {
        $doc = $this->doc();
        $data = $this->head([
            'docState' => 10,
            'issue_date' => '2024-02-05', 'vat_duzp' => '2024-02-01', 'vat_dppd' => '2024-02-01',
            '_importNumber' => ['number' => 'FP-1'],
            'rows' => [$this->row('cz-302', 10.0, 1000.0)],
        ]);

        $findings = $this->findings($doc, $data, 'vat_rate_missing');
        $this->assertCount(1, $findings);
        $this->assertSame('error', $findings[0]['severity']);
    }

    public function testDocumentSourceWithoutDateIsErrorOnlyOnConfirmation(): void
    {
        $doc = $this->doc();
        $data = $this->head([
            'vat_rate_source' => 1, 'vat_rate_date' => null,
            'rows' => [$this->row('cz-110', 21.0, 100.0)],
        ]);

        $this->assertSame([], $this->findings($doc, $data + ['docState' => 10], 'vat_rate_date_required'));

        $confirmed = $this->findings($doc, $data + ['docState' => 40], 'vat_rate_date_required');
        $this->assertCount(1, $confirmed);
        $this->assertSame('error', $confirmed[0]['severity']);
        $this->assertSame('vat_rate_date', $confirmed[0]['column']);
    }

    // ── U11: pozdější vlastní datum ──────────────────────────────────────

    public function testLaterOwnDateIsWarningEvenOnConfirmation(): void
    {
        $doc = $this->doc();
        $data = $this->head([
            'docState' => 40,
            'vat_rate_source' => 1, 'vat_rate_date' => '2013-03-01',
            'rows' => [$this->row('cz-110', 21.0, 100.0)],
        ]);
        $head = $this->findings($doc, $data, 'vat_rate_date_late');
        $this->assertCount(1, $head);
        $this->assertSame('warning', $head[0]['severity']);
        $this->assertSame('vat_rate_date', $head[0]['column']);

        $data = $this->head([
            'docState' => 40,
            'vat_rate_source' => 2,
            'rows' => [
                $this->row('cz-110', 21.0, 100.0, ['vat_rate_date' => '2012-12-31']),
                $this->row('cz-110', 21.0, 100.0, ['vat_rate_date' => '2013-02-01']),
            ],
        ]);
        $rows = $this->findings($doc, $data, 'vat_rate_date_late');
        $this->assertCount(1, $rows, 'dřívější datum je v pořádku');
        $this->assertSame('rows.1.vat_rate_date', $rows[0]['column']);
        $this->assertSame('warning', $rows[0]['severity']);
    }

    // ── D6: přepnutí zdroje ──────────────────────────────────────────────

    public function testSwitchFromRowsToAutomaticClearsRowDatesInDb(): void
    {
        $doc = $this->doc();
        // Uložení hlavičky bez řádků v payloadu — řádky jsou v DB.
        $this->dbRows = [
            $this->row('cz-120', 21.0, 5000.0, ['id' => 11, 'vat_rate_date' => null]),
            $this->row('cz-120', 20.0, -2000.0, ['id' => 12, 'vat_rate_date' => '2012-11-20']),
        ];
        $data = $this->head([
            'id' => 42,
            'issue_date' => '2014-03-12', 'vat_duzp' => '2014-03-10', 'vat_dppd' => '2014-03-10',
            'vat_rate_source' => 0,
        ]);

        [$rows, $recap] = $this->save($doc, $data, ['vat_rate_source' => 2]);

        $this->assertSame([21.0, 21.0], array_map(static fn ($r) => (float) $r['vat_pct'], $rows));
        $this->assertSame([null, null], array_column($rows, 'vat_rate_date'));
        $this->assertCount(1, $recap, 'jedna skupina — procento automatiky');
        $this->assertArrayNotHasKey('rows', $data, 'child sync řádků se nespouští');

        $doc->persistRowComputedColumns();
        $second = $doc->executedSql[1]['args'][0];
        $this->assertSame(21.0, (float) $second['vat_pct'], 'procento se propíše do řádku v DB');
        $this->assertArrayHasKey('vat_rate_date', $second);
        $this->assertNull($second['vat_rate_date'], 'datum řádku se v DB smaže');
    }

    public function testHeadSaveKeepsStoredSourceWhenPayloadOmitsIt(): void
    {
        $doc = $this->doc();
        $this->dbRows = [$this->row('cz-120', 20.0, -2000.0, ['id' => 12, 'vat_rate_date' => '2012-11-20'])];
        $data = $this->head(['id' => 42, 'vat_duzp' => '2014-03-10', 'vat_dppd' => '2014-03-10']);

        [$rows] = $this->save($doc, $data, ['vat_rate_source' => 2, 'vat_rate_date' => null]);

        $this->assertSame(2, $data['vat_rate_source'], 'částečný zápis přepínač nemění');
        $this->assertSame('2012-11-20', $rows[0]['vat_rate_date']);
        $this->assertSame(20.0, (float) $rows[0]['vat_pct']);
    }

    // ── D8: převzatá rekapitulace ────────────────────────────────────────

    public function testDeclaredRecapPercentIsDerivedAndAmountsStay(): void
    {
        $doc = $this->doc();
        $this->dbRows = [$this->row('cz-110', 21.0, 1000.0, ['id' => 1])];
        $data = $this->head([
            'id' => 42,
            'vat_dppd' => '2012-12-31',
            'vat_recap_source' => 1,
            'vatRecap' => [
                // Sazba ze vstupu je jiná, částky dodavatele haléřově „po svém“.
                ['id' => 5, 'vat_code' => 'cz-110', 'vat_pct' => 21.0, 'base' => 1000.00, 'tax' => 200.01, 'total' => 1200.01],
            ],
        ]);

        [, $recap] = $this->save($doc, $data, ['vat_recap_source' => 1]);

        $this->assertSame(20.0, (float) $recap[0]['vat_pct'], 'procento není vstup');
        $this->assertSame(1000.00, (float) $recap[0]['base']);
        $this->assertSame(200.01, (float) $recap[0]['tax'], 'částky převzaté rekapitulace se nemění');
        $this->assertSame(1200.01, (float) $recap[0]['total']);
        $this->assertSame(5, $recap[0]['id']);
        $this->assertNull($recap[0]['vat_rate_date']);
    }

    public function testSupplierWithOldRateShowsAsInconsistentRecap(): void
    {
        $doc = $this->doc();
        $this->dbRows = [$this->row('cz-110', 21.0, 1000.0, ['id' => 1])];
        // Dodavatel zdanil 20 %, doklad má DPPD v roce 2013 → sazebník 21 %.
        $recap = [['vat_code' => 'cz-110', 'vat_pct' => 20.0, 'base' => 1000.0, 'tax' => 200.0, 'total' => 1200.0]];
        $data = $this->head(['id' => 42, 'docState' => 10, 'vat_recap_source' => 1, 'vatRecap' => $recap]);

        $findings = $this->findings($doc, $data, 'vat_recap_inconsistent');
        $this->assertCount(1, $findings);
        $this->assertSame('warning', $findings[0]['severity']);
        $this->assertStringContainsString('21 %', $findings[0]['message']);

        // Datum sazby v roce 2012 nesoulad odstraní — stopa je datum, ne číslo.
        $data['vat_rate_source'] = 1;
        $data['vat_rate_date'] = '2012-12-31';
        $this->assertSame([], $this->findings($doc, $data, 'vat_recap_inconsistent'));
        $this->assertSame([], $this->findings($doc, $data, 'rows_recap_mismatch'), 'řádky i rekapitulace k témuž datu');
    }

    public function testDeclaredRecapLineKeepsOwnDateWithRowsSource(): void
    {
        $doc = $this->doc();
        $this->dbRows = [];
        $data = $this->head([
            'id' => 42,
            'issue_date' => '2014-03-12', 'vat_duzp' => '2014-03-10', 'vat_dppd' => '2014-03-10',
            'vat_rate_source' => 2,
            'vat_recap_source' => 1,
            'vatRecap' => [
                ['vat_code' => 'cz-110', 'vat_pct' => 0, 'base' => 8000.0, 'tax' => 1680.0, 'total' => 9680.0],
                ['vat_code' => 'cz-110', 'vat_pct' => 0, 'base' => -2000.0, 'tax' => -400.0, 'total' => -2400.0,
                    'vat_rate_date' => '2012-11-20'],
            ],
        ]);

        [, $recap] = $this->save($doc, $data, ['vat_recap_source' => 1]);

        $this->assertSame([21.0, 20.0], array_map(static fn ($r) => (float) $r['vat_pct'], $recap));
        $this->assertSame([null, '2012-11-20'], array_column($recap, 'vat_rate_date'));
    }

    public function testCopyFromComputedToDeclaredCarriesGroupDate(): void
    {
        $doc = $this->doc();
        $this->dbRows = [
            $this->row('cz-120', 21.0, 5000.0, ['id' => 11, 'vat_rate_date' => null]),
            $this->row('cz-120', 20.0, -2000.0, ['id' => 12, 'vat_rate_date' => '2012-11-20']),
            $this->row('cz-120', 20.0, -500.0, ['id' => 13, 'vat_rate_date' => '2012-12-05']),
        ];
        $data = $this->head([
            'id' => 42,
            'issue_date' => '2014-03-12', 'vat_duzp' => '2014-03-10', 'vat_dppd' => '2014-03-10',
            'vat_rate_source' => 2,
            'vat_recap_source' => 1,
        ]);

        // Uložený doklad má přepočítanou rekapitulaci — přepnutí ji zkopíruje.
        [, $recap] = $this->save($doc, $data, ['vat_recap_source' => 0, 'vat_rate_source' => 2]);

        $this->assertCount(2, $recap);
        $this->assertNull($recap[0]['vat_rate_date']);
        $this->assertSame('2012-11-20', $recap[1]['vat_rate_date'], 'datum prvního řádku skupiny');
        $this->assertSame(-2500.0, (float) $recap[1]['base'], 'různá data, stejné procento = jedna skupina');
    }

    // ── U6: oddaňovací pár ───────────────────────────────────────────────

    public function testReversePairInheritsPercentAndDateOfItsPrimaryLine(): void
    {
        $doc = $this->doc();
        $this->dbRows = [];
        $data = $this->head([
            'id' => 42,
            'vat_rate_source' => 2,
            'vat_recap_source' => 1,
            'vatRecap' => [
                ['vat_code' => 'cz-115', 'vat_pct' => 21.0, 'base' => 100.0, 'tax' => 20.0, 'total' => 100.0,
                    'reverse_charge_code' => '4', 'vat_rate_date' => '2012-12-31'],
                ['vat_code' => 'cz-203', 'vat_pct' => 21.0, 'base' => 100.0, 'tax' => 20.0, 'total' => 120.0,
                    'reverse_charge_code' => '4', 'is_reverse_pair' => 1],
            ],
        ]);

        [, $recap] = $this->save($doc, $data, ['vat_recap_source' => 1]);

        $this->assertSame(20.0, (float) $recap[0]['vat_pct']);
        $this->assertSame(20.0, (float) $recap[1]['vat_pct'], 'pár dědí procento primární skupiny');
        $this->assertSame('2012-12-31', $recap[1]['vat_rate_date'], 'i její datum');
        $this->assertSame([], $this->findings($doc, $data + ['docState' => 40], 'vat_rate_missing'));
    }

    // ── U8: bez konfigurace ──────────────────────────────────────────────

    public function testWithoutConfigurationPercentStaysFromInput(): void
    {
        $doc = $this->doc(withConfig: false);
        $data = $this->head([
            'vat_dppd' => '2012-12-31',
            'rows'     => [$this->row('cz-110', 19.0, 1000.0)],
        ]);

        [$rows] = $this->save($doc, $data);

        $this->assertSame(19.0, (float) $rows[0]['vat_pct'], 'bez číselníku se procento neodvozuje');
        $this->assertSame([], $this->findings($doc, $data + ['docState' => 40], 'vat_rate_missing'));
    }

    public function testTextRowAndRowWithoutCodeAreLeftAlone(): void
    {
        $doc = $this->doc();
        $data = $this->head([
            'rows' => [
                ['row_kind' => 0, 'description' => 'Poznámka'],
                ['row_kind' => 1, 'quantity' => 1, 'unit_price' => 50.0, 'price_calc_mode' => 0, 'vat_code' => null],
            ],
        ]);

        [$rows] = $this->save($doc, $data);

        $this->assertArrayNotHasKey('vat_pct', $rows[0]);
        $this->assertArrayNotHasKey('vat_pct', $rows[1]);
        $this->assertSame([], $this->findings($doc, $data + ['docState' => 40], 'vat_rate_missing'));
    }

    // ── U7: předmět plnění k datu řádku ──────────────────────────────────

    public function testReverseChargeSubjectIsCheckedAtRowDate(): void
    {
        $doc = $this->doc();
        // Předmět 4a platí od 1. 7. 2017.
        $row = $this->row('cz-150', 21.0, 100.0, ['reverse_charge_code' => '4a']);
        $data = $this->head([
            'docState' => 10,
            'issue_date' => '2026-05-07', 'vat_duzp' => '2026-05-06', 'vat_dppd' => '2026-05-06',
            'vat_rate_source' => 2,
            'rows' => [$row, ['vat_rate_date' => '2017-06-30'] + $row],
        ]);

        $errors = array_values(array_filter(
            array_map(static fn ($e): array => $e->toArray(), $doc->validate($data)->getErrors()),
            static fn (array $e): bool => $e['code'] === 'invalid_value',
        ));

        $this->assertCount(1, $errors, 'k automatice dokladu platí, k datu řádku ne');
        $this->assertSame('rows.1.reverse_charge_code', $errors[0]['column']);
    }
}
