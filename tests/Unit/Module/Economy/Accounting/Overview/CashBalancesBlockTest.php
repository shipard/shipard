<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting\Overview;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Overview\OverviewContext;
use Shipard\Core\Reports\ReportRegistry;
use Shipard\Core\Reports\ReportRunner;
use Shipard\Module\Economy\Accounting\Overview\CashBalancesBlock;

/**
 * Blok pokladen Přehledu firmy (#120 D5, D15, D19): zůstatek z deníku
 * v měně pokladny, datum posledního zápisu, pojistka počátečních stavů,
 * pokladna bez účtu vynechaná (JOIN), chybějící účetní období.
 */
final class CashBalancesBlockTest extends TestCase
{
    private const TODAY = '2026-10-16';

    private const DESKS = [
        ['id' => 1, 'code' => 'P1', 'name' => 'Hlavní pokladna', 'currency' => 'czk', 'account_number' => '211001'],
        ['id' => 2, 'code' => 'P2', 'name' => 'Eurová pokladna', 'currency' => 'eur', 'account_number' => '211002'],
    ];

    private const BALANCES = [
        '211001' => ['balance' => '1500.50', 'balance_cur' => '1500.50', 'last_date' => '2026-10-10', 'entries' => 3],
        '211002' => ['balance' => '25000.00', 'balance_cur' => '1000.00', 'last_date' => '2026-09-30', 'entries' => 1],
    ];

    public function testBalancesPerDeskInDeskCurrencyWithDateNoteAndJournalLink(): void
    {
        // Pokladny v pořadí číselníku: EUR druhá; domácí první drží i tak (D4).
        $desks = array_reverse(self::DESKS);
        $items = (new CashBalancesBlock())->collect($this->context($this->db($desks)))->items;

        $this->assertSame(['cash:1', 'cash:2'], array_map(static fn ($i) => $i->id, $items));
        $this->assertSame([
            'id'      => 'cash:1',
            'label'   => 'Hlavní pokladna',
            'kind'    => 'cash',
            'amount'  => ['value' => 1500.5, 'currency' => 'CZK'],
            'asOf'    => '2026-10-10',
            'state'   => 'ok',
            'note'    => 'According to posted documents',
            'dueDate' => null,
            'link'    => ['kind' => 'open_viewer', 'target' => [
                'viewerId' => 'economy.accounting.journal',
                'filters'  => ['fiscal_year' => 5, 'account' => '211001'],
            ]],
        ], $items[0]->toArray());
        // Cizoměnová pokladna: částka z money_*_cur (D19), ne domácí.
        $this->assertSame(['value' => 1000.0, 'currency' => 'EUR'], $items[1]->amount);
        $this->assertSame('2026-09-30', $items[1]->asOf);
    }

    public function testMissingOpeningBalancesReplaceItemsWithMessage(): void
    {
        $result = (new CashBalancesBlock())->collect($this->context($this->db(self::DESKS, hasOpening: false)));

        $this->assertSame('unavailable', $result->state);
        $this->assertSame([], $result->items);
        $this->assertSame('openingMissing', $result->messages[0]->code);
        $this->assertSame('warning', $result->messages[0]->severity);
    }

    public function testNoDesksYieldsEmptyResultWithoutFurtherQueries(): void
    {
        $db = $this->db([]);
        $db->expects($this->never())->method('fetchRow');
        $db->expects($this->never())->method('fetchSingle');

        $result = (new CashBalancesBlock())->collect($this->context($db));

        $this->assertSame([], $result->items);
        $this->assertSame([], $result->messages);
        $this->assertSame('ok', $result->state);
    }

    public function testDesksQueryJoinsAccountAndFiltersValidity(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(static function (mixed ...$args) use (&$captured): array {
            $captured = $args;
            return [];
        });

        (new CashBalancesBlock())->collect($this->context($db));

        $this->assertNotNull($captured);
        $this->assertStringContainsString('JOIN [economy_accounting_accounts] [a] ON [a].[id] = [d].[accounting_account]', $captured[0]);
        $this->assertStringContainsString('[d].[valid_to] IS NULL OR [d].[valid_to] >= %s', $captured[0]);
        $this->assertSame([90, 90, self::TODAY], array_slice($captured, 1));
    }

    public function testNoFiscalYearYieldsInfoMessage(): void
    {
        $result = (new CashBalancesBlock())->collect($this->context($this->db(self::DESKS, year: null)));

        $this->assertSame([], $result->items);
        $this->assertSame('noFiscalYear', $result->messages[0]->code);
        $this->assertSame('ok', $result->state);
    }

    public function testAvailabilityRequiresDesksAndJournalTables(): void
    {
        $block = new CashBalancesBlock();
        $db    = $this->createMock(DataSourceConnection::class);

        $this->assertTrue($block->isAvailable($this->context($db)));
        $this->assertFalse($block->isAvailable($this->context($db, ['economy_accounting_journal' => true])));
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** @param list<array<string, mixed>> $desks */
    private function db(array $desks, bool $hasOpening = true, ?array $year = ['id' => 5, 'name' => '2026']): MockObject&DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(static function (mixed ...$args) use ($desks): array {
            if (str_contains((string) $args[0], 'economy_codebooks_cash_desks')) {
                return $desks;
            }
            throw new \LogicException('Neočekávaný fetchAll: ' . $args[0]);
        });
        $db->method('fetchRow')->willReturnCallback(static function (mixed ...$args) use ($year): ?array {
            $sql = (string) $args[0];
            if (str_contains($sql, 'economy_codebooks_fiscal_years')) {
                return $year;
            }
            if (str_contains($sql, 'economy_accounting_journal')) {
                return self::BALANCES[(string) $args[1]] ?? null;
            }
            throw new \LogicException("Neočekávaný fetchRow: {$sql}");
        });
        $db->method('fetchSingle')->willReturnCallback(static function (mixed ...$args) use ($hasOpening): mixed {
            $sql = (string) $args[0];
            if (str_contains($sql, '[date_begin]')) {
                return '2026-01-01';
            }
            if (str_contains($sql, '[date_end] <')) {
                return 4;
            }
            if (str_contains($sql, '[period_type]')) {
                return $hasOpening ? 1 : null;
            }
            if (str_contains($sql, 'economy_accounting_journal')) {
                return 1; // předchozí období má zápisy
            }
            throw new \LogicException("Neočekávaný fetchSingle: {$sql}");
        });
        return $db;
    }

    /** @param ?array<string, true> $tables */
    private function context(DataSourceConnection $db, ?array $tables = null): OverviewContext
    {
        return new OverviewContext(
            $db,
            null,
            'cs',
            new \DateTimeImmutable(self::TODAY),
            new ReportRunner(new ReportRegistry(), $db, null, 'test-ds'),
            $tables ?? ['economy_codebooks_cash_desks' => true, 'economy_accounting_journal' => true],
            'cz',
            'czk',
        );
    }
}
