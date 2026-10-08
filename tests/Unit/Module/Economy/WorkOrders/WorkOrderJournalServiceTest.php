<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\WorkOrders\WorkOrderJournalService;

/**
 * Deník zakázky: výběr řádků s dimenzí zakázky, strop počtu řádků
 * a roční obraty (náklady 5xx, výnosy 6xx, ostatní).
 */
class WorkOrderJournalServiceTest extends TestCase
{
    public function testQueriesFilterByWorkOrderDimension(): void
    {
        $queries = [];
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetchAll')->willReturnCallback(function (...$args) use (&$queries): array {
            $queries[] = $args;
            return count($queries) === 1
                ? [new \Dibi\Row(['doc_head' => 21, 'doc_number' => '2260021', 'accounting_date' => new \DateTimeImmutable('2026-06-10'),
                    'account_number' => '518100', 'text' => 'Servis', 'money_dr' => '4200.00', 'money_cr' => '0.00'])]
                : [new \Dibi\Row(['year_name' => '2026', 'expenses' => '4200.00', 'revenues' => '0.00', 'other_dr' => '0.00', 'other_cr' => '0.00'])];
        });

        $overview = (new WorkOrderJournalService($db))->overview(7);

        $this->assertCount(2, $queries);
        foreach ($queries as $query) {
            $this->assertStringContainsString('[j].[work_order] = %i', $query[0]);
            $this->assertStringNotContainsString('operation', $query[0]);
        }
        $this->assertSame([7, WorkOrderJournalService::ROW_LIMIT + 1], array_slice($queries[0], 1));
        $this->assertSame(['5%', '6%', '5%', '6%', '5%', '6%', 7], array_slice($queries[1], 1));

        $this->assertSame([[
            'docId' => 21, 'docNumber' => '2260021', 'date' => '2026-06-10', 'accountNumber' => '518100',
            'text' => 'Servis', 'moneyDr' => 4200.0, 'moneyCr' => 0.0,
        ]], $overview['rows']);
        $this->assertSame(
            [['year' => '2026', 'expenses' => 4200.0, 'revenues' => 0.0, 'otherDr' => 0.0, 'otherCr' => 0.0]],
            $overview['years'],
        );
        $this->assertFalse($overview['more']);
    }

    public function testWithoutRowsSkipsYearTotalsAndLimitFlagsMore(): void
    {
        $calls = 0;
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetchAll')->willReturnCallback(function () use (&$calls): array {
            $calls++;
            return [];
        });
        $this->assertSame(['years' => [], 'rows' => [], 'more' => false], (new WorkOrderJournalService($db))->overview(7));
        $this->assertSame(1, $calls);
        $this->assertSame([], (new WorkOrderJournalService(null))->overview(7)['rows']);

        $row = new \Dibi\Row(['doc_head' => 1, 'doc_number' => 'X', 'accounting_date' => '2026-01-01', 'account_number' => '518100',
            'text' => 'T', 'money_dr' => 1, 'money_cr' => 0]);
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetchAll')->willReturn(array_fill(0, WorkOrderJournalService::ROW_LIMIT + 1, $row));
        $overview = (new WorkOrderJournalService($db))->overview(7);
        $this->assertCount(WorkOrderJournalService::ROW_LIMIT, $overview['rows']);
        $this->assertTrue($overview['more']);
    }
}
