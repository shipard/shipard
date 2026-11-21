<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Vat\RecapitulativeStatementCalculator;
use Shipard\Module\Economy\Vat\VatOutputsMapping;

class RecapitulativeStatementCalculatorTest extends TestCase
{
    private function calculator(): RecapitulativeStatementCalculator
    {
        return new RecapitulativeStatementCalculator(new VatOutputsMapping(['vatOutputs' => [
            'cz-201' => ['dp3' => ['row' => 20], 'kh' => null, 'sh' => ['kod' => 0]],
            'cz-202' => ['dp3' => ['row' => 21], 'kh' => null, 'sh' => ['kod' => 3]],
            'cz-120' => ['dp3' => ['row' => 1], 'kh' => null, 'sh' => null],
            'cz-221' => ['dp3' => ['row' => 31], 'kh' => null, 'sh' => ['kod' => 2]],
            'cz-209' => ['dp3' => ['row' => 26], 'kh' => null, 'sh' => null],
        ]]));
    }

    /** @return array<string, mixed> */
    private function doc(int $id, string $vatId, array $recap): array
    {
        return ['id' => $id, 'doc_number' => "FV-{$id}", 'customer_vat_id' => $vatId, 'recap' => $recap];
    }

    public function testAggregatesPerKodAndVatId(): void
    {
        $result = $this->calculator()->calculate([
            $this->doc(1, 'DE811111111', [
                ['vat_code' => 'cz-201', 'base_dom' => 1000.0, 'tax_dom' => 0.0],
                ['vat_code' => 'cz-202', 'base_dom' => 500.0, 'tax_dom' => 0.0],
                ['vat_code' => 'cz-120', 'base_dom' => 99.0, 'tax_dom' => 20.79], // sh null → mimo SH
            ]),
            $this->doc(2, 'DE811111111', [
                ['vat_code' => 'cz-201', 'base_dom' => 2000.0, 'tax_dom' => 0.0],
            ]),
            $this->doc(3, 'ATU12345678', [
                ['vat_code' => 'cz-201', 'base_dom' => 300.0, 'tax_dom' => 0.0],
            ]),
        ]);

        // Řazení dle (DIČ, kód); počet plnění = počet recap řádků.
        $this->assertSame([
            ['kod' => 0, 'vatId' => 'ATU12345678', 'count' => 1, 'value' => 300.0],
            ['kod' => 0, 'vatId' => 'DE811111111', 'count' => 2, 'value' => 3000.0],
            ['kod' => 3, 'vatId' => 'DE811111111', 'count' => 1, 'value' => 500.0],
        ], $result['rows']);
        $this->assertSame([], $result['errors']);
    }

    /** Třístranný obchod prostřední osobou jde do hlášení s kódem 2; ř. 26 přiznání ne (#164 D8). */
    public function testTriangularTradeIsReportedWithCodeTwo(): void
    {
        $result = $this->calculator()->calculate([
            $this->doc(1, 'DE811111111', [
                ['vat_code' => 'cz-221', 'base_dom' => 4000.0, 'tax_dom' => 0.0],
                ['vat_code' => 'cz-201', 'base_dom' => 1000.0, 'tax_dom' => 0.0],
                ['vat_code' => 'cz-209', 'base_dom' => 700.0, 'tax_dom' => 0.0], // sh null → mimo SH
            ]),
        ]);

        $this->assertSame([
            ['kod' => 0, 'vatId' => 'DE811111111', 'count' => 1, 'value' => 1000.0],
            ['kod' => 2, 'vatId' => 'DE811111111', 'count' => 1, 'value' => 4000.0],
        ], $result['rows']);
        $this->assertSame([], $result['errors']);
    }

    public function testMissingVatIdIsSoftError(): void
    {
        $result = $this->calculator()->calculate([
            $this->doc(7, '', [['vat_code' => 'cz-201', 'base_dom' => 100.0, 'tax_dom' => 0.0]]),
        ]);

        $this->assertCount(1, $result['rows'], 'řádek se agreguje i bez DIČ');
        $this->assertSame('', $result['rows'][0]['vatId']);
        $this->assertSame(
            [['code' => 'missingVatId', 'docId' => 7, 'docNumber' => 'FV-7']],
            $result['errors'],
        );
    }

    public function testEmptySelection(): void
    {
        $this->assertSame(
            ['rows' => [], 'errors' => []],
            $this->calculator()->calculate([]),
        );
    }
}
