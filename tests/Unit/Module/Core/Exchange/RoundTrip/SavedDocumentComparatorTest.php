<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\RoundTrip;

use PHPUnit\Framework\TestCase;
use Shipard\Tests\Integration\Exchange\RoundTrip\SavedDocument;
use Shipard\Tests\Integration\Exchange\RoundTrip\SavedDocumentComparator;

/**
 * Porovnávač uložených dokladů matice round-tripu
 * (tasks/doc-saving-safety-net.md U3) nad definicemi tabulek z repozitáře.
 */
class SavedDocumentComparatorTest extends TestCase
{
    private function comparator(?callable $referenceLabel = null): SavedDocumentComparator
    {
        return new SavedDocumentComparator(DocTableDefinitions::load(), $referenceLabel);
    }

    /**
     * @param array<string, mixed>       $head
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $recap
     */
    private static function doc(array $head = [], array $rows = [], array $recap = []): SavedDocument
    {
        return new SavedDocument($head + ['id' => 1, 'doc_type' => 'invno'], $rows, $recap);
    }

    public function testIdentityAuditAndOriginColumnsAreIgnored(): void
    {
        $a = self::doc(
            ['id' => 1, 'doc_number' => '2600001', 'sequence_number' => 1, 'doc_state_changed_at' => '2026-06-10T10:00:00', 'source_kind' => null],
            [['id' => 10, 'doc_head' => 1, 'order_pos' => 1, 'description' => 'práce']],
            [['id' => 20, 'doc_head' => 1, 'vat_code' => 'cz-120', 'vat_pct' => 21]],
        );
        $b = self::doc(
            ['id' => 2, 'doc_number' => 'IT-RT-B-1', 'sequence_number' => null, 'doc_state_changed_at' => '2026-06-10T10:00:05', 'source_kind' => 'import'],
            [['id' => 11, 'doc_head' => 2, 'order_pos' => 1, 'description' => 'práce']],
            [['id' => 21, 'doc_head' => 2, 'vat_code' => 'cz-120', 'vat_pct' => 21]],
        );

        $this->assertSame([], $this->comparator()->compare($a, $b));
    }

    public function testNumbersCompareAtColumnScaleAndDatesAsText(): void
    {
        $a = self::doc(
            ['total_amount' => '121.40', 'exchange_rate' => 24.565, 'issue_date' => '2026-06-10', 'vat_mode' => '1'],
            [['order_pos' => 1, 'quantity' => 2, 'unit_price' => '1234.5000']],
        );
        $b = self::doc(
            ['total_amount' => 121.4, 'exchange_rate' => '24.565000', 'issue_date' => new \DateTimeImmutable('2026-06-10'), 'vat_mode' => 1],
            [['order_pos' => 1, 'quantity' => '2.0000', 'unit_price' => 1234.5]],
        );
        $this->assertSame([], $this->comparator()->compare($a, $b));

        // Rozdíl pod přesností sloupce se nepočítá, na jeho přesnosti ano.
        $b = self::doc(['total_amount' => 121.404], [['order_pos' => 1, 'quantity' => 2.00004, 'unit_price' => 1234.5001]]);
        $a = self::doc(['total_amount' => 121.40], [['order_pos' => 1, 'quantity' => 2, 'unit_price' => 1234.5]]);
        $this->assertSame(
            ['rows[1].unit_price' => ['1234.5000', '1234.5001']],
            $this->comparator()->compare($a, $b),
        );
    }

    public function testNullDiffersFromEmptyStringAndZero(): void
    {
        $a = self::doc(['doc_text' => null, 'total_rounding_mode' => 3]);
        $b = self::doc(['doc_text' => '', 'total_rounding_mode' => 1]);

        $this->assertSame(
            ['head.doc_text' => [null, ''], 'head.total_rounding_mode' => [3, 1]],
            $this->comparator()->compare($a, $b),
        );
    }

    public function testRowsPairByOrderPosAndMissingRowIsDifference(): void
    {
        $a = self::doc([], [
            ['order_pos' => 2, 'description' => 'druhý'],
            ['order_pos' => 1, 'description' => 'první'],
        ]);
        $b = self::doc([], [
            ['order_pos' => 1, 'description' => 'první'],
            ['order_pos' => 2, 'description' => 'jiný'],
            ['order_pos' => 3, 'description' => 'navíc'],
        ]);

        $this->assertSame(
            [
                'rows[2].description' => ['druhý', 'jiný'],
                'rows[3]'             => [SavedDocumentComparator::MISSING, SavedDocumentComparator::PRESENT],
            ],
            $this->comparator()->compare($a, $b),
        );
    }

    public function testRecapPairsByGroupKey(): void
    {
        $group = static fn (array $over): array => $over + [
            'vat_code' => 'cz-110', 'vat_pct' => 21, 'reverse_charge_code' => null,
            'vat_rate_date' => null, 'is_reverse_pair' => 0, 'base' => 100, 'tax' => 21, 'total' => 121,
        ];
        $a = self::doc([], [], [
            $group([]),
            $group(['vat_pct' => 20, 'vat_rate_date' => '2012-06-01', 'base' => 50, 'tax' => 10, 'total' => 60]),
        ]);
        $b = self::doc([], [], [
            $group(['vat_pct' => '20.00', 'vat_rate_date' => '2012-06-01', 'base' => 50, 'tax' => 10, 'total' => 60]),
            $group(['tax' => 20.99, 'total' => 120.99]),
            $group(['vat_code' => 'cz-203', 'is_reverse_pair' => 1]),
        ]);

        $this->assertSame(
            [
                'vatRecap[cz-110/21.00/-/-/0].tax'   => ['21.00', '20.99'],
                'vatRecap[cz-110/21.00/-/-/0].total' => ['121.00', '120.99'],
                'vatRecap[cz-203/21.00/-/-/1]'       => [SavedDocumentComparator::MISSING, SavedDocumentComparator::PRESENT],
            ],
            $this->comparator()->compare($a, $b),
        );
    }

    public function testJsonColumnDiffersByLeafAndIgnoresKeyOrder(): void
    {
        $a = self::doc(['supplier_snapshot' => '{"name":"Firma","company_id":"","address":{"city":"Brno","zip":"60200"}}']);
        $b = self::doc(['supplier_snapshot' => '{"address":{"zip":"60200","city":"Brno"},"company_id":"","name":"Firma"}']);
        $this->assertSame([], $this->comparator()->compare($a, $b));

        $b = self::doc(['supplier_snapshot' => ['name' => 'Firma', 'company_id' => null, 'address' => ['city' => 'Zlín', 'zip' => '60200'], 'bank_account' => ['iban' => 'CZ00']]]);
        $this->assertSame(
            [
                'head.supplier_snapshot.address.city' => ['Brno', 'Zlín'],
                'head.supplier_snapshot.company_id'   => ['', null],
                'head.supplier_snapshot.bank_account' => [SavedDocumentComparator::ABSENT, ['iban' => 'CZ00']],
            ],
            $this->comparator()->compare($a, $b),
        );
    }

    public function testReferenceValuesGetStableLabels(): void
    {
        $label = static fn (string $table, int $id): string => $id === 7 ? '@supplier' : "{$table}:?";
        $a = self::doc(['partner' => 7, 'partner_address' => 31]);
        $b = self::doc(['partner' => 99, 'partner_address' => null]);

        $this->assertSame(
            [
                'head.partner'         => ['@supplier', 'base_persons_persons:?'],
                'head.partner_address' => ['base_persons_addresses:?', null],
            ],
            $this->comparator($label)->compare($a, $b),
        );
        // Shodná reference se neoznačuje ani nehlásí.
        $this->assertSame([], $this->comparator($label)->compare($a, $a));
    }
}
