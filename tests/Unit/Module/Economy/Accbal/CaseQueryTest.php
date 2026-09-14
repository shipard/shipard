<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accbal;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Accbal\CaseQuery;

/**
 * CaseQuery — definice případu (#69 D1/D10/D11): normalizace klíče, tvar
 * SQL podmínek rovnosti (IS NULL pro prázdný symbol, pořadí idx_case),
 * agregační výrazy, klasifikace otevřenosti a dny po splatnosti. Agregát
 * nad reálnou DB kryje integrační CaseQueryTest.
 */
class CaseQueryTest extends TestCase
{
    // ── Normalizace (D10) ───────────────────────────────────────────────────

    public function testSymbolIsTrimmedAndEmptyIsNull(): void
    {
        $this->assertSame('123', CaseQuery::normalizeSymbol(' 123 '));
        $this->assertNull(CaseQuery::normalizeSymbol(''));
        $this->assertNull(CaseQuery::normalizeSymbol('   '));
        $this->assertNull(CaseQuery::normalizeSymbol(null));
        $this->assertSame('42', CaseQuery::normalizeSymbol(42));
    }

    public function testCurrencyIsLowercased(): void
    {
        $this->assertSame('czk', CaseQuery::normalizeCurrency('CZK'));
        $this->assertSame('eur', CaseQuery::normalizeCurrency(' Eur '));
        $this->assertNull(CaseQuery::normalizeCurrency(''));
        $this->assertNull(CaseQuery::normalizeCurrency(null));
    }

    public function testNormalizeKeyTakesOnlyKeyColumns(): void
    {
        $key = CaseQuery::normalizeKey([
            'id'                => 7,
            'balance'           => '3',
            'fiscal_year'       => 1,
            'partner'           => '',
            'payment_reference' => ' VS1 ',
            'specific_symbol'   => '',
            'currency'          => 'CZK',
            'amount'            => 10.0,
        ]);

        $this->assertSame([
            'balance'           => 3,
            'fiscal_year'       => 1,
            'partner'           => null,
            'payment_reference' => 'VS1',
            'specific_symbol'   => null,
            'currency'          => 'czk',
        ], $key);
    }

    public function testNormalizeKeyKeepsPartialKeyPartial(): void
    {
        $this->assertSame(
            ['partner' => 42, 'payment_reference' => 'X'],
            CaseQuery::normalizeKey(['payment_reference' => 'X', 'partner' => 42]),
            'pořadí = KEY_COLUMNS, chybějící sloupce chybí dál',
        );
    }

    public function testNormalizeKeyAcceptsDibiRow(): void
    {
        $row = new \Dibi\Row(['balance' => 1, 'fiscal_year' => 2, 'partner' => 3, 'payment_reference' => 'A', 'specific_symbol' => null, 'currency' => 'eur']);

        $this->assertSame(
            ['balance' => 1, 'fiscal_year' => 2, 'partner' => 3, 'payment_reference' => 'A', 'specific_symbol' => null, 'currency' => 'eur'],
            CaseQuery::normalizeKey($row),
        );
    }

    // ── SQL podmínky ────────────────────────────────────────────────────────

    public function testKeyConditionsUseEqualityAndIsNull(): void
    {
        [$conds, $params] = CaseQuery::keyConditions([
            'balance' => 1, 'fiscal_year' => 5, 'partner' => 42,
            'payment_reference' => ' 20260001 ', 'specific_symbol' => '', 'currency' => 'CZK',
        ]);

        $this->assertSame([
            'l.[balance] = %i',
            'l.[fiscal_year] = %i',
            'l.[partner] = %i',
            'l.[payment_reference] = %s',
            'l.[specific_symbol] IS NULL',
            'l.[currency] = %s',
        ], $conds);
        $this->assertSame([1, 5, 42, '20260001', 'czk'], $params, 'IS NULL bez parametru, vstup normalizovaný');
    }

    public function testKeyConditionsForPartialKeyAndAlias(): void
    {
        [$conds, $params] = CaseQuery::keyConditions(['payment_reference' => 'X', 'partner' => 42], 'x');
        $this->assertSame(['x.[partner] = %i', 'x.[payment_reference] = %s'], $conds, 'pořadí idx_case i pro částečný klíč');
        $this->assertSame([42, 'X'], $params);

        [$conds] = CaseQuery::keyConditions(['partner' => null], '');
        $this->assertSame(['[partner] IS NULL'], $conds, 'bez aliasu');
    }

    public function testKeyColumnsSql(): void
    {
        $this->assertSame(
            'l.[balance], l.[fiscal_year], l.[partner], l.[payment_reference], l.[specific_symbol], l.[currency]',
            CaseQuery::keyColumnsSql(),
        );
    }

    public function testAggregateColumnsSql(): void
    {
        $sql = CaseQuery::aggregateColumnsSql('l');

        $this->assertStringContainsString('SUM(CASE WHEN l.[bal_side] = 0 THEN l.[amount] ELSE 0 END) AS sum_requests', $sql);
        $this->assertStringContainsString('SUM(CASE WHEN l.[bal_side] = 1 THEN l.[amount] ELSE 0 END) AS sum_payments', $sql);
        $this->assertStringContainsString('SUM(CASE WHEN l.[bal_side] = 0 THEN l.[amount] ELSE -l.[amount] END) AS residual', $sql);
        $this->assertStringContainsString('AS residual_hc', $sql);
        $this->assertStringContainsString('MIN(CASE WHEN l.[bal_side] = 0 THEN l.[due_date] END) AS due_date', $sql, 'splatnost jen z předpisů');
        $this->assertStringContainsString('COUNT(*) AS moves', $sql);
        $this->assertStringContainsString('MIN(l.[id]) AS row_id', $sql);
    }

    public function testKindConditionSql(): void
    {
        $this->assertSame('c.[residual] > 0', CaseQuery::kindConditionSql(CaseQuery::KIND_DEBT));
        $this->assertSame('c.[sum_requests] <> 0 AND c.[residual] < 0', CaseQuery::kindConditionSql(CaseQuery::KIND_OVERPAYMENT));
        $this->assertSame('c.[sum_requests] = 0 AND c.[residual] <> 0', CaseQuery::kindConditionSql(CaseQuery::KIND_UNREQUESTED));
        $this->assertSame('[residual] = 0', CaseQuery::kindConditionSql(CaseQuery::KIND_CLOSED, ''));
        $this->assertNull(CaseQuery::kindConditionSql('nope'));
        $this->assertSame('c.[residual] <> 0', CaseQuery::openConditionSql());
    }

    // ── Klasifikace ─────────────────────────────────────────────────────────

    public function testKindOf(): void
    {
        $this->assertSame(CaseQuery::KIND_DEBT, CaseQuery::kindOf(1000.00, 400.00));
        $this->assertSame(CaseQuery::KIND_CLOSED, CaseQuery::kindOf(1000.00, 1000.00));
        $this->assertSame(CaseQuery::KIND_CLOSED, CaseQuery::kindOf(1000.00, 1000.004), 'tolerance půl haléře');
        $this->assertSame(CaseQuery::KIND_OVERPAYMENT, CaseQuery::kindOf(1000.00, 1200.00));
        $this->assertSame(CaseQuery::KIND_UNREQUESTED, CaseQuery::kindOf(0.0, 500.00));
        $this->assertSame(CaseQuery::KIND_CLOSED, CaseQuery::kindOf(0.0, 0.0));
    }

    public function testDecorateAddsKindOpenAndOverdue(): void
    {
        $today = new \DateTimeImmutable('2026-07-10');

        $debt = CaseQuery::decorate([
            'sum_requests' => '1000.00', 'sum_payments' => '400.00',
            'sum_requests_hc' => '1000.00', 'sum_payments_hc' => '400.00',
            'residual' => '600.00', 'residual_hc' => '600.00',
            'due_date' => '2026-06-30', 'moves' => '2',
        ], $today);
        $this->assertSame(600.0, $debt['residual']);
        $this->assertSame(2, $debt['moves']);
        $this->assertSame(CaseQuery::KIND_DEBT, $debt['kind']);
        $this->assertTrue($debt['is_open']);
        $this->assertSame(10, $debt['days_overdue']);

        $overpaid = CaseQuery::decorate(['sum_requests' => 100, 'sum_payments' => 150, 'due_date' => '2026-06-30'], $today);
        $this->assertSame(CaseQuery::KIND_OVERPAYMENT, $overpaid['kind']);
        $this->assertSame(0, $overpaid['days_overdue'], 'po splatnosti jen u dluhu');

        $closed = CaseQuery::decorate(['sum_requests' => 100, 'sum_payments' => 100, 'due_date' => '2026-01-01'], $today);
        $this->assertFalse($closed['is_open']);
        $this->assertSame(0, $closed['days_overdue']);
    }

    public function testDaysOverdue(): void
    {
        $today = new \DateTimeImmutable('2026-07-10 15:00');

        $this->assertSame(10, CaseQuery::daysOverdue('2026-06-30', $today));
        $this->assertSame(10, CaseQuery::daysOverdue(new \DateTime('2026-06-30'), $today));
        $this->assertSame(0, CaseQuery::daysOverdue('2026-07-10', $today), 'den splatnosti ještě není po splatnosti');
        $this->assertSame(0, CaseQuery::daysOverdue('2026-08-01', $today));
        $this->assertSame(0, CaseQuery::daysOverdue(null, $today));
        $this->assertSame(0, CaseQuery::daysOverdue('', $today));
    }

    // ── Dotazy nad mockem ───────────────────────────────────────────────────

    public function testCaseOfGroupsByKeyAndDecorates(): void
    {
        $queries = [];
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturnCallback(function (...$args) use (&$queries) {
            $queries[] = ['sql' => (string) $args[0], 'params' => array_slice($args, 1)];
            return new \Dibi\Row([
                'balance' => 1, 'fiscal_year' => 5, 'partner' => 42, 'payment_reference' => 'X', 'specific_symbol' => null, 'currency' => 'czk',
                'sum_requests' => '500.00', 'sum_payments' => '800.00', 'sum_requests_hc' => '500.00', 'sum_payments_hc' => '800.00',
                'residual' => '-300.00', 'residual_hc' => '-300.00', 'due_date' => '2026-06-30', 'moves' => 2, 'row_id' => 11, 'home_currency' => 'czk',
            ]);
        });

        $case = (new CaseQuery($db))->caseOf([
            'balance' => 1, 'fiscal_year' => 5, 'partner' => 42, 'payment_reference' => ' X ', 'specific_symbol' => '', 'currency' => 'CZK',
        ]);

        $this->assertNotNull($case);
        $this->assertSame(CaseQuery::KIND_OVERPAYMENT, $case['kind']);
        $this->assertSame(-300.0, $case['residual']);
        $this->assertSame(11, $case['row_id']);
        $q = $queries[0];
        $this->assertStringContainsString('FROM [economy_accbal_ledger] l', $q['sql']);
        $this->assertStringContainsString('l.[specific_symbol] IS NULL', $q['sql']);
        $this->assertStringContainsString('GROUP BY l.[balance], l.[fiscal_year], l.[partner], l.[payment_reference], l.[specific_symbol], l.[currency]', $q['sql']);
        $this->assertSame([1, 5, 42, 'X', 'czk'], $q['params']);
    }

    public function testCaseOfWithoutMovesIsNull(): void
    {
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturn(null);

        $this->assertNull((new CaseQuery($db))->caseOf([
            'balance' => 1, 'fiscal_year' => 5, 'partner' => 42, 'payment_reference' => 'X', 'specific_symbol' => null, 'currency' => 'czk',
        ]));
    }

    public function testCaseOfRejectsIncompleteKey(): void
    {
        $db = $this->createMock(\Dibi\Connection::class);
        $this->expectException(\InvalidArgumentException::class);

        (new CaseQuery($db))->caseOf(['partner' => 42, 'payment_reference' => 'X']);
    }

    public function testKeyOfRowNormalizesLedgerRow(): void
    {
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturn(new \Dibi\Row([
            'balance' => '1', 'fiscal_year' => '5', 'partner' => '42', 'payment_reference' => 'X', 'specific_symbol' => null, 'currency' => 'czk',
        ]));

        $this->assertSame(
            ['balance' => 1, 'fiscal_year' => 5, 'partner' => 42, 'payment_reference' => 'X', 'specific_symbol' => null, 'currency' => 'czk'],
            (new CaseQuery($db))->keyOfRow(11),
        );
    }
}
