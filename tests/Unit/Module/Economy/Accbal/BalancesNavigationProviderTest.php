<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accbal;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Accbal\BalancesNavigationProvider;

/**
 * Sidebar položky saldokont — odvození ikony ze strany předpisových účtů
 * skupiny (Issue #54): čistě MD → receivable (pohledávkový typ), čistě DAL
 * → payable (závazkový typ), smíšené/žádné → fallback balance. Dobropisové
 * řádky (modify_sign=1) a úhrady (bal_side=1) vylučuje už SQL — na unit
 * úrovni se ověřuje zachycený dotaz.
 */
class BalancesNavigationProviderTest extends TestCase
{
    private string $capturedSql = '';

    /** @param list<array<string, mixed>> $rows */
    private function items(array $rows): array
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql) use ($rows): array {
                $this->capturedSql = $sql;
                return $rows;
            },
        );
        return (new BalancesNavigationProvider())->items($db, 'cs');
    }

    /** @param array<string, mixed> $overrides */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'code'       => 'receivables',
            'name'       => 'Pohledávky',
            'short_name' => 'Pohledávky',
            'side_min'   => 0,
            'side_max'   => 0,
            'side_cnt'   => 2,
        ];
    }

    // ── odvození ikony ──────────────────────────────────────────────────────

    public function testPureMdSideYieldsReceivableIcon(): void
    {
        $items = $this->items([self::row()]);
        $this->assertSame('receivable', $items[0]['icon']);
    }

    public function testPureDalSideYieldsPayableIcon(): void
    {
        $items = $this->items([self::row(['side_min' => 1, 'side_max' => 1, 'side_cnt' => 4])]);
        $this->assertSame('payable', $items[0]['icon']);
    }

    public function testMixedSidesFallBackToBalanceIcon(): void
    {
        $items = $this->items([self::row(['side_min' => 0, 'side_max' => 1, 'side_cnt' => 3])]);
        $this->assertSame('balance', $items[0]['icon']);
    }

    public function testNoPrescriptionRowsFallBackToBalanceIcon(): void
    {
        // Nespárované platby: jen úhradové řádky → LEFT JOIN nic nespáruje,
        // agregace vrací NULL/0.
        $items = $this->items([self::row(['side_min' => null, 'side_max' => null, 'side_cnt' => 0])]);
        $this->assertSame('balance', $items[0]['icon']);
    }

    // ── SQL kontrakt (vyloučení dobropisů a úhrad se děje v dotazu) ─────────

    public function testQueryExcludesCreditNotesPaymentsAndTrashedRows(): void
    {
        $this->items([]);
        $this->assertStringContainsString('a.`bal_side` = 0', $this->capturedSql);
        $this->assertStringContainsString('a.`modify_sign` = 0', $this->capturedSql);
        $this->assertStringContainsString('a.`docState` != 90', $this->capturedSql);
        $this->assertStringContainsString('a.`balance` = b.`id`', $this->capturedSql);
        $this->assertStringContainsString('b.`show_in_navigation` = 1', $this->capturedSql);
    }

    // ── zachování stávajícího chování položek ───────────────────────────────

    public function testItemShapeOrderAndLabelFallbackPreserved(): void
    {
        $items = $this->items([
            self::row(),
            self::row(['code' => 'payables', 'name' => 'Závazky', 'short_name' => '',
                'side_min' => 1, 'side_max' => 1]),
        ]);

        $this->assertSame('accbal-balance:receivables', $items[0]['id']);
        $this->assertSame('viewer', $items[0]['type']);
        $this->assertSame('economy.accbal.cases', $items[0]['viewerId'], 'výchozí vstup do saldokonta = případy (#69 D1)');
        $this->assertSame('receivables', $items[0]['fixedViewGroup']);
        $this->assertSame('accounting', $items[0]['_section']);
        $this->assertSame(32, $items[0]['_order'], 'hned za Saldokonto (31)');
        $this->assertSame(33, $items[1]['_order']);
        // Prázdný short_name → plný name.
        $this->assertSame('Závazky', $items[1]['label']);
    }

    public function testDbFailureYieldsEmptyList(): void
    {
        // DS před ds-upgrade (chybějící sloupec) nesmí shodit navigaci.
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willThrowException(new \RuntimeException('Unknown column'));
        $this->assertSame([], (new BalancesNavigationProvider())->items($db, 'cs'));
    }
}
