<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Vat\ControlStatementCalculator;
use Shipard\Module\Economy\Vat\VatOutputsMapping;

/**
 * Rozpad sekcí KH na syntetických datech: hranice 10 000,00 vč. daně
 * (strict >, abs), CZ/ne-CZ/prázdné DIČ, PDP kódy 4/5, sazbová pásma,
 * agregace A5/B3, měkké chyby.
 */
class ControlStatementCalculatorTest extends TestCase
{
    private function calculator(): ControlStatementCalculator
    {
        // Minimální výřez mapování a číselníku — sémanticky shodný
        // s vat-reports-cz.jsonc / vat-cz.jsonc.
        $mapping = new VatOutputsMapping(['vatOutputs' => [
            'cz-120' => ['dp3' => ['row' => 1], 'kh' => ['group' => 'A4A5'], 'sh' => null],
            'cz-121' => ['dp3' => ['row' => 2], 'kh' => ['group' => 'A4A5'], 'sh' => null],
            'cz-310' => ['dp3' => ['row' => 2], 'kh' => ['group' => 'A4A5'], 'sh' => null],
            'cz-311' => ['dp3' => ['row' => 2], 'kh' => ['group' => 'A4A5'], 'sh' => null],
            'cz-122' => ['dp3' => null, 'kh' => null, 'sh' => null],
            'cz-123' => ['dp3' => ['row' => 50], 'kh' => null, 'sh' => null],
            'cz-110' => ['dp3' => ['row' => 40, 'col' => 'full'], 'kh' => ['group' => 'B2B3'], 'sh' => null],
            'cz-115' => ['dp3' => ['row' => 43, 'col' => 'full'], 'kh' => ['group' => 'B1'], 'sh' => null],
            'cz-117' => ['dp3' => ['row' => 43, 'col' => 'full'], 'kh' => ['group' => 'B1'], 'sh' => null],
            'cz-150' => ['dp3' => ['row' => 25], 'kh' => ['group' => 'A1'], 'sh' => null],
            'cz-203' => ['dp3' => ['row' => 10], 'kh' => null, 'sh' => null],
            'cz-205' => ['dp3' => ['row' => 3], 'kh' => ['group' => 'A2'], 'sh' => null],
        ]]);
        $vatCodes = [
            'cz-120' => ['category' => 'standard'],
            'cz-121' => ['category' => 'reduced'],
            'cz-310' => ['category' => 'reduced1'],
            'cz-311' => ['category' => 'reduced2'],
            'cz-122' => ['category' => 'zero'],
            'cz-123' => ['category' => 'exempt'],
            'cz-110' => ['category' => 'standard'],
            'cz-115' => ['category' => 'standard'],
            'cz-117' => ['category' => 'standard'],
            'cz-150' => ['category' => 'standard'],
            'cz-203' => ['category' => 'standard'],
            'cz-205' => ['category' => 'standard'],
        ];
        return new ControlStatementCalculator($mapping, $vatCodes);
    }

    /** @return array<string, mixed> */
    private function doc(array $overrides = []): array
    {
        return $overrides + [
            'id'                 => 1,
            'doc_number'         => 'FV-001',
            'partner_doc_number' => 'DOD-99',
            'total_amount_dom'   => 12100.0,
            'vat_duzp'           => '2026-01-10',
            'vat_dppd'           => '2026-01-12',
            'customer_vat_id'    => 'CZ12345678',
            'supplier_vat_id'    => 'CZ87654321',
            'recap'              => [
                ['vat_code' => 'cz-120', 'base_dom' => 10000.0, 'tax_dom' => 2100.0],
            ],
        ];
    }

    // ── Ruční zařazení (`cs_mode`, #77, 1:1 se starým vatCS) ───────────────

    public function testForcedDetailPutsSmallOutputIntoA4(): void
    {
        $result = $this->calculator()->calculate([$this->doc([
            'total_amount_dom' => 4356.0,
            'cs_mode'          => ControlStatementCalculator::MODE_DETAIL,
            'recap'            => [['vat_code' => 'cz-120', 'base_dom' => 3600.0, 'tax_dom' => 756.0]],
        ])]);

        $this->assertCount(1, $result['sections']['A4']);
        $this->assertSame([], $result['sections']['A5']);
        $this->assertSame([], $result['errors']);
    }

    public function testForcedDetailPutsSmallInputIntoB2(): void
    {
        $result = $this->calculator()->calculate([$this->doc([
            'total_amount_dom' => 4590.0,
            'cs_mode'          => ControlStatementCalculator::MODE_DETAIL,
            'recap'            => [['vat_code' => 'cz-110', 'base_dom' => 3793.39, 'tax_dom' => 796.61]],
        ])]);

        $this->assertCount(1, $result['sections']['B2']);
        $this->assertSame('DOD-99', $result['sections']['B2'][0]['evidNumber']);
        $this->assertSame(ControlStatementCalculator::MODE_DETAIL, $result['sections']['B2'][0]['csMode'], 'řádek nese režim pro sloupec Zařazení');
        $this->assertSame([], $result['sections']['B3']);
        $this->assertSame([], $result['excluded']);
    }

    /** Starý engine vynutil A4 i bez CZ DIČ; podání pak `dic_odb` chybí — proto měkká chyba. */
    public function testForcedDetailWithoutCzVatIdGoesToA4WithSoftError(): void
    {
        $result = $this->calculator()->calculate([$this->doc([
            'customer_vat_id' => '',
            'cs_mode'         => ControlStatementCalculator::MODE_DETAIL,
        ])]);

        $this->assertCount(1, $result['sections']['A4']);
        $this->assertSame([['code' => 'missingVatId', 'docId' => 1, 'docNumber' => 'FV-001', 'section' => 'A4']], $result['errors']);
    }

    public function testForcedAggregateKeepsLargeDocumentsOutOfDetail(): void
    {
        $result = $this->calculator()->calculate([
            $this->doc(['id' => 1, 'cs_mode' => ControlStatementCalculator::MODE_AGGREGATE]),
            $this->doc([
                'id'      => 2,
                'cs_mode' => ControlStatementCalculator::MODE_AGGREGATE,
                'recap'   => [['vat_code' => 'cz-110', 'base_dom' => 10000.0, 'tax_dom' => 2100.0]],
            ]),
        ]);

        $this->assertSame([], $result['sections']['A4']);
        $this->assertSame([], $result['sections']['B2']);
        $this->assertSame(10000.0, $result['sections']['A5'][0]['base1']);
        $this->assertSame(10000.0, $result['sections']['B3'][0]['base1']);
    }

    public function testExcludedDocumentIsMissingFromEverySectionIncludingFixedOnes(): void
    {
        $calculator = $this->calculator();
        $excluded   = $this->doc([
            'cs_mode' => ControlStatementCalculator::MODE_EXCLUDE,
            'recap'   => [
                ['vat_code' => 'cz-120', 'base_dom' => 10000.0, 'tax_dom' => 2100.0],
                ['vat_code' => 'cz-150', 'base_dom' => 5000.0, 'tax_dom' => 0.0],
                ['vat_code' => 'cz-115', 'base_dom' => 5000.0, 'tax_dom' => 1050.0],
            ],
        ]);

        $result = $calculator->calculate([$excluded]);
        foreach (ControlStatementCalculator::SECTIONS as $section) {
            $this->assertSame([], $result['sections'][$section], "sekce {$section} má být prázdná");
        }
        $this->assertSame([], $result['errors']);
        $this->assertSame([['docId' => 1, 'docNumber' => 'FV-001']], $result['excluded'], 'report vyřazené doklady vypíše');

        // Totéž vidí snapshot podání přes sectionForCode — kh_section NULL.
        $this->assertNull($calculator->sectionForCode($excluded, 'cz-120'));
        $this->assertNull($calculator->sectionForCode($excluded, 'cz-150'));
        $this->assertSame('A4', $calculator->sectionForCode($this->doc(), 'cz-120'));
    }

    public function testManualModeDoesNotTouchFixedSections(): void
    {
        $result = $this->calculator()->calculate([$this->doc([
            'cs_mode' => ControlStatementCalculator::MODE_AGGREGATE,
            'recap'   => [['vat_code' => 'cz-150', 'base_dom' => 5000.0, 'tax_dom' => 0.0]],
        ])]);

        $this->assertCount(1, $result['sections']['A1'], 'A1 nemá souhrnnou variantu, režim 2 ji nevyrobí');
    }

    // ── A4/A5: limit + DIČ ──────────────────────────────────────────────────

    public function testOutputOverLimitWithCzVatIdGoesToA4(): void
    {
        $result = $this->calculator()->calculate([$this->doc()]);

        $this->assertCount(1, $result['sections']['A4']);
        $this->assertSame([], $result['sections']['A5']);
        $row = $result['sections']['A4'][0];
        // Ev. číslo A4 = vlastní číslo dokladu; DPPD s fallbackem na DUZP zde přímé.
        $this->assertSame('FV-001', $row['evidNumber']);
        $this->assertSame('CZ12345678', $row['vatId']);
        $this->assertSame('2026-01-12', $row['dppd']);
        $this->assertNull($row['kodPredPl']);
        $this->assertSame(10000.0, $row['base1']);
        $this->assertSame(2100.0, $row['tax1']);
        $this->assertSame([], $result['errors']);
    }

    public function testOutputOverLimitWithoutCzVatIdGoesToA5(): void
    {
        foreach (['', 'DE811111111'] as $vatId) {
            $result = $this->calculator()->calculate([$this->doc(['customer_vat_id' => $vatId])]);
            $this->assertSame([], $result['sections']['A4'], "DIČ '{$vatId}'");
            $this->assertCount(1, $result['sections']['A5'], "DIČ '{$vatId}'");
        }
    }

    public function testLimitIsStrictAndInclTax(): void
    {
        // Přesně 10 000,00 vč. daně → pod limit (strict >) → A5.
        $exact = $this->doc(['total_amount_dom' => 10000.0]);
        $result = $this->calculator()->calculate([$exact]);
        $this->assertSame([], $result['sections']['A4']);
        $this->assertCount(1, $result['sections']['A5']);

        // 10 000,01 → A4.
        $over = $this->doc(['total_amount_dom' => 10000.01]);
        $result = $this->calculator()->calculate([$over]);
        $this->assertCount(1, $result['sections']['A4']);
    }

    public function testLimitUsesAbsoluteValueForCreditNotes(): void
    {
        // Dobropis −12 100 vč. daně → abs nad limit → A4.
        $credit = $this->doc([
            'total_amount_dom' => -12100.0,
            'recap' => [['vat_code' => 'cz-120', 'base_dom' => -10000.0, 'tax_dom' => -2100.0]],
        ]);
        $result = $this->calculator()->calculate([$credit]);
        $this->assertCount(1, $result['sections']['A4']);
        $this->assertSame(-10000.0, $result['sections']['A4'][0]['base1']);
    }

    // ── B2/B3: limit bez testu DIČ + měkké chyby ───────────────────────────

    public function testInputOverLimitGoesToB2EvenWithoutCzVatId(): void
    {
        $doc = $this->doc([
            'customer_vat_id' => '',
            'supplier_vat_id' => 'DE811111111',
            'recap' => [['vat_code' => 'cz-110', 'base_dom' => 10000.0, 'tax_dom' => 2100.0]],
        ]);
        $result = $this->calculator()->calculate([$doc]);

        $this->assertCount(1, $result['sections']['B2']);
        // Ev. číslo B2 = číslo dokladu dodavatele.
        $this->assertSame('DOD-99', $result['sections']['B2'][0]['evidNumber']);
        $this->assertSame('DE811111111', $result['sections']['B2'][0]['vatId']);
        $this->assertSame([], $result['errors']);
    }

    public function testB2SoftErrors(): void
    {
        $doc = $this->doc([
            'supplier_vat_id'    => '',
            'partner_doc_number' => '',
            'recap' => [['vat_code' => 'cz-110', 'base_dom' => 10000.0, 'tax_dom' => 2100.0]],
        ]);
        $result = $this->calculator()->calculate([$doc]);

        $codes = array_column($result['errors'], 'code');
        $this->assertContains('missingVatId', $codes);
        $this->assertContains('missingPartnerDocNumber', $codes);
        $this->assertSame('B2', $result['errors'][0]['section']);
    }

    public function testInputUnderLimitAggregatesToB3(): void
    {
        $docA = $this->doc([
            'id' => 1, 'total_amount_dom' => 6050.0,
            'recap' => [['vat_code' => 'cz-110', 'base_dom' => 5000.0, 'tax_dom' => 1050.0]],
        ]);
        $docB = $this->doc([
            'id' => 2, 'total_amount_dom' => 1210.0,
            'recap' => [['vat_code' => 'cz-110', 'base_dom' => 1000.0, 'tax_dom' => 210.0]],
        ]);
        $result = $this->calculator()->calculate([$docA, $docB]);

        $this->assertSame([], $result['sections']['B2']);
        $this->assertCount(1, $result['sections']['B3'], 'B3 = jeden agregátní řádek');
        $aggregate = $result['sections']['B3'][0];
        $this->assertNull($aggregate['evidNumber']);
        $this->assertSame(6000.0, $aggregate['base1']);
        $this->assertSame(1260.0, $aggregate['tax1']);
    }

    // ── A1/B1: PDP kódy ─────────────────────────────────────────────────────

    /**
     * Předmět plnění nese řádek rekapitulace dokladu (#89 D13): tři předměty
     * na jednom dokladu a kódu = tři řádky B1, kód je řetězec (i s písmenem).
     */
    public function testB1RowPerSubjectFromRecap(): void
    {
        $doc = $this->doc([
            'total_amount_dom' => 60000.0,
            'recap' => [
                ['vat_code' => 'cz-115', 'base_dom' => 20000.0, 'tax_dom' => 4200.0, 'reverse_charge_code' => '4'],
                // párový řádek samovyměření — kh null, do KH nevstupuje (i s předmětem)
                ['vat_code' => 'cz-203', 'base_dom' => 20000.0, 'tax_dom' => 4200.0, 'reverse_charge_code' => '4'],
                ['vat_code' => 'cz-115', 'base_dom' => 10000.0, 'tax_dom' => 2100.0, 'reverse_charge_code' => '4a'],
                ['vat_code' => 'cz-203', 'base_dom' => 10000.0, 'tax_dom' => 2100.0, 'reverse_charge_code' => '4a'],
                ['vat_code' => 'cz-115', 'base_dom' => 30000.0, 'tax_dom' => 6300.0, 'reverse_charge_code' => '14'],
                ['vat_code' => 'cz-203', 'base_dom' => 30000.0, 'tax_dom' => 6300.0, 'reverse_charge_code' => '14'],
            ],
        ]);
        $result = $this->calculator()->calculate([$doc]);

        $this->assertCount(3, $result['sections']['B1']);
        $byKod = [];
        foreach ($result['sections']['B1'] as $row) {
            $this->assertIsString($row['kodPredPl']);
            $byKod[$row['kodPredPl']] = $row;
        }
        $this->assertSame(['4', '4a', '14'], array_map('strval', array_keys($byKod)));
        $this->assertSame(20000.0, $byKod['4']['base1']);
        $this->assertSame(4200.0, $byKod['4']['tax1']);
        $this->assertSame(10000.0, $byKod['4a']['base1']);
        $this->assertSame(30000.0, $byKod['14']['base1']);
        $this->assertSame('DOD-99', $byKod['4a']['evidNumber']);
    }

    /** Dva řádky rekapitulace se stejným předmětem (základní + snížená sazba) = jeden řádek B1. */
    public function testB1SameSubjectAcrossCodesIsOneRow(): void
    {
        $doc = $this->doc([
            'recap' => [
                ['vat_code' => 'cz-115', 'base_dom' => 20000.0, 'tax_dom' => 4200.0, 'reverse_charge_code' => '4'],
                ['vat_code' => 'cz-117', 'base_dom' => 10000.0, 'tax_dom' => 2100.0, 'reverse_charge_code' => '4'],
            ],
        ]);
        $rows = $this->calculator()->calculate([$doc])['sections']['B1'];

        $this->assertCount(1, $rows);
        $this->assertSame('4', $rows[0]['kodPredPl']);
        $this->assertSame(30000.0, $rows[0]['base1']);
    }

    /**
     * Řádek rekapitulace s kódem přenesení bez předmětu (starý nebo
     * rozpracovaný doklad): žádná výchozí hodnota — řádek KH zůstane bez
     * kódu a podání na něm skončí chybou validace.
     */
    public function testRecapWithoutSubjectHasNoDefaultCode(): void
    {
        $doc = $this->doc([
            'recap' => [['vat_code' => 'cz-115', 'base_dom' => 20000.0, 'tax_dom' => 4200.0]],
        ]);
        $rows = $this->calculator()->calculate([$doc])['sections']['B1'];

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['kodPredPl']);
    }

    /** Předmět na řádku mimo A1 / B1 se do KH nepropisuje. */
    public function testSubjectOutsideReverseChargeSectionsIsIgnored(): void
    {
        $doc = $this->doc([
            'recap' => [['vat_code' => 'cz-120', 'base_dom' => 10000.0, 'tax_dom' => 2100.0, 'reverse_charge_code' => '4']],
        ]);
        $result = $this->calculator()->calculate([$doc]);

        $this->assertNull($result['sections']['A4'][0]['kodPredPl']);
    }

    public function testA1CarriesBaseOnly(): void
    {
        // Výstupní PDP (cz-150): daň odvádí zákazník — recap má tax 0.
        $doc = $this->doc([
            'recap' => [['vat_code' => 'cz-150', 'base_dom' => 50000.0, 'tax_dom' => 0.0, 'reverse_charge_code' => '4']],
            'total_amount_dom' => 50000.0,
        ]);
        $result = $this->calculator()->calculate([$doc]);

        $this->assertCount(1, $result['sections']['A1']);
        $row = $result['sections']['A1'][0];
        $this->assertSame('4', $row['kodPredPl']);
        $this->assertSame('FV-001', $row['evidNumber']);
        $this->assertSame(50000.0, $row['base1']);
        $this->assertSame(0.0, $row['tax1']);
    }

    public function testA2UsesSupplierVatId(): void
    {
        // A2 z párového řádku samovyměření EU pořízení (cz-205).
        $doc = $this->doc([
            'recap' => [['vat_code' => 'cz-205', 'base_dom' => 8000.0, 'tax_dom' => 1680.0]],
        ]);
        $result = $this->calculator()->calculate([$doc]);

        $this->assertCount(1, $result['sections']['A2']);
        $this->assertSame('CZ87654321', $result['sections']['A2'][0]['vatId']);
        $this->assertSame(8000.0, $result['sections']['A2'][0]['base1']);
    }

    // ── Sazbová pásma + DPPD fallback ───────────────────────────────────────

    public function testRateBands(): void
    {
        $doc = $this->doc([
            'customer_vat_id' => 'CZ12345678',
            'total_amount_dom' => 50000.0,
            'recap' => [
                ['vat_code' => 'cz-120', 'base_dom' => 1000.0, 'tax_dom' => 210.0],   // standard → 1
                ['vat_code' => 'cz-121', 'base_dom' => 2000.0, 'tax_dom' => 240.0],   // reduced → 2
                ['vat_code' => 'cz-310', 'base_dom' => 3000.0, 'tax_dom' => 450.0],   // reduced1 → 2
                ['vat_code' => 'cz-311', 'base_dom' => 4000.0, 'tax_dom' => 400.0],   // reduced2 → 3
                ['vat_code' => 'cz-122', 'base_dom' => 5000.0, 'tax_dom' => 0.0],     // zero → mimo
                ['vat_code' => 'cz-123', 'base_dom' => 6000.0, 'tax_dom' => 0.0],     // exempt → kh null
            ],
        ]);
        $result = $this->calculator()->calculate([$doc]);

        $this->assertCount(1, $result['sections']['A4']);
        $row = $result['sections']['A4'][0];
        $this->assertSame(1000.0, $row['base1']);
        $this->assertSame(210.0, $row['tax1']);
        $this->assertSame(5000.0, $row['base2'], 'reduced + reduced1 sdílejí pásmo 2');
        $this->assertSame(690.0, $row['tax2']);
        $this->assertSame(4000.0, $row['base3']);
        $this->assertSame(400.0, $row['tax3']);
    }

    public function testDppdFallsBackToDuzp(): void
    {
        $doc = $this->doc(['vat_dppd' => null]);
        $result = $this->calculator()->calculate([$doc]);
        $this->assertSame('2026-01-10', $result['sections']['A4'][0]['dppd']);
    }

    // ── Datum per sekce (#55 X12) ───────────────────────────────────────

    /**
     * Režim přenesení daňové povinnosti se vykazuje k DUZP, ne k DPPD —
     * formulář i XML mají u sekcí A.1 a B.1 atribut `duzp`.
     */
    public function testReverseChargeSectionsReportDuzp(): void
    {
        $supplier = $this->doc([
            'recap' => [['vat_code' => 'cz-150', 'base_dom' => 50000.0, 'tax_dom' => 0.0]],
        ]);
        $customer = $this->doc([
            'id'    => 2,
            'recap' => [['vat_code' => 'cz-115', 'base_dom' => 50000.0, 'tax_dom' => 10500.0]],
        ]);

        $result = $this->calculator()->calculate([$supplier, $customer]);

        $this->assertSame('2026-01-10', $result['sections']['A1'][0]['dppd'], 'A1 vykazuje DUZP');
        $this->assertSame('2026-01-10', $result['sections']['B1'][0]['dppd'], 'B1 vykazuje DUZP');
    }

    public function testOtherSectionsKeepReportingDppd(): void
    {
        $result = $this->calculator()->calculate([$this->doc()]);
        $this->assertSame('2026-01-12', $result['sections']['A4'][0]['dppd']);
    }

    /** Chybějící DUZP u sekce A.1 spadne na DPPD — datum tam být musí. */
    public function testDuzpFallsBackToDppd(): void
    {
        $doc = $this->doc([
            'vat_duzp' => null,
            'recap'    => [['vat_code' => 'cz-150', 'base_dom' => 50000.0, 'tax_dom' => 0.0]],
        ]);
        $result = $this->calculator()->calculate([$doc]);
        $this->assertSame('2026-01-12', $result['sections']['A1'][0]['dppd']);
    }
}
