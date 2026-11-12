<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\World\Vat\VatRateResolver;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

/**
 * Převzatá rekapitulace (`vat_recap_source = 1`, spec
 * `docs/vat-calculation.md` § 5): rekapitulace je vstup a fakt — uložení ji
 * nepřepočítá, jen z ní dopočte domácí měnu, součty hlavičky a flagy sčítání
 * z definice kódu. Nesrovnalosti hlásí warningy, neblokují.
 */
class DocDocumentVatRecapDeclaredTest extends TestCase
{
    private const VAT_CZ_PATH = __DIR__ . '/../../../../../modules/world/vat/config/vat-cz.jsonc';

    private string $tmpDir;

    /** @var array<int, array<string, mixed>> Řádky, které vrací mock DB. */
    private array $dbRows = [];

    /** @var array<int, array<string, mixed>> Rekapitulace, kterou vrací mock DB. */
    private array $dbRecap = [];

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_declared_test_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode([
                '_meta' => ['language' => 'cs'],
                'items' => ['world.vat.cz' => JsoncParser::parseFile(self::VAT_CZ_PATH)],
            ]),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = "$path/$entry";
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($path);
    }

    private function doc(): TestableDocsHeadsDocument
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
        $doc->setConfig(ConfigRuntime::load($this->tmpDir, 'cs'));
        return $doc;
    }

    /** @return array<string, array<string, mixed>> */
    private function vatCodes(): array
    {
        return (new VatRateResolver(ConfigRuntime::load($this->tmpDir, 'cs')))
            ->getVatCodes('cz', direction: null, place: null, includeHidden: true);
    }

    /** @param array<string, mixed> $data */
    private function runPipeline(TestableDocsHeadsDocument $doc, array &$data, array $rows): array
    {
        $doc->beforeSavePub($data, $data['_original'] ?? null);
        unset($data['_original']);
        return [$doc->getComputedRows(), $data['vatRecap']];
    }

    private function row(string $code, float $pct, float $price, ?int $id = null): array
    {
        $row = [
            'row_kind'        => 1,
            'quantity'        => 1,
            'unit_price'      => $price,
            'price_calc_mode' => 0,
            'vat_code'        => $code,
            'vat_pct'         => $pct,
        ];
        if ($id !== null) {
            $row['id'] = $id;
        }
        return $row;
    }

    /** Doklad s převzatou rekapitulací dodavatele (haléřově „po svém"). */
    private function declaredData(array $recap, array $extra = []): array
    {
        return array_merge([
            'id' => 42,
            'vat_mode' => 2,
            'vat_recap_source' => 1,
            'vat_registration' => 1,
            'vat_duzp' => '2026-05-06',
            'exchange_rate' => 1.0,
            'total_rounding_mode' => 0,
            'vatRecap' => $recap,
        ], $extra);
    }

    // ── Převzetí ────────────────────────────────────────────────────────────

    public function testDeclaredRecapIsNotRecomputed(): void
    {
        $doc = $this->doc();
        // Řádky by dokladovou metodou daly 90,91 / 19,09 — dodavatel ale
        // na faktuře uvádí 90,90 / 19,10 a to je pro nás fakt.
        $this->dbRows = [$this->row('cz-110', 21.0, 55.00, 1), $this->row('cz-110', 21.0, 55.00, 2)];
        $data = $this->declaredData([
            ['vat_code' => 'cz-110', 'vat_pct' => 21.0, 'base' => 90.90, 'tax' => 19.10, 'total' => 110.00],
        ]);
        [$rows, $recap] = $this->runPipeline($doc, $data, $this->dbRows);

        $this->assertCount(1, $recap);
        $this->assertSame(90.90, $recap[0]['base']);
        $this->assertSame(19.10, $recap[0]['tax']);
        $this->assertSame(110.00, $recap[0]['total']);

        // součty hlavičky z převzaté rekapitulace
        $this->assertSame(90.90, $data['total_base']);
        $this->assertSame(19.10, $data['total_vat']);
        $this->assertSame(110.00, $data['total_amount']);

        // řádky dorovnané na převzatou rekapitulaci (v obou měnách)
        $this->assertSame(90.90, round((float) $rows[0]['vat_base'] + (float) $rows[1]['vat_base'], 2));
        $this->assertSame(19.10, round((float) $rows[0]['vat_amount'] + (float) $rows[1]['vat_amount'], 2));
        $this->assertSame(90.90, round((float) $rows[0]['vat_base_dom'] + (float) $rows[1]['vat_base_dom'], 2));
    }

    public function testSumFlagsComeFromCodeDefinitionNotFromInput(): void
    {
        $doc = $this->doc();
        $this->dbRows = [$this->row('cz-115', 21.0, 1000.00, 1)];
        // Vstup tvrdí, že se daň sčítá — definice cz-115 (PDP na vstupu) říká
        // sumTax = 0 a ta vyhrává.
        $data = $this->declaredData([
            [
                'vat_code' => 'cz-115', 'vat_pct' => 21.0,
                'base' => 1000.00, 'tax' => 210.00, 'total' => 1000.00,
                'sum_tax' => 1,
            ],
        ], ['vat_mode' => 1]);
        [, $recap] = $this->runPipeline($doc, $data, $this->dbRows);

        $this->assertSame(1, $recap[0]['sum_base']);
        $this->assertSame(0, $recap[0]['sum_tax']);
        $this->assertSame(0.0, $data['total_vat'], 'daň PDP nevstupuje do součtu hlavičky');
        $this->assertSame(1000.0, $data['total_amount']);
    }

    public function testDeclaredRecapKeepsRowIdsAndComputesDomesticAmounts(): void
    {
        $doc = $this->doc();
        $this->dbRows = [$this->row('cz-110', 21.0, 121.00, 1)];
        $data = $this->declaredData([
            ['id' => 7, 'vat_code' => 'cz-110', 'vat_pct' => 21.0, 'base' => 100.00, 'tax' => 21.00, 'total' => 121.00],
        ], ['exchange_rate' => 25.0, 'doc_currency' => 'eur', 'home_currency' => 'czk']);
        [, $recap] = $this->runPipeline($doc, $data, $this->dbRows);

        $this->assertSame(7, $recap[0]['id'], 'id řádku rekapitulace se zachovává pro child sync');
        $this->assertSame(2500.0, $recap[0]['base_dom']);
        $this->assertSame(525.0, $recap[0]['tax_dom']);
        $this->assertSame(3025.0, $recap[0]['total_dom']);
        $this->assertSame(2500.0, $data['total_base_dom']);
        $this->assertSame(525.0, $data['total_vat_dom']);
    }

    public function testDeclaredRecapLoadedFromDbOnHeaderOnlySave(): void
    {
        $doc = $this->doc();
        $this->dbRows = [$this->row('cz-110', 21.0, 121.00, 1)];
        $this->dbRecap = [[
            'id' => 9, 'doc_head' => 42, 'vat_code' => 'cz-110', 'vat_pct' => 21.0,
            'base' => 99.00, 'tax' => 22.00, 'total' => 121.00,
            'sum_base' => 1, 'sum_tax' => 1, 'sum_total' => 1,
            'is_reverse_pair' => 0, 'order_pos' => 0,
        ]];
        // payload rekapitulaci nenese (uložení hlavičky) — vezme se z DB
        $data = $this->declaredData([]);
        unset($data['vatRecap']);
        [, $recap] = $this->runPipeline($doc, $data, $this->dbRows);

        $this->assertCount(1, $recap);
        $this->assertSame(99.00, $recap[0]['base']);
        $this->assertSame(22.00, $recap[0]['tax']);
        $this->assertSame(9, $recap[0]['id']);
    }

    public function testUnknownVatCodeInDeclaredRecapThrows(): void
    {
        $doc = $this->doc();
        $this->dbRows = [$this->row('cz-110', 21.0, 121.00, 1)];
        $data = $this->declaredData([
            ['vat_code' => 'cz-999', 'vat_pct' => 21.0, 'base' => 100.0, 'tax' => 21.0, 'total' => 121.0],
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/cz-999/');
        $doc->beforeSavePub($data);
    }

    public function testRowsOutsideDeclaredRecapAreNotDoubleCounted(): void
    {
        $doc = $this->doc();
        // Řádek s kódem, který v převzaté rekapitulaci není (typicky import
        // s jiným mapováním kódů) — základ se nesmí přičíst nad rekapitulaci.
        $this->dbRows = [
            $this->row('cz-110', 21.0, 121.00, 1),
            $this->row('cz-111', 12.0, 112.00, 2),
        ];
        $data = $this->declaredData([
            ['vat_code' => 'cz-110', 'vat_pct' => 21.0, 'base' => 100.00, 'tax' => 21.00, 'total' => 121.00],
        ]);
        $this->runPipeline($doc, $data, $this->dbRows);

        $this->assertSame(100.00, $data['total_base']);
        $this->assertSame(121.00, $data['total_amount']);
    }

    // ── Přechody zdroje (I3) ────────────────────────────────────────────────

    public function testSwitchFromComputedToDeclaredCopiesComputedRecap(): void
    {
        $doc = $this->doc();
        $this->assertFalse(
            $doc->useDeclaredRecapPub(['vat_recap_source' => 1, 'id' => 42], ['vat_recap_source' => 0]),
            'přepnutí 0 → 1: startovní převzatá se přegeneruje z řádků',
        );
        $this->assertTrue(
            $doc->useDeclaredRecapPub(['vat_recap_source' => 1, 'id' => 42], ['vat_recap_source' => 1]),
            'uložení už převzatého dokladu recap nepřepočítá',
        );
        $this->assertFalse(
            $doc->useDeclaredRecapPub(['vat_recap_source' => 0, 'id' => 42], ['vat_recap_source' => 1]),
            'přepnutí 1 → 0: recap se přegeneruje z řádků',
        );
    }

    public function testDeclaredWithoutOriginalDataUsesPayloadOrStoredRecap(): void
    {
        $doc = $this->doc();
        // Applier: nový doklad s rekapitulací v payloadu
        $this->assertTrue($doc->useDeclaredRecapPub([
            'vat_recap_source' => 1,
            'vatRecap' => [['vat_code' => 'cz-110']],
        ], null));
        // Nový doklad bez rekapitulace — není co převzít
        $this->assertFalse($doc->useDeclaredRecapPub(['vat_recap_source' => 1], null));
        // Interní přepočet nad uloženým dokladem (DocRowsDocument)
        $this->assertTrue($doc->useDeclaredRecapPub(['vat_recap_source' => 1, 'id' => 42], null));
    }

    public function testSwitchToComputedRebuildsRecapFromRows(): void
    {
        $doc = $this->doc();
        $this->dbRows = [$this->row('cz-110', 21.0, 55.00, 1), $this->row('cz-110', 21.0, 55.00, 2)];
        $data = $this->declaredData([
            ['vat_code' => 'cz-110', 'vat_pct' => 21.0, 'base' => 90.90, 'tax' => 19.10, 'total' => 110.00],
        ]);
        $data['vat_recap_source'] = 0;
        $data['_original'] = ['vat_recap_source' => 1];
        [, $recap] = $this->runPipeline($doc, $data, $this->dbRows);

        $this->assertSame(90.91, $recap[0]['base'], 'přepočítaná dokladovou metodou');
        $this->assertSame(19.09, $recap[0]['tax']);
    }

    // ── Warningy ────────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function warnings(TestableDocsHeadsDocument $doc, array $data): array
    {
        return array_map(
            static fn($w) => $w->toArray(),
            $doc->validate($data)->getWarnings(),
        );
    }

    public function testRowsRecapMismatchWarnsButDoesNotBlock(): void
    {
        $doc = $this->doc();
        // Rekapitulace na 110,00 s DPH, řádky jen na 55,00 — chybí řádek.
        $this->dbRows = [$this->row('cz-110', 21.0, 55.00, 1)];
        $data = $this->declaredData([
            ['vat_code' => 'cz-110', 'vat_pct' => 21.0, 'base' => 90.91, 'tax' => 19.09, 'total' => 110.00],
        ]);
        $result = $doc->validate($data);
        $codes = array_column(array_map(static fn($w) => $w->toArray(), $result->getWarnings()), 'code');

        $this->assertContains('rows_recap_mismatch', $codes);
        // warningy uložení neblokují — errory jsou jen chybějící povinná pole
        $this->assertNotContains('rows_recap_mismatch', array_column(
            array_map(static fn($e) => $e->toArray(), $result->getErrors()),
            'code',
        ));
    }

    public function testRowsMatchingDeclaredRecapWithinToleranceDoesNotWarn(): void
    {
        $doc = $this->doc();
        $this->dbRows = [$this->row('cz-110', 21.0, 55.00, 1), $this->row('cz-110', 21.0, 55.00, 2)];
        $data = $this->declaredData([
            ['vat_code' => 'cz-110', 'vat_pct' => 21.0, 'base' => 90.90, 'tax' => 19.10, 'total' => 110.00],
        ]);

        $this->assertSame([], array_column($this->warnings($doc, $data), 'code'));
    }

    public function testInconsistentDeclaredRecapWarns(): void
    {
        $doc = $this->doc();
        $this->dbRows = [$this->row('cz-110', 21.0, 121.00, 1)];
        $data = $this->declaredData([
            // base + tax ≠ total a zároveň daň neodpovídá sazbě
            ['vat_code' => 'cz-110', 'vat_pct' => 21.0, 'base' => 100.00, 'tax' => 12.00, 'total' => 121.00],
        ]);
        $warnings = $this->warnings($doc, $data);
        $codes = array_column($warnings, 'code');

        $this->assertContains('vat_recap_inconsistent', $codes);
        $this->assertSame('recap', $warnings[array_search('vat_recap_inconsistent', $codes, true)]['column']);
    }

    public function testUnexpectedRateForCodeWarns(): void
    {
        $doc = $this->doc();
        $this->dbRows = [$this->row('cz-110', 21.0, 121.00, 1)];
        // Vnitřně konzistentní (100 + 19 = 119, 19 % ze 100), ale cz-110 má
        // k roku 2026 sazbu 21 % — sazba z dokladu nepatří ke kódu.
        $data = $this->declaredData([
            ['vat_code' => 'cz-110', 'vat_pct' => 19.0, 'base' => 100.00, 'tax' => 19.00, 'total' => 119.00],
        ]);
        $codes = array_column($this->warnings($doc, $data), 'code');

        $this->assertContains('vat_recap_inconsistent', $codes);
    }

    /** Opravný doklad (#92 D4): sazba převzaté rekapitulace se ověřuje k datu plnění původního dokladu. */
    public function testCorrectiveDocumentChecksRateAtOriginalTaxPointDate(): void
    {
        $doc = $this->doc();
        $this->dbRows = [$this->row('cz-110', 20.0, 120.00, 1)];
        // cz-110 mělo v roce 2012 sazbu 20 % — k DUZP opravy (2026) by 20 % neprošlo.
        $recap = [['vat_code' => 'cz-110', 'vat_pct' => 20.0, 'base' => 100.00, 'tax' => 20.00, 'total' => 120.00]];

        $codes = array_column($this->warnings($doc, $this->declaredData($recap, [
            'corrective' => 1, 'corrected_doc_date' => '2012-06-01',
        ])), 'code');
        $this->assertNotContains('vat_recap_inconsistent', $codes, 'sazba k datu původního plnění sedí');

        $codes = array_column($this->warnings($doc, $this->declaredData($recap, [
            'corrective' => 1, 'corrected_doc_date' => null,
        ])), 'code');
        $this->assertContains('vat_recap_inconsistent', $codes, 'bez data původního plnění platí DUZP opravy');

        $codes = array_column($this->warnings($doc, $this->declaredData($recap, [
            'corrective' => 0, 'corrected_doc_date' => '2012-06-01',
        ])), 'code');
        $this->assertContains('vat_recap_inconsistent', $codes, 'běžný doklad: DUZP');
    }

    public function testComputedRecapProducesNoRecapWarnings(): void
    {
        $doc = $this->doc();
        $this->dbRows = [$this->row('cz-110', 21.0, 55.00, 1)];
        $data = $this->declaredData([]);
        $data['vat_recap_source'] = 0;
        unset($data['vatRecap']);

        $this->assertSame([], array_column($this->warnings($doc, $data), 'code'));
    }

    // ── Dorovnání jen do meze ───────────────────────────────────────────────

    public function testRowsAreNotReconciledBeyondTolerance(): void
    {
        $doc = $this->doc();
        $this->dbRows = [$this->row('cz-110', 21.0, 55.00, 1)];
        // Rekapitulace je o 55 Kč jinde než řádek — dorovnání se neprovede,
        // řádek zůstane, jak je (a validace vydá rows_recap_mismatch).
        $data = $this->declaredData([
            ['vat_code' => 'cz-110', 'vat_pct' => 21.0, 'base' => 90.91, 'tax' => 19.09, 'total' => 110.00],
        ]);
        [$rows] = $this->runPipeline($doc, $data, $this->dbRows);

        $this->assertSame(45.45, (float) $rows[0]['vat_base']);
        $this->assertSame(9.55, (float) $rows[0]['vat_amount']);
    }

    public function testHalerDifferenceIsStillReconciled(): void
    {
        $doc = $this->doc();
        $this->dbRows = [$this->row('cz-110', 21.0, 55.00, 1)];
        // O haléř jinak (dodavatelovo zaokrouhlení) — dorovná se.
        $data = $this->declaredData([
            ['vat_code' => 'cz-110', 'vat_pct' => 21.0, 'base' => 45.46, 'tax' => 9.54, 'total' => 55.00],
        ]);
        [$rows] = $this->runPipeline($doc, $data, $this->dbRows);

        $this->assertSame(45.46, (float) $rows[0]['vat_base']);
        $this->assertSame(9.54, (float) $rows[0]['vat_amount']);
    }


    // ── Předmět plnění tuzemského přenesení (#89 D7) ───────────────────────

    public function testDeclaredRecapTakesSubjectOverAndDropsItOnOtherCodes(): void
    {
        $doc = $this->doc();
        $this->dbRows = [];
        $data = $this->declaredData([
            ['vat_code' => 'cz-115', 'vat_pct' => 21.0, 'base' => 100.0, 'tax' => 21.0, 'total' => 100.0,
                'reverse_charge_code' => '4a'],
            ['vat_code' => 'cz-203', 'vat_pct' => 21.0, 'base' => 100.0, 'tax' => 21.0, 'total' => 121.0,
                'is_reverse_pair' => 1, 'reverse_charge_code' => '4a'],
            ['vat_code' => 'cz-110', 'vat_pct' => 21.0, 'base' => 50.0, 'tax' => 10.5, 'total' => 60.5,
                'reverse_charge_code' => '4a'],
        ], ['vat_mode' => 1]);

        [, $recap] = $this->runPipeline($doc, $data, []);

        $this->assertSame(
            ['4a', '4a', null],
            array_column($recap, 'reverse_charge_code'),
            'kód přenesení a oddaňovací pár předmět nesou, jiný kód ne',
        );
    }

    public function testRowsAreMatchedToDeclaredRecapPerSubject(): void
    {
        $doc = $this->doc();
        // Dva řádky se stejným kódem a sazbou, různý předmět = dvě skupiny;
        // součet řádků se porovnává s rekapitulací po předmětech.
        $this->dbRows = [
            $this->row('cz-115', 21.0, 100.00, 1) + ['reverse_charge_code' => '4'],
            $this->row('cz-115', 21.0, 200.00, 2) + ['reverse_charge_code' => '14'],
        ];
        $data = $this->declaredData([
            ['vat_code' => 'cz-115', 'vat_pct' => 21.0, 'base' => 100.0, 'tax' => 21.0, 'total' => 100.0,
                'reverse_charge_code' => '4'],
            ['vat_code' => 'cz-115', 'vat_pct' => 21.0, 'base' => 200.0, 'tax' => 42.0, 'total' => 200.0,
                'reverse_charge_code' => '14'],
        ], ['vat_mode' => 1]);

        $codes = array_column($this->warnings($doc, $data), 'code');
        $this->assertNotContains('rows_recap_mismatch', $codes);

        // Prohozené předměty v rekapitulaci = součty řádků nesedí.
        $data['vatRecap'][0]['reverse_charge_code'] = '14';
        $data['vatRecap'][1]['reverse_charge_code'] = '4';
        $codes = array_column($this->warnings($doc, $data), 'code');
        $this->assertContains('rows_recap_mismatch', $codes);
    }

    public function testDeclaredRecapLineWithReverseChargeCodeNeedsSubjectOnConfirmation(): void
    {
        $doc = $this->doc();
        $this->dbRows = [];
        $recap = [
            ['vat_code' => 'cz-115', 'vat_pct' => 21.0, 'base' => 100.0, 'tax' => 21.0, 'total' => 100.0],
            ['vat_code' => 'cz-203', 'vat_pct' => 21.0, 'base' => 100.0, 'tax' => 21.0, 'total' => 121.0,
                'is_reverse_pair' => 1],
        ];
        $recapErrors = static fn (array $data): array => array_values(array_filter(
            array_map(static fn ($e) => $e->toArray(), $doc->validate($data)->getErrors()),
            static fn (array $e): bool => $e['column'] === 'recap',
        ));

        $draft = $this->declaredData($recap, ['docState' => 10, 'vat_mode' => 1]);
        $this->assertSame([], $recapErrors($draft), 'Koncept se bez předmětu uloží');

        $confirmed = $this->declaredData($recap, ['docState' => 40, 'vat_mode' => 1]);
        $errors = $recapErrors($confirmed);
        $this->assertCount(1, $errors, 'jen řádek s kódem přenesení, pár ne');
        $this->assertSame('required', $errors[0]['code']);

        $recap[0]['reverse_charge_code'] = '99';
        $unknown = $this->declaredData($recap, ['docState' => 10, 'vat_mode' => 1]);
        $errors = $recapErrors($unknown);
        $this->assertCount(1, $errors, 'předmět mimo číselník je chyba i v Konceptu');
        $this->assertSame('invalid_value', $errors[0]['code']);
    }
}
