<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat\Dataset;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Vat\Dataset\VatExpectations;

class VatExpectationsTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function records(): array
    {
        return [
            [
                'messageId' => 'A', 'variant' => 'domestic-goods', 'currency' => 'CZK', 'period' => '2026-08',
                'return' => ['40' => ['base' => 16530.45, 'tax' => 3471.39]],
                'controlStatement' => ['section' => 'B.2', 'supplierVatId' => 'cz 43075894', 'documentNumber' => 'FV-1',
                    'taxPointDate' => '2026-08-25', 'rates' => ['21' => ['base' => 16530.45, 'tax' => 3471.39]], 'reverseChargeCode' => null],
            ],
            [
                'messageId' => 'B', 'variant' => 'domestic-two-rates', 'currency' => 'CZK', 'period' => '2026-08',
                'return' => ['40' => ['base' => 3670, 'tax' => 770.7], '41' => ['base' => 1212, 'tax' => 145.44]],
                'controlStatement' => ['section' => 'B.3', 'supplierVatId' => 'CZ56546939', 'documentNumber' => '2026/0882',
                    'taxPointDate' => '2026-08-20', 'rates' => ['21' => ['base' => 3670, 'tax' => 770.7], '12' => ['base' => 1212, 'tax' => 145.44]], 'reverseChargeCode' => null],
            ],
            [
                'messageId' => 'C', 'variant' => 'domestic-reverse-charge', 'currency' => 'CZK', 'period' => '2026-09',
                'return' => ['10' => ['base' => 67720, 'tax' => 14221.2], '43' => ['base' => 67720, 'tax' => 14221.2]],
                'controlStatement' => ['section' => 'B.1', 'supplierVatId' => 'CZ16612248', 'documentNumber' => '2026-0163',
                    'taxPointDate' => '2026-09-17', 'rates' => ['21' => ['base' => 67720, 'tax' => 14221.2]], 'reverseChargeCode' => '4'],
            ],
            ['messageId' => 'D', 'variant' => 'domestic-non-payer-supplier', 'currency' => 'CZK', 'period' => null, 'return' => [], 'controlStatement' => null],
            [
                'messageId' => 'E', 'variant' => 'eu-services', 'currency' => 'EUR', 'period' => '2026-08',
                'return' => ['5' => ['base' => 432.7, 'tax' => 90.87], '43' => ['base' => 432.7, 'tax' => 90.87]],
                'controlStatement' => ['section' => 'A.2', 'supplierVatId' => 'DE383584058', 'documentNumber' => 'INV-1',
                    'taxPointDate' => '2026-08-31', 'rates' => ['21' => ['base' => 432.7, 'tax' => 90.87]], 'reverseChargeCode' => null],
            ],
            [
                'messageId' => 'F', 'variant' => 'receipt', 'currency' => 'CZK', 'period' => '2026-08',
                'return' => ['40' => ['base' => 1313.97, 'tax' => 275.93]],
                'controlStatement' => ['section' => 'B.3', 'supplierVatId' => 'CZ40488004', 'documentNumber' => '0004712',
                    'taxPointDate' => '2026-08-03', 'rates' => ['21' => ['base' => 1313.97, 'tax' => 275.93]], 'reverseChargeCode' => null],
            ],
        ];
    }

    public function testAggregatesOnlyAppliedByPeriod(): void
    {
        $agg = VatExpectations::aggregate(self::records(), ['A', 'B', 'C', 'D', 'F']);   // E (EUR) přeskočeno
        $this->assertSame(['2026-08', '2026-09'], array_keys($agg));

        $aug = $agg['2026-08'];
        $this->assertEqualsWithDelta(16530.45 + 3670 + 1313.97, $aug['return']['40']['base'], 0.001);
        $this->assertEqualsWithDelta(3471.39 + 770.7 + 275.93, $aug['return']['40']['tax'], 0.001);
        $this->assertEqualsWithDelta(1212.0, $aug['return']['41']['base'], 0.001);
        $this->assertArrayNotHasKey('5', $aug['return'], 'přeskočený doklad v cizí měně do očekávání nejde');
        $this->assertSame(['B2'], array_keys($aug['items']));
        $item = $aug['items']['B2']['CZ43075894|FV-1'];
        $this->assertSame('CZ43075894', $item['vatId'], 'DIČ bez mezer velkými');
        $this->assertSame('2026-08-25', $item['taxPointDate']);
        $this->assertSame([21 => ['base' => 16530.45, 'tax' => 3471.39]], $item['rates']);
        $this->assertSame(['B3'], array_keys($aug['sums']));
        $this->assertEqualsWithDelta(3670 + 1313.97, $aug['sums']['B3'][21]['base'], 0.001);
        $this->assertEqualsWithDelta(1212.0, $aug['sums']['B3'][12]['base'], 0.001);

        $sep = $agg['2026-09'];
        $this->assertSame([10, 43], array_keys($sep['return']), 'číselné klíče řádků dělá PHP z int');
        $this->assertSame('4', $sep['items']['B1']['CZ16612248|2026-0163']['reverseChargeCode']);
        $this->assertSame([], $sep['sums']);
    }

    public function testNothingAppliedGivesNothing(): void
    {
        $this->assertSame([], VatExpectations::aggregate(self::records(), ['D']));
        $this->assertSame([], VatExpectations::aggregate(self::records(), []));
    }

    public function testHelpers(): void
    {
        $this->assertSame('B2', VatExpectations::sectionCode('B.2'));
        $this->assertSame('A2', VatExpectations::sectionCode(' a.2 '));
        $this->assertSame('CZ43075894|FV-1', VatExpectations::itemKey('cz 430 75894', ' FV-1 '));
        $this->assertSame(1, VatExpectations::bandForRate(21));
        $this->assertSame(2, VatExpectations::bandForRate(12));
        $this->assertNull(VatExpectations::bandForRate(19));
    }
}
