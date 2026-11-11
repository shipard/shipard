<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

class DocDocumentRowCalcTest extends TestCase
{
    private function doc(): TestableDocsHeadsDocument
    {
        return new TestableDocsHeadsDocument();
    }

    // ── calculateRowPrice ──────────────────────────────────────────────────

    public function testCalculateRowPriceFromUnitPrice(): void
    {
        $row = ['row_kind' => 1, 'quantity' => 2, 'unit_price' => 100, 'price_calc_mode' => 0];
        $this->doc()->calculateRowPricePub($row);

        $this->assertSame(200.0, $row['total_price']);
    }

    public function testCalculateRowPriceFromTotalPrice(): void
    {
        $row = ['row_kind' => 1, 'quantity' => 4, 'total_price' => 1000, 'price_calc_mode' => 1];
        $this->doc()->calculateRowPricePub($row);

        $this->assertSame(250.0, $row['unit_price']);
    }

    public function testCalculateRowPriceWithDiscountPct(): void
    {
        $row = [
            'row_kind' => 1, 'quantity' => 1, 'unit_price' => 1000, 'price_calc_mode' => 0,
            'discount_pct' => 10,
        ];
        $this->doc()->calculateRowPricePub($row);

        $this->assertSame(900.0, $row['total_price']);
    }

    public function testCalculateRowPriceWithDiscountAmount(): void
    {
        $row = [
            'row_kind' => 1, 'quantity' => 1, 'unit_price' => 1000, 'price_calc_mode' => 0,
            'discount_amount' => 250,
        ];
        $this->doc()->calculateRowPricePub($row);

        $this->assertSame(750.0, $row['total_price']);
    }

    public function testCalculateRowPriceTextRowSetsNull(): void
    {
        $row = ['row_kind' => 0, 'quantity' => 5, 'unit_price' => 100];
        $this->doc()->calculateRowPricePub($row);

        $this->assertNull($row['total_price']);
    }

    public function testCalculateRowPriceFromTotalWithZeroQuantity(): void
    {
        $row = ['row_kind' => 1, 'quantity' => 0, 'total_price' => 100, 'price_calc_mode' => 1];
        $this->doc()->calculateRowPricePub($row);

        $this->assertSame(0.0, $row['unit_price']);
    }

    public function testCalculateRowPriceFromTotalWithNegativeQuantity(): void
    {
        // Dobropis (#98): −2 ks za −100 celkem = 50 za kus, celková cena beze změny.
        $row = ['row_kind' => 1, 'quantity' => -2, 'total_price' => -100, 'price_calc_mode' => 1];
        $this->doc()->calculateRowPricePub($row);

        $this->assertSame(50.0, $row['unit_price']);
        $this->assertSame(-100.0, $row['total_price']);
    }

    // ── calculateRowVat ────────────────────────────────────────────────────

    public function testCalculateRowVatModeNoVat(): void
    {
        $row = ['row_kind' => 1, 'total_price' => 200, 'vat_code' => 'cz-110', 'vat_pct' => 21];
        $this->doc()->calculateRowVatPub($row, 0);

        $this->assertSame(200.0, $row['vat_base']);
        $this->assertSame(0.0, $row['vat_amount']);
        $this->assertSame(200.0, $row['vat_total']);
    }

    public function testCalculateRowVatModeFromBase(): void
    {
        $row = ['row_kind' => 1, 'total_price' => 200, 'vat_code' => 'cz-110', 'vat_pct' => 21];
        $this->doc()->calculateRowVatPub($row, 1);

        $this->assertSame(200.0, $row['vat_base']);
        $this->assertSame(42.0, $row['vat_amount']);
        $this->assertSame(242.0, $row['vat_total']);
    }

    public function testCalculateRowVatModeFromTotal(): void
    {
        $row = ['row_kind' => 1, 'total_price' => 242, 'vat_code' => 'cz-110', 'vat_pct' => 21];
        $this->doc()->calculateRowVatPub($row, 2);

        $this->assertSame(200.0, $row['vat_base']);
        $this->assertSame(42.0, $row['vat_amount']);
        $this->assertSame(242.0, $row['vat_total']);
    }

    public function testCalculateRowVatTextRowSetsAllNull(): void
    {
        $row = ['row_kind' => 0, 'total_price' => 200];
        $this->doc()->calculateRowVatPub($row, 1);

        $this->assertNull($row['vat_base']);
        $this->assertNull($row['vat_amount']);
        $this->assertNull($row['vat_total']);
    }

    public function testCalculateRowVatMissingCodeOrPctFallback(): void
    {
        $row = ['row_kind' => 1, 'total_price' => 100];
        $this->doc()->calculateRowVatPub($row, 1);

        $this->assertSame(100.0, $row['vat_base']);
        $this->assertSame(0.0, $row['vat_amount']);
        $this->assertSame(100.0, $row['vat_total']);
    }

    // ── calculateRowVat: noPayTax kódy (Z3) ─────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    private function vatCodes(): array
    {
        return [
            // Vstupní samovyměření (PDP, EU pořízení) — noPayTax + reverseVatCode.
            'cz-115' => ['noPayTax' => 1, 'reverseVatCode' => 'cz-203'],
            // Výstupní PDP / osvobozené — noPayTax bez reverseVatCode.
            'cz-150' => ['noPayTax' => 1],
            // Běžný kód bez noPayTax.
            'cz-110' => [],
        ];
    }

    public function testCalculateRowVatSelfAssessedInputFromBase(): void
    {
        $row = ['row_kind' => 1, 'total_price' => 1000, 'vat_code' => 'cz-115', 'vat_pct' => 21];
        $this->doc()->calculateRowVatPub($row, 1, $this->vatCodes());

        $this->assertSame(1000.0, $row['vat_base']);
        $this->assertSame(210.0, $row['vat_amount']);
        $this->assertSame(1000.0, $row['vat_total']);
    }

    public function testCalculateRowVatSelfAssessedInputFromTotalUsesFullBase(): void
    {
        // Mode 2: total_price je celý základ, žádný zpětný rozpočet.
        $row = ['row_kind' => 1, 'total_price' => 1000, 'vat_code' => 'cz-115', 'vat_pct' => 21];
        $this->doc()->calculateRowVatPub($row, 2, $this->vatCodes());

        $this->assertSame(1000.0, $row['vat_base']);
        $this->assertSame(210.0, $row['vat_amount']);
        $this->assertSame(1000.0, $row['vat_total']);
    }

    public function testCalculateRowVatOutputPdpHasZeroAmount(): void
    {
        $row = ['row_kind' => 1, 'total_price' => 1900, 'vat_code' => 'cz-150', 'vat_pct' => 21];
        $this->doc()->calculateRowVatPub($row, 1, $this->vatCodes());

        $this->assertSame(1900.0, $row['vat_base']);
        $this->assertSame(0.0, $row['vat_amount']);
        $this->assertSame(1900.0, $row['vat_total']);
    }

    public function testCalculateRowVatOrdinaryCodeWithDefsUnchanged(): void
    {
        $row = ['row_kind' => 1, 'total_price' => 200, 'vat_code' => 'cz-110', 'vat_pct' => 21];
        $this->doc()->calculateRowVatPub($row, 1, $this->vatCodes());

        $this->assertSame(200.0, $row['vat_base']);
        $this->assertSame(42.0, $row['vat_amount']);
        $this->assertSame(242.0, $row['vat_total']);
    }

    public function testCalculateRowVatNullDefsUnchanged(): void
    {
        // Bez definic kódů (nedohledaná země) — noPayTax se neuplatní.
        $row = ['row_kind' => 1, 'total_price' => 1000, 'vat_code' => 'cz-115', 'vat_pct' => 21];
        $this->doc()->calculateRowVatPub($row, 1, null);

        $this->assertSame(1000.0, $row['vat_base']);
        $this->assertSame(210.0, $row['vat_amount']);
        $this->assertSame(1210.0, $row['vat_total']);
    }
}
