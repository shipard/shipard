<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accbal;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Accbal\CaseQuery;
use Shipard\Module\Economy\Accbal\CaseTotals;

/**
 * Součty případů per měna (#120): podmínky filtrů ve dvou úrovních vč.
 * nových `currency` (klíčová) a `dueWithin` (případová, interval
 * ⟨dnes, dnes + N⟩), tvar dotazu součtů a parsování řádků, převod mapy
 * filtrů z prokliku na tvar vieweru.
 */
final class CaseTotalsTest extends TestCase
{
    private const TODAY = '2026-10-16';

    public function testCurrencyIsKeyLevelAndNormalized(): void
    {
        [$rowConds, $rowParams, $caseConds, $caseParams] = CaseTotals::conditions(
            [['id' => 'viewGroup', 'value' => 'receivables'], ['id' => 'currency', 'value' => ' EUR ']],
            new \DateTimeImmutable(self::TODAY),
        );

        $this->assertSame(['b.`code` = %s', 'l.`currency` = %s'], $rowConds);
        $this->assertSame(['receivables', 'eur'], $rowParams);
        $this->assertSame([CaseQuery::openConditionSql('c')], $caseConds, 'výchozí jen otevřené');
        $this->assertSame([], $caseParams);
    }

    public function testDueWithinIsCaseLevelIntervalFromTodayInclusive(): void
    {
        [, , $caseConds, $caseParams] = CaseTotals::conditions(
            [['id' => 'kind', 'value' => CaseQuery::KIND_DEBT], ['id' => 'dueWithin', 'value' => '7']],
            new \DateTimeImmutable(self::TODAY),
        );

        $this->assertSame(
            [CaseQuery::openConditionSql('c'), 'c.[residual] > 0', 'c.[residual] > 0 AND c.[due_date] >= %s AND c.[due_date] <= %s'],
            $caseConds,
        );
        $this->assertSame(['2026-10-16', '2026-10-23'], $caseParams);

        [, , , $zero] = CaseTotals::conditions([['id' => 'dueWithin', 'value' => '0']], new \DateTimeImmutable(self::TODAY));
        $this->assertSame(['2026-10-16', '2026-10-16'], $zero, 'N = 0 → jen dnešek');
    }

    public function testInvalidDueWithinAndEmptyCurrencyAreIgnored(): void
    {
        foreach (['abc', '-1', '7.5', '', null] as $value) {
            [$rowConds, , $caseConds, $caseParams] = CaseTotals::conditions(
                [['id' => 'dueWithin', 'value' => $value], ['id' => 'currency', 'value' => '  ']],
                new \DateTimeImmutable(self::TODAY),
            );
            $this->assertSame([], $rowConds, var_export($value, true));
            $this->assertSame([CaseQuery::openConditionSql('c')], $caseConds, var_export($value, true));
            $this->assertSame([], $caseParams);
        }
    }

    public function testViewGroupAllAndActiveHaveNoCondition(): void
    {
        $this->assertSame([null, null], CaseTotals::viewGroupConditionSql('all'));
        $this->assertSame([null, null], CaseTotals::viewGroupConditionSql('active'));
        $this->assertSame(['x.`code` = %s', 'payables'], CaseTotals::viewGroupConditionSql('payables', 'x'));
    }

    public function testFiltersFromMapKeepsOrderAndValues(): void
    {
        $this->assertSame(
            [['id' => 'viewGroup', 'value' => 'receivables'], ['id' => 'fiscal_year', 'value' => '5'], ['id' => 'overdue', 'value' => '1']],
            CaseTotals::filtersFromMap(['viewGroup' => 'receivables', 'fiscal_year' => '5', 'overdue' => '1']),
        );
    }

    public function testTotalsGroupsByCurrencyAndParsesRows(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(static function (mixed ...$args) use (&$captured): array {
            $captured = $args;
            return [
                ['currency' => 'CZK', 'home_currency' => 'czk', 'cnt' => '3', 'sum_requests' => '1500.00', 'sum_payments' => '500.00', 'residual' => '1000.00',
                 'sum_requests_hc' => '1500.00', 'sum_payments_hc' => '500.00', 'residual_hc' => '1000.00'],
                ['currency' => 'eur', 'home_currency' => 'czk', 'cnt' => 1, 'sum_requests' => '100', 'sum_payments' => null, 'residual' => '100',
                 'sum_requests_hc' => '2500', 'sum_payments_hc' => null, 'residual_hc' => '2500'],
            ];
        });

        $rows = (new CaseTotals($db))->totals(
            CaseTotals::filtersFromMap(['viewGroup' => 'receivables', 'fiscal_year' => '5', 'kind' => 'debt', 'overdue' => '1']),
            new \DateTimeImmutable(self::TODAY),
        );

        $sql = (string) $captured[0];
        $this->assertStringStartsWith('SELECT c.`currency`, MAX(c.`home_currency`) AS home_currency, COUNT(*) AS cnt,', $sql);
        $this->assertStringContainsString(' FROM (SELECT l.[balance], l.[fiscal_year],', $sql);
        $this->assertStringContainsString('FROM `economy_accbal_ledger` l', $sql);
        $this->assertStringContainsString(' WHERE b.`code` = %s AND l.`fiscal_year` = %i GROUP BY l.[balance],', $sql, 'klíčové před GROUP BY');
        $this->assertStringContainsString(') c WHERE c.[residual] <> 0 AND c.[residual] > 0 AND c.[residual] > 0 AND c.[due_date] < %s GROUP BY c.`currency` ORDER BY c.`currency`', $sql);
        $this->assertSame(['receivables', 5, '2026-10-16'], array_slice($captured, 1));

        $this->assertSame([
            ['currency' => 'czk', 'home_currency' => 'czk', 'count' => 3, 'sum_requests' => 1500.0, 'sum_payments' => 500.0, 'residual' => 1000.0,
             'sum_requests_hc' => 1500.0, 'sum_payments_hc' => 500.0, 'residual_hc' => 1000.0],
            ['currency' => 'eur', 'home_currency' => 'czk', 'count' => 1, 'sum_requests' => 100.0, 'sum_payments' => 0.0, 'residual' => 100.0,
             'sum_requests_hc' => 2500.0, 'sum_payments_hc' => 0.0, 'residual_hc' => 2500.0],
        ], $rows);
    }
}
