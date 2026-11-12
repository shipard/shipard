<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Document\VatModeDerivation;

class VatModeDerivationTest extends TestCase
{
    /**
     * Referenční účtenka PHM (anonymizované poměry z dev DS): 45 l ×
     * 38,80 Kč koncové ceny = 1746,00; recap 1442,98 + 303,02.
     *
     * @return array<string, mixed>
     */
    private function receiptCanonical(): array
    {
        return [
            'docType' => 'invoiceReceived',
            'vat'     => ['mode' => 'fromBase'],
            'rows'    => [
                [
                    'rowKind'    => 'item',
                    'quantity'   => 45,
                    'unitPrice'  => 38.80,
                    'totalPrice' => 1746.00,
                    'vat'        => ['code' => 'cz-110', 'pct' => 21],
                ],
            ],
            'vatRecap' => [
                ['vatCode' => 'cz-110', 'vatPct' => 21, 'base' => 1442.98, 'tax' => 303.02, 'total' => 1746.00],
            ],
            'totals' => [
                'totalBase' => 1442.98, 'totalVat' => 303.02, 'totalAmount' => 1746.00,
            ],
        ];
    }

    public function testReceiptRowsMatchingRecapTotalDeriveFromTotal(): void
    {
        $this->assertSame(2, VatModeDerivation::derive($this->receiptCanonical()));
    }

    public function testRowsMatchingRecapBaseDeriveFromBase(): void
    {
        // Korektní „zdola" faktura — derivace potvrdí deklarovaný mode 1,
        // applier tedy nic nemění a issue nevzniká.
        $canonical = [
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 10330.58, 'vat' => ['pct' => 21]],
            ],
            'vatRecap' => [
                ['vatPct' => 21, 'base' => 10330.58, 'tax' => 2169.42, 'total' => 12500.00],
            ],
        ];
        $this->assertSame(1, VatModeDerivation::derive($canonical));
    }

    public function testZeroRateDocIsSkipped(): void
    {
        // refBase ≈ refTotal — osvobozeno / 0 % — oba režimy dají stejná
        // čísla, derivace mlčí.
        $canonical = [
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 1000.00, 'vat' => ['pct' => 0]],
            ],
            'vatRecap' => [
                ['vatPct' => 0, 'base' => 1000.00, 'tax' => 0.00, 'total' => 1000.00],
            ],
        ];
        $this->assertNull(VatModeDerivation::derive($canonical));
    }

    public function testFallbackToTotalsWithRounding(): void
    {
        // Bez recap: refTotal = totalAmount − totalRounding. Řádky v cenách
        // s DPH sedí na nezaokrouhlenou částku.
        $canonical = [
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 1745.80, 'vat' => ['pct' => 21]],
            ],
            'totals' => [
                'totalBase' => 1442.81, 'totalAmount' => 1746.00, 'totalRounding' => 0.20,
            ],
        ];
        $this->assertSame(2, VatModeDerivation::derive($canonical));
    }

    public function testNoRecapNoTotalsIsSkipped(): void
    {
        $canonical = [
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 1746.00, 'vat' => ['pct' => 21]],
            ],
        ];
        $this->assertNull(VatModeDerivation::derive($canonical));
    }

    public function testIncompleteRecapFallsBackToTotals(): void
    {
        // Recap bez base je nekompletní → přeskočí se, reference z totals.
        $canonical = [
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 1746.00, 'vat' => ['pct' => 21]],
            ],
            'vatRecap' => [
                ['vatPct' => 21, 'total' => 1746.00],
            ],
            'totals' => [
                'totalBase' => 1442.98, 'totalAmount' => 1746.00,
            ],
        ];
        $this->assertSame(2, VatModeDerivation::derive($canonical));
    }

    public function testNoPayTaxRecapConfirmsDeclaredFromBase(): void
    {
        // PDP (cz-115): Σ řádků == recap base; informativní daň v recap
        // total. Derivace vrátí 1 == deklarovaný mode → žádná korekce.
        $canonical = [
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 10000.00, 'vat' => ['code' => 'cz-115', 'pct' => 21]],
            ],
            'vatRecap' => [
                ['vatCode' => 'cz-115', 'vatPct' => 21, 'base' => 10000.00, 'tax' => 2100.00, 'total' => 12100.00],
            ],
        ];
        $this->assertSame(1, VatModeDerivation::derive($canonical));
    }

    public function testNoPayTaxTotalsWithPaidAmountIsSkipped(): void
    {
        // PDP bez recap: totalAmount = placená částka = base → guard
        // refBase ≈ refTotal derivaci vyřadí.
        $canonical = [
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 10000.00, 'vat' => ['code' => 'cz-115', 'pct' => 21]],
            ],
            'totals' => [
                'totalBase' => 10000.00, 'totalAmount' => 10000.00,
            ],
        ];
        $this->assertNull(VatModeDerivation::derive($canonical));
    }

    public function testAmbiguousMatchOnBothReferencesIsSkipped(): void
    {
        // 100 řádků → ε = 1,00; reference se liší přesně o 1,00 (guard
        // < 1,00 nevyřadí) a Σ řádků padne do tolerance obou → null.
        $rows = [];
        for ($i = 0; $i < 100; $i++) {
            $rows[] = ['rowKind' => 'item', 'totalPrice' => 10.005, 'vat' => ['pct' => 21]];
        }
        $canonical = [
            'rows'   => $rows,
            'totals' => ['totalBase' => 1000.00, 'totalAmount' => 1001.00],
        ];
        $this->assertNull(VatModeDerivation::derive($canonical));
    }

    public function testMatchingNeitherReferenceIsSkipped(): void
    {
        $canonical = [
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 500.00, 'vat' => ['pct' => 21]],
            ],
            'totals' => ['totalBase' => 1000.00, 'totalAmount' => 1210.00],
        ];
        $this->assertNull(VatModeDerivation::derive($canonical));
    }

    public function testItemRowWithoutNumericTotalPriceDisablesDerivation(): void
    {
        // Neúplný součet je pro rozhodnutí o modu nespolehlivý.
        $canonical = $this->receiptCanonical();
        $canonical['rows'][] = ['rowKind' => 'item', 'quantity' => 1];
        $this->assertNull(VatModeDerivation::derive($canonical));
    }

    public function testNonItemAndContationRowsAreIgnored(): void
    {
        $canonical = $this->receiptCanonical();
        $canonical['rows'][] = ['rowKind' => 'text', 'description' => 'Poznámka'];
        $canonical['rows'][] = ['rowKind' => 'item', 'totalPrice' => 999.99, 'accSide' => 'debit'];
        $this->assertSame(2, VatModeDerivation::derive($canonical));
    }
}
