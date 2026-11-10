<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\Core\DocRowCalculator;

/**
 * Čistý výpočet řádku sdílený save cestou a živým přepočtem (#71).
 * Chování save cesty (obálky v DocDocument) hlídá DocDocumentRowCalcTest.
 */
class DocRowCalculatorTest extends TestCase
{
    // ── computePrice ───────────────────────────────────────────────────────

    public function testFromUnitPrice(): void
    {
        $p = DocRowCalculator::computePrice([
            'row_kind' => 1, 'quantity' => 2, 'unit_price' => 100, 'price_calc_mode' => 0,
        ]);

        $this->assertSame(100.0, $p['unit_price']);
        $this->assertSame(200.0, $p['total_price']);
        $this->assertSame(200.0, $p['net_total']);
    }

    public function testFromUnitPriceRoundsTotalToTwoDecimals(): void
    {
        $p = DocRowCalculator::computePrice([
            'row_kind' => 1, 'quantity' => 3, 'unit_price' => 33.333, 'price_calc_mode' => 0,
        ]);

        $this->assertSame(100.0, $p['total_price']);
    }

    public function testFromTotalPrice(): void
    {
        $p = DocRowCalculator::computePrice([
            'row_kind' => 1, 'quantity' => 4, 'total_price' => 1000, 'price_calc_mode' => 1,
        ]);

        $this->assertSame(250.0, $p['unit_price']);
        $this->assertSame(1000.0, $p['total_price']);
        $this->assertSame(1000.0, $p['net_total']);
    }

    public function testFromTotalPriceRoundsUnitToFourDecimals(): void
    {
        $p = DocRowCalculator::computePrice([
            'row_kind' => 1, 'quantity' => 3, 'total_price' => 100, 'price_calc_mode' => 1,
        ]);

        $this->assertSame(33.3333, $p['unit_price']);
    }

    public function testFromTotalPriceWithZeroQuantityGivesZeroUnitPrice(): void
    {
        $p = DocRowCalculator::computePrice([
            'row_kind' => 1, 'quantity' => 0, 'total_price' => 100, 'price_calc_mode' => 1,
        ]);

        $this->assertSame(0.0, $p['unit_price']);
        $this->assertSame(100.0, $p['total_price']);
    }

    /** Množství chybí (kontační řádek, prázdné pole z klienta): cena za jednotku 0 jako u nuly. */
    public function testFromTotalPriceWithoutQuantityGivesZeroUnitPrice(): void
    {
        foreach ([null, '', '0'] as $quantity) {
            $p = DocRowCalculator::computePrice([
                'row_kind' => 1, 'quantity' => $quantity, 'total_price' => 100, 'price_calc_mode' => 1,
            ]);

            $this->assertSame(0.0, $p['unit_price'], var_export($quantity, true));
            $this->assertSame(100.0, $p['total_price'], var_export($quantity, true));
        }
    }

    /** Dobropis, vratka (#98): záporné množství cenu za jednotku dopočítá, ne vynuluje. */
    public function testFromTotalPriceWithNegativeQuantityAndTotalGivesPositiveUnitPrice(): void
    {
        $p = DocRowCalculator::computePrice([
            'row_kind' => 1, 'quantity' => -2, 'total_price' => -100, 'price_calc_mode' => 1,
        ]);

        $this->assertSame(50.0, $p['unit_price']);
        $this->assertSame(-100.0, $p['total_price']);
        $this->assertSame(-100.0, $p['net_total']);
    }

    /** Znaménka se neupravují, platí prosté dělení. */
    public function testFromTotalPriceNegativeTotalWithPositiveQuantity(): void
    {
        $p = DocRowCalculator::computePrice([
            'row_kind' => 1, 'quantity' => 2, 'total_price' => -100, 'price_calc_mode' => 1,
        ]);

        $this->assertSame(-50.0, $p['unit_price']);
    }

    public function testFromTotalPricePositiveTotalWithNegativeQuantity(): void
    {
        $p = DocRowCalculator::computePrice([
            'row_kind' => 1, 'quantity' => -2, 'total_price' => 100, 'price_calc_mode' => 1,
        ]);

        $this->assertSame(-50.0, $p['unit_price']);
    }

    public function testFromTotalPriceWithNegativeQuantityRoundsUnitToFourDecimals(): void
    {
        $p = DocRowCalculator::computePrice([
            'row_kind' => 1, 'quantity' => -3, 'total_price' => -100, 'price_calc_mode' => 1,
        ]);

        $this->assertSame(33.3333, $p['unit_price']);
    }

    public function testDiscountPctKeepsTotalPriceBeforeDiscount(): void
    {
        // Past P1: total_price před slevou, net_total po slevě.
        $p = DocRowCalculator::computePrice([
            'row_kind' => 1, 'quantity' => 1, 'unit_price' => 1000, 'price_calc_mode' => 0,
            'discount_pct' => 10,
        ]);

        $this->assertSame(1000.0, $p['total_price']);
        $this->assertSame(900.0, $p['net_total']);
    }

    public function testDiscountAmount(): void
    {
        $p = DocRowCalculator::computePrice([
            'row_kind' => 1, 'quantity' => 1, 'unit_price' => 1000, 'price_calc_mode' => 0,
            'discount_amount' => 250,
        ]);

        $this->assertSame(1000.0, $p['total_price']);
        $this->assertSame(750.0, $p['net_total']);
    }

    public function testDiscountPctTakesPrecedenceOverAmount(): void
    {
        $p = DocRowCalculator::computePrice([
            'row_kind' => 1, 'quantity' => 1, 'unit_price' => 1000, 'price_calc_mode' => 0,
            'discount_pct' => 10, 'discount_amount' => 250,
        ]);

        $this->assertSame(900.0, $p['net_total']);
    }

    public function testDiscountZeroStringMeansNoDiscount(): void
    {
        $p = DocRowCalculator::computePrice([
            'row_kind' => 1, 'quantity' => 1, 'unit_price' => 1000, 'price_calc_mode' => 0,
            'discount_pct' => '0', 'discount_amount' => '',
        ]);

        $this->assertSame(1000.0, $p['net_total']);
    }

    public function testTextRowHasNoPrice(): void
    {
        $p = DocRowCalculator::computePrice([
            'row_kind' => 0, 'quantity' => 5, 'unit_price' => 100, 'total_price' => 500,
        ]);

        $this->assertNull($p['unit_price']);
        $this->assertNull($p['total_price']);
        $this->assertSame(0.0, $p['net_total']);
    }

    public function testStringInputsFromClient(): void
    {
        $p = DocRowCalculator::computePrice([
            'row_kind' => 1, 'quantity' => '3', 'unit_price' => '1500.5', 'price_calc_mode' => '0',
        ]);

        $this->assertSame(1500.5, $p['unit_price']);
        $this->assertSame(4501.5, $p['total_price']);
    }

    public function testEmptyUnitPriceStaysEmptyAndTotalIsZero(): void
    {
        $p = DocRowCalculator::computePrice([
            'row_kind' => 1, 'quantity' => '2', 'unit_price' => '', 'price_calc_mode' => 0,
        ]);

        $this->assertNull($p['unit_price']);
        $this->assertSame(0.0, $p['total_price']);
        $this->assertSame(0.0, $p['net_total']);
    }

    // ── computeVat ─────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    private function vatCodes(): array
    {
        return [
            'cz-115' => ['noPayTax' => 1, 'reverseVatCode' => 'cz-203'],
            'cz-150' => ['noPayTax' => 1],
            'cz-110' => [],
        ];
    }

    public function testVatModeNoVat(): void
    {
        $v = DocRowCalculator::computeVat(200.0, ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21], 0, null);

        $this->assertSame(['vat_base' => 200.0, 'vat_amount' => 0.0, 'vat_total' => 200.0], $v);
    }

    public function testVatModeFromBase(): void
    {
        $v = DocRowCalculator::computeVat(200.0, ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21], 1, null);

        $this->assertSame(['vat_base' => 200.0, 'vat_amount' => 42.0, 'vat_total' => 242.0], $v);
    }

    public function testVatModeFromTotal(): void
    {
        $v = DocRowCalculator::computeVat(242.0, ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21], 2, null);

        $this->assertSame(['vat_base' => 200.0, 'vat_amount' => 42.0, 'vat_total' => 242.0], $v);
    }

    public function testVatWithoutCodeIsBaseOnly(): void
    {
        $v = DocRowCalculator::computeVat(100.0, ['row_kind' => 1, 'vat_pct' => 21], 1, null);

        $this->assertSame(['vat_base' => 100.0, 'vat_amount' => 0.0, 'vat_total' => 100.0], $v);
    }

    public function testVatZeroPctStringIsNoTax(): void
    {
        $v = DocRowCalculator::computeVat(100.0, ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => '0'], 1, null);

        $this->assertSame(['vat_base' => 100.0, 'vat_amount' => 0.0, 'vat_total' => 100.0], $v);
    }

    public function testVatTextRowIsNull(): void
    {
        $v = DocRowCalculator::computeVat(100.0, ['row_kind' => 0], 1, null);

        $this->assertSame(['vat_base' => null, 'vat_amount' => null, 'vat_total' => null], $v);
    }

    public function testVatNoPayTaxWithReverseCodeCarriesAmountInBothModes(): void
    {
        $row = ['row_kind' => 1, 'vat_code' => 'cz-115', 'vat_pct' => 21];
        $expected = ['vat_base' => 1000.0, 'vat_amount' => 210.0, 'vat_total' => 1000.0];

        $this->assertSame($expected, DocRowCalculator::computeVat(1000.0, $row, 1, $this->vatCodes()));
        $this->assertSame($expected, DocRowCalculator::computeVat(1000.0, $row, 2, $this->vatCodes()));
    }

    public function testVatNoPayTaxWithoutReverseCodeHasZeroAmount(): void
    {
        $v = DocRowCalculator::computeVat(1900.0, ['row_kind' => 1, 'vat_code' => 'cz-150', 'vat_pct' => 21], 1, $this->vatCodes());

        $this->assertSame(['vat_base' => 1900.0, 'vat_amount' => 0.0, 'vat_total' => 1900.0], $v);
    }

    public function testVatOrdinaryCodeWithDefsUnchanged(): void
    {
        $v = DocRowCalculator::computeVat(200.0, ['row_kind' => 1, 'vat_code' => 'cz-110', 'vat_pct' => 21], 1, $this->vatCodes());

        $this->assertSame(['vat_base' => 200.0, 'vat_amount' => 42.0, 'vat_total' => 242.0], $v);
    }

    public function testVatNullDefsIgnoreNoPayTax(): void
    {
        $v = DocRowCalculator::computeVat(1000.0, ['row_kind' => 1, 'vat_code' => 'cz-115', 'vat_pct' => 21], 1, null);

        $this->assertSame(['vat_base' => 1000.0, 'vat_amount' => 210.0, 'vat_total' => 1210.0], $v);
    }
}
