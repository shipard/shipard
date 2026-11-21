<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Vat\VatOutputsMapping;
use Shipard\Module\Economy\Vat\VatReturnCalculator;

/**
 * Dopočty DPHDP3 (46, 52, 62–65) na syntetických datech.
 */
class VatReturnCalculatorTest extends TestCase
{
    private function calculator(): VatReturnCalculator
    {
        return new VatReturnCalculator(new VatOutputsMapping(['vatOutputs' => [
            'cz-120' => ['dp3' => ['row' => 1], 'kh' => null, 'sh' => null],
            'cz-121' => ['dp3' => ['row' => 2], 'kh' => null, 'sh' => null],
            'cz-110' => ['dp3' => ['row' => 40, 'col' => 'full'], 'kh' => null, 'sh' => null],
            'cz-118' => ['dp3' => ['row' => 40, 'col' => 'reduced'], 'kh' => null, 'sh' => null],
            'cz-111' => ['dp3' => ['row' => 41, 'col' => 'full'], 'kh' => null, 'sh' => null],
            'cz-115' => ['dp3' => ['row' => 43, 'col' => 'full'], 'kh' => null, 'sh' => null],
            'cz-203' => ['dp3' => ['row' => 10], 'kh' => null, 'sh' => null],
            'cz-201' => ['dp3' => ['row' => 20], 'kh' => null, 'sh' => null],
            'cz-114' => ['dp3' => null, 'kh' => null, 'sh' => null],
            'cz-402' => ['dp3' => ['row' => 26], 'kh' => null, 'sh' => null],
            'cz-220' => ['dp3' => ['row' => 30], 'kh' => null, 'sh' => null],
            'cz-221' => ['dp3' => ['row' => 31], 'kh' => null, 'sh' => ['kod' => 2]],
        ]]));
    }

    /** @return array<string, mixed> */
    private function doc(array $recap, int $id = 1): array
    {
        return ['id' => $id, 'doc_number' => "D-{$id}", 'recap' => $recap];
    }

    public function testRowsSumPerRowAndColumn(): void
    {
        $result = $this->calculator()->calculate([
            $this->doc([
                ['vat_code' => 'cz-120', 'base_dom' => 1000.0, 'tax_dom' => 210.0],
                ['vat_code' => 'cz-120', 'base_dom' => 500.0, 'tax_dom' => 105.0],
                ['vat_code' => 'cz-121', 'base_dom' => 200.0, 'tax_dom' => 24.0],
                ['vat_code' => 'cz-114', 'base_dom' => 999.0, 'tax_dom' => 0.0], // dp3 null → mimo
            ]),
            $this->doc([
                ['vat_code' => 'cz-110', 'base_dom' => 2000.0, 'tax_dom' => 420.0],
                ['vat_code' => 'cz-118', 'base_dom' => 300.0, 'tax_dom' => 63.0],
            ], 2),
        ]);

        $this->assertSame(
            ['base' => 1500.0, 'taxFull' => 315.0, 'taxReduced' => 0.0],
            $result['rows'][1],
        );
        $this->assertSame(
            ['base' => 200.0, 'taxFull' => 24.0, 'taxReduced' => 0.0],
            $result['rows'][2],
        );
        // Řádek 40: plný i krácený sloupec, základ dohromady.
        $this->assertSame(
            ['base' => 2300.0, 'taxFull' => 420.0, 'taxReduced' => 63.0],
            $result['rows'][40],
        );
        $this->assertArrayNotHasKey(20, $result['rows']);
        // Řádky vzestupně.
        $this->assertSame([1, 2, 40], array_keys($result['rows']));
    }

    public function testComputedRow46SumsDeductions(): void
    {
        $result = $this->calculator()->calculate([
            $this->doc([
                ['vat_code' => 'cz-110', 'base_dom' => 1000.0, 'tax_dom' => 210.0],
                ['vat_code' => 'cz-118', 'base_dom' => 500.0, 'tax_dom' => 105.0],
                ['vat_code' => 'cz-115', 'base_dom' => 2000.0, 'tax_dom' => 420.0],
            ]),
        ]);

        $this->assertSame(
            ['base' => 3500.0, 'taxFull' => 630.0, 'taxReduced' => 105.0],
            $result['computed'][46],
        );
    }

    /** Ř. 26, 30 a 31 jsou doplňující údaje — do součtů 46, 62 a 63 nevstupují (#164 U12). */
    public function testSupplementaryRowsStayOutOfComputedTotals(): void
    {
        $plain = [
            ['vat_code' => 'cz-120', 'base_dom' => 1000.0, 'tax_dom' => 210.0],
            ['vat_code' => 'cz-110', 'base_dom' => 400.0, 'tax_dom' => 84.0],
        ];
        $supplementary = [
            ['vat_code' => 'cz-402', 'base_dom' => 5000.0, 'tax_dom' => 0.0],
            ['vat_code' => 'cz-220', 'base_dom' => 3000.0, 'tax_dom' => 0.0],
            ['vat_code' => 'cz-221', 'base_dom' => 3500.0, 'tax_dom' => 0.0],
        ];

        $without = $this->calculator()->calculate([$this->doc($plain)]);
        $with = $this->calculator()->calculate([$this->doc($plain), $this->doc($supplementary, 2)]);

        $this->assertSame(['base' => 5000.0, 'taxFull' => 0.0, 'taxReduced' => 0.0], $with['rows'][26]);
        $this->assertSame(['base' => 3000.0, 'taxFull' => 0.0, 'taxReduced' => 0.0], $with['rows'][30]);
        $this->assertSame(['base' => 3500.0, 'taxFull' => 0.0, 'taxReduced' => 0.0], $with['rows'][31]);
        $this->assertSame($without['computed'], $with['computed']);
    }

    public function testOwnTaxLiability(): void
    {
        // Výstup 1000/210 (ř. 1) + samovyměření 500/105 (ř. 10) proti
        // odpočtu 400/84 (ř. 40): 62 = 315, 63 = 84 → vlastní daň 231.
        $result = $this->calculator()->calculate([
            $this->doc([
                ['vat_code' => 'cz-120', 'base_dom' => 1000.0, 'tax_dom' => 210.0],
                ['vat_code' => 'cz-203', 'base_dom' => 500.0, 'tax_dom' => 105.0],
                ['vat_code' => 'cz-110', 'base_dom' => 400.0, 'tax_dom' => 84.0],
            ]),
        ]);

        $this->assertSame(315.0, $result['computed'][62]['taxFull']);
        $this->assertSame(84.0, $result['computed'][63]['taxFull']);
        $this->assertSame(231.0, $result['computed'][64]['taxFull'], 'vlastní daň');
        $this->assertSame(0.0, $result['computed'][65]['taxFull']);
    }

    public function testExcessDeduction(): void
    {
        // Odpočet převyšuje daň na výstupu → nadměrný odpočet na ř. 65.
        $result = $this->calculator()->calculate([
            $this->doc([
                ['vat_code' => 'cz-120', 'base_dom' => 100.0, 'tax_dom' => 21.0],
                ['vat_code' => 'cz-110', 'base_dom' => 1000.0, 'tax_dom' => 210.0],
            ]),
        ]);

        $this->assertSame(21.0, $result['computed'][62]['taxFull']);
        $this->assertSame(210.0, $result['computed'][63]['taxFull']);
        $this->assertSame(0.0, $result['computed'][64]['taxFull']);
        $this->assertSame(189.0, $result['computed'][65]['taxFull'], 'nadměrný odpočet');
    }

    public function testReducedDeductionEntersRow63ViaRow52(): void
    {
        // Krácený odpočet (ř. 40 sloupec krácený) × default koeficient 1,00
        // → ř. 52 = celý krácený nárok, 63 = 46 (plný) + 52.
        $result = $this->calculator()->calculate([
            $this->doc([
                ['vat_code' => 'cz-120', 'base_dom' => 1000.0, 'tax_dom' => 210.0],
                ['vat_code' => 'cz-118', 'base_dom' => 500.0, 'tax_dom' => 105.0],
            ]),
        ]);

        $this->assertSame(105.0, $result['computed'][46]['taxReduced']);
        $this->assertSame(['base' => 0.0, 'taxFull' => 105.0, 'taxReduced' => 0.0], $result['computed'][52]);
        $this->assertSame(105.0, $result['computed'][63]['taxFull']);
        $this->assertSame(105.0, $result['computed'][64]['taxFull']);
        $this->assertSame([46, 52, 62, 63, 64, 65], array_keys($result['computed']));
    }

    /** @return list<array<string, mixed>> Fixture 04/2026 (#59 D13): ř. 40 plný + krácený, 41, 43, výstup ř. 1. */
    private function fixtureApril2026(): array
    {
        return [
            $this->doc([
                ['vat_code' => 'cz-120', 'base_dom' => 1307627.81, 'tax_dom' => 274601.84],
                ['vat_code' => 'cz-110', 'base_dom' => 434209.81, 'tax_dom' => 91184.06],
                ['vat_code' => 'cz-118', 'base_dom' => 188.43, 'tax_dom' => 39.57],
                ['vat_code' => 'cz-111', 'base_dom' => 229782.25, 'tax_dom' => 27573.87],
                ['vat_code' => 'cz-115', 'base_dom' => 58191.14, 'tax_dom' => 12220.14],
            ]),
        ];
    }

    public function testApril2026WithDefaultCoefficient(): void
    {
        $result = $this->calculator()->calculate($this->fixtureApril2026());

        $this->assertSame(39.57, $result['rows'][40]['taxReduced']);
        $this->assertSame(39.57, $result['computed'][52]['taxFull']);
        $this->assertSame(130978.07, $result['computed'][46]['taxFull']);
        $this->assertSame(131017.64, $result['computed'][63]['taxFull']);
        $this->assertSame(274601.84, $result['computed'][62]['taxFull']);
        $this->assertSame(143584.20, $result['computed'][64]['taxFull'], 'vlastní daň = podané tvrzení (před zaokrouhlením na Kč)');
        $this->assertSame(0.0, $result['computed'][65]['taxFull']);
    }

    public function testApril2026WithCoefficient080(): void
    {
        $result = $this->calculator()->calculate($this->fixtureApril2026(), 0.80);

        $this->assertSame(31.66, $result['computed'][52]['taxFull'], '39,57 × 0,80 = 31,656 → 31,66');
        $this->assertSame(131009.73, $result['computed'][63]['taxFull']);
        $this->assertSame(143592.11, $result['computed'][64]['taxFull']);
        // Krácený sloupec ř. 40 zůstává vykázaný v plné výši (formulář ho má).
        $this->assertSame(39.57, $result['rows'][40]['taxReduced']);
        $this->assertSame(39.57, $result['computed'][46]['taxReduced']);
    }

    public function testCoefficientWithoutReducedDeductionIsNoop(): void
    {
        $result = $this->calculator()->calculate([
            $this->doc([
                ['vat_code' => 'cz-120', 'base_dom' => 1000.0, 'tax_dom' => 210.0],
                ['vat_code' => 'cz-110', 'base_dom' => 400.0, 'tax_dom' => 84.0],
            ]),
        ], 0.5);

        $this->assertSame(0.0, $result['computed'][52]['taxFull']);
        $this->assertSame($result['computed'][46]['taxFull'], $result['computed'][63]['taxFull']);
    }

    public function testCoefficientOutsideUnitIntervalThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator()->calculate([], 1.5);
    }

    public function testBaseOnlyRowsDoNotAffectTax(): void
    {
        // Ř. 20 (dodání do EU) nese jen základ — 62/64 z něj nevzniká.
        $result = $this->calculator()->calculate([
            $this->doc([['vat_code' => 'cz-201', 'base_dom' => 9000.0, 'tax_dom' => 0.0]]),
        ]);

        $this->assertSame(['base' => 9000.0, 'taxFull' => 0.0, 'taxReduced' => 0.0], $result['rows'][20]);
        $this->assertSame(0.0, $result['computed'][62]['taxFull']);
        $this->assertSame(0.0, $result['computed'][64]['taxFull']);
        $this->assertSame(0.0, $result['computed'][65]['taxFull']);
    }

    public function testEmptySelection(): void
    {
        $result = $this->calculator()->calculate([]);

        $this->assertSame([], $result['rows']);
        $this->assertSame(0.0, $result['computed'][46]['taxFull']);
        $this->assertSame(0.0, $result['computed'][64]['taxFull']);
        $this->assertSame(0.0, $result['computed'][65]['taxFull']);
    }
}
