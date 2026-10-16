<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accbal\Overview;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Overview\OverviewContext;
use Shipard\Core\Reports\ReportRegistry;
use Shipard\Core\Reports\ReportRunner;
use Shipard\Module\Economy\Accbal\Overview\PayablesBlock;
use Shipard\Module\Economy\Accbal\Overview\ReceivablesBlock;

/**
 * Bloky saldokonta Přehledu firmy (#120 D6, D18): položky per měna
 * (domácí první) s proklikem nesoucím tytéž filtry, nulová položka bez
 * případů, nespárované platby jako zpráva, pojistka počátečních stavů,
 * chybějící skupina na DS.
 */
final class ReceivablesBlockTest extends TestCase
{
    private const TODAY = '2026-10-16';

    /** @var list<array{0: string, 1: list<mixed>}> */
    private array $totalsQueries = [];

    public function testItemsPerCurrencyWithLinksMatchingTotalsFilters(): void
    {
        $db = $this->db(
            total:   [self::row('eur', 2, 300.0), self::row('czk', 12, 150000.0)],
            overdue: [self::row('czk', 3, 20000.0)],
        );

        $result = (new ReceivablesBlock())->collect($this->context($db));

        $this->assertSame('ok', $result->state);
        $this->assertSame(
            ['receivables:total:czk', 'receivables:total:eur', 'receivables:overdue:czk'],
            array_map(static fn ($i) => $i->id, $result->items),
        );

        $total = $result->items[0]->toArray();
        $this->assertSame('Receivables', $total['label']);
        $this->assertSame('receivables', $total['kind']);
        $this->assertSame(['value' => 150000.0, 'currency' => 'CZK'], $total['amount']);
        $this->assertSame(self::TODAY, $total['asOf']);
        $this->assertSame('12 cases', $total['note']);
        $this->assertSame(['kind' => 'open_viewer', 'target' => [
            'viewerId'  => 'economy.accbal.cases',
            'viewGroup' => 'receivables',
            'filters'   => ['fiscal_year' => '5', 'kind' => 'debt', 'currency' => 'czk'],
        ]], $total['link']);
        $this->assertSame(['value' => 300.0, 'currency' => 'EUR'], $result->items[1]->amount);

        $overdue = $result->items[2]->toArray();
        $this->assertSame('Overdue receivables', $overdue['label']);
        $this->assertSame(['fiscal_year' => '5', 'kind' => 'debt', 'overdue' => '1', 'currency' => 'czk'], $overdue['link']['target']['filters']);

        // Součty dostaly přesně filtry prokliku (bez měny — ta je výsledkem GROUP BY).
        $this->assertSame(['receivables', 5], array_slice($this->totalsQueries[0][1], 0, 2));
        $this->assertSame(['receivables', 5, self::TODAY], $this->totalsQueries[1][1]);
    }

    public function testZeroItemInHomeCurrencyWithoutCases(): void
    {
        $db = $this->db(total: [], overdue: []);

        $items = (new ReceivablesBlock())->collect($this->context($db))->items;

        $this->assertSame(['receivables:total:czk', 'receivables:overdue:czk'], array_map(static fn ($i) => $i->id, $items));
        $this->assertSame(['value' => 0.0, 'currency' => 'CZK'], $items[0]->amount);
        $this->assertNull($items[0]->note);
        $this->assertSame(['fiscal_year' => '5', 'kind' => 'debt', 'currency' => 'czk'], $items[0]->link['target']['filters']);
    }

    public function testUnmatchedPaymentsMessageWithCountSumAndLink(): void
    {
        $db = $this->db(total: [], overdue: [], unmatched: [self::row('czk', 2, -1500.5), self::row('eur', 0, 0.0)]);

        $messages = (new ReceivablesBlock())->collect($this->context($db))->messages;

        $this->assertCount(1, $messages, 'měna bez případů se nehlásí');
        $this->assertSame('unmatchedPayments', $messages[0]->code);
        $this->assertSame('info', $messages[0]->severity);
        $this->assertSame('2 unmatched payments totalling 1,500.50 CZK', $messages[0]->text);
        $this->assertSame(['kind' => 'open_viewer', 'target' => [
            'viewerId'  => 'economy.accbal.cases',
            'viewGroup' => 'unmatched_payments',
            'filters'   => ['fiscal_year' => '5', 'currency' => 'czk'],
        ]], $messages[0]->link);
    }

    public function testMissingOpeningBalancesMakeBlockUnavailable(): void
    {
        $db = $this->db(total: [], overdue: [], hasOpening: false);
        $db->expects($this->never())->method('fetchAll');

        $result = (new ReceivablesBlock())->collect($this->context($db));

        $this->assertSame('unavailable', $result->state);
        $this->assertSame([], $result->items);
        $this->assertSame(['openingMissing'], array_map(static fn ($m) => $m->code, $result->messages));
        $this->assertSame('warning', $result->messages[0]->severity);
    }

    public function testMissingGroupYieldsEmptyResult(): void
    {
        $db = $this->db(total: [], overdue: [], groups: []);
        $db->expects($this->never())->method('fetchAll');

        $result = (new ReceivablesBlock())->collect($this->context($db));

        $this->assertSame([], $result->items);
        $this->assertSame([], $result->messages);
    }

    public function testPayablesSpecsOverdueAndDueWithinSevenDays(): void
    {
        $db = $this->db(total: [], overdue: [self::row('czk', 1, 5000.0)], dueSoon: [self::row('czk', 4, 42000.0)]);

        $items = (new PayablesBlock())->collect($this->context($db))->items;

        $this->assertSame(['payables:overdue:czk', 'payables:dueSoon:czk'], array_map(static fn ($i) => $i->id, $items));
        $this->assertSame('Overdue payables', $items[0]->label);
        $this->assertSame('Payables due within 7 days', $items[1]->label);
        $this->assertSame(['value' => 42000.0, 'currency' => 'CZK'], $items[1]->amount);
        $this->assertSame('4 cases', $items[1]->note);
        $this->assertSame(['fiscal_year' => '5', 'kind' => 'debt', 'dueWithin' => '7', 'currency' => 'czk'], $items[1]->link['target']['filters']);
        $this->assertSame(['payables', 5, self::TODAY, '2026-10-23'], $this->totalsQueries[1][1], 'splatné do 7 dní = ⟨dnes, dnes + 7⟩');
    }

    public function testAvailabilityRequiresLedgerAndBalancesTables(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $this->assertTrue((new ReceivablesBlock())->isAvailable($this->context($db)));
        $this->assertFalse((new ReceivablesBlock())->isAvailable($this->context($db, ['economy_accbal_ledger' => true])));
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> řádek součtů per měna */
    private static function row(string $currency, int $count, float $residual): array
    {
        return ['currency' => $currency, 'home_currency' => 'czk', 'cnt' => $count,
            'sum_requests' => max($residual, 0.0), 'sum_payments' => max(-$residual, 0.0), 'residual' => $residual,
            'sum_requests_hc' => max($residual, 0.0), 'sum_payments_hc' => max(-$residual, 0.0), 'residual_hc' => $residual];
    }

    /**
     * @param list<array<string, mixed>> $total     součty bez případových filtrů splatnosti
     * @param list<array<string, mixed>> $overdue   součty s `overdue`
     * @param list<array<string, mixed>> $dueSoon   součty s `dueWithin`
     * @param list<array<string, mixed>> $unmatched součty skupiny nespárovaných plateb
     * @param list<string>               $groups    kódy saldokont, která na DS existují
     */
    private function db(
        array $total,
        array $overdue,
        array $dueSoon = [],
        array $unmatched = [],
        bool $hasOpening = true,
        array $groups = ['receivables', 'payables', 'unmatched_payments'],
    ): MockObject&DataSourceConnection {
        $this->totalsQueries = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturnCallback(static function (mixed ...$args) use ($hasOpening, $groups): mixed {
            $sql = (string) $args[0];
            if (str_contains($sql, 'economy_accbal_balances')) {
                return in_array($args[1], $groups, true) ? array_search($args[1], $groups, true) + 1 : null;
            }
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
                return 1;
            }
            throw new \LogicException("Neočekávaný fetchSingle: {$sql}");
        });
        $db->method('fetchRow')->willReturnCallback(static function (mixed ...$args): ?array {
            if (str_contains((string) $args[0], 'economy_codebooks_fiscal_years')) {
                return ['id' => 5, 'name' => '2026'];
            }
            throw new \LogicException('Neočekávaný fetchRow: ' . $args[0]);
        });
        $db->method('fetchAll')->willReturnCallback(function (mixed ...$args) use ($total, $overdue, $dueSoon, $unmatched): array {
            $sql    = (string) $args[0];
            $params = array_slice($args, 1);
            $this->totalsQueries[] = [$sql, $params];
            if (in_array('unmatched_payments', $params, true)) {
                return $unmatched;
            }
            if (str_contains($sql, 'c.[due_date] >=')) {
                return $dueSoon;
            }
            if (str_contains($sql, 'c.[due_date] < %s')) {
                return $overdue;
            }
            return $total;
        });
        return $db;
    }

    /** @param ?array<string, true> $tables */
    private function context(DataSourceConnection $db, ?array $tables = null): OverviewContext
    {
        return new OverviewContext(
            $db,
            null,
            'en',
            new \DateTimeImmutable(self::TODAY),
            new ReportRunner(new ReportRegistry(), $db, null, 'test-ds'),
            $tables ?? ['economy_accbal_ledger' => true, 'economy_accbal_balances' => true],
            'cz',
            'czk',
        );
    }
}
