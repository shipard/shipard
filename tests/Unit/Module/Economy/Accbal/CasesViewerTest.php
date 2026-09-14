<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accbal;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Accbal\CaseQuery;
use Shipard\Module\Economy\Accbal\CasesViewer;
use Shipard\Tests\Fixtures\Reports\FakeFiscalPeriodProvider;

/**
 * Viewer saldokonta po případech (#69 D1) nad mockem DB: GROUP BY klíče
 * ve vnitřním dotazu, filtry ve dvou úrovních (klíčové před GROUP BY,
 * případové nad agregátem), výchozí otevřenost, okno součtu partnera,
 * řazení dle partnera (D12), render řádků a footer sdílející podmínky.
 * Agregát nad reálnou DB kryje integrační CasesViewerTest.
 */
class CasesViewerTest extends TestCase
{
    /** @var list<array{sql: string, params: array}> */
    private array $queries = [];

    private function makeViewer(array $fetchAllRows = [], ?array $fetchRowResult = null): CasesViewer
    {
        $this->queries = [];

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, ...$params) use ($fetchAllRows): array {
                $this->queries[] = ['sql' => $sql, 'params' => $params];
                return $fetchAllRows;
            },
        );
        $db->method('fetchRow')->willReturnCallback(
            function (string $sql, ...$params) use ($fetchRowResult): ?array {
                $this->queries[] = ['sql' => $sql, 'params' => $params];
                return $fetchRowResult;
            },
        );

        $viewer = new CasesViewer($db, 'economy_accbal_ledger');
        $viewer->setLanguage('cs');
        return $viewer;
    }

    /** @return array<string, mixed> */
    private static function caseRow(array $over = []): array
    {
        return array_merge([
            'balance'             => 2,
            'fiscal_year'         => 1,
            'partner'             => 42,
            'payment_reference'   => '2026001',
            'specific_symbol'     => null,
            'currency'            => 'czk',
            'sum_requests'        => '12100.00',
            'sum_payments'        => '2100.00',
            'sum_requests_hc'     => '12100.00',
            'sum_payments_hc'     => '2100.00',
            'residual'            => '10000.00',
            'residual_hc'         => '10000.00',
            'due_date'            => '2026-07-15',
            'moves'               => 2,
            'row_id'              => 7,
            'home_currency'       => 'czk',
            'balance_name'        => 'Pohledávky z obchodních vztahů',
            'balance_short_name'  => 'Pohledávky',
            'balance_code'        => 'receivables',
            'partner_name'        => 'AKIMA, spol. s r.o.',
            'fiscal_year_name'    => '2026',
            'partner_residual_hc' => '9700.00',
        ], $over);
    }

    // ── selectRows: tvar dotazu ─────────────────────────────────────────────

    public function testSelectRowsGroupsByKeyAndOrdersByPartner(): void
    {
        $viewer = $this->makeViewer();
        $viewer->selectRows(null, [], 0);

        ['sql' => $sql, 'params' => $params] = $this->queries[0];
        $this->assertStringContainsString(
            'FROM (SELECT l.[balance], l.[fiscal_year], l.[partner], l.[payment_reference], l.[specific_symbol], l.[currency], SUM(',
            $sql,
            'případ = agregát klíče ve vnitřním dotazu',
        );
        $this->assertStringContainsString(
            ' GROUP BY l.[balance], l.[fiscal_year], l.[partner], l.[payment_reference], l.[specific_symbol], l.[currency]) c',
            $sql,
        );
        $this->assertStringContainsString('MIN(l.[id]) AS row_id', $sql, 'id řádku = nejnižší pohyb klíče');
        $this->assertStringContainsString(
            'SUM(c.`residual_hc`) OVER (PARTITION BY c.`partner`) AS partner_residual_hc',
            $sql,
            'součet zůstatku partnera pro skupinový řádek přes filtrovaný set',
        );
        // Výchozí = jen otevřené (frontend nemá výchozí hodnoty filtrů → obrácený checkbox).
        $this->assertStringContainsString(' WHERE c.[residual] <> 0 ORDER BY', $sql);
        // D12: primárně partner, uvnitř klíč případu.
        $this->assertStringContainsString(
            'ORDER BY ISNULL(p.`full_name`) ASC, p.`full_name` ASC, c.`partner` ASC,'
            . ' c.`fiscal_year` ASC, c.`balance` ASC, c.`currency` ASC,'
            . ' c.`payment_reference` ASC, c.`specific_symbol` ASC LIMIT 0, 51',
            $sql,
        );
        $this->assertSame([], $params);
    }

    public function testKeyLevelFiltersGoBeforeGroupBy(): void
    {
        $viewer = $this->makeViewer();
        $viewer->selectRows(null, [
            ['id' => 'viewGroup', 'value' => 'receivables'],
            ['id' => 'partner', 'value' => 'AKIMA'],
            ['id' => 'payment_reference', 'value' => '2026'],
        ], 0);

        ['sql' => $sql, 'params' => $params] = $this->queries[0];
        $inner = substr($sql, 0, (int) strpos($sql, ' GROUP BY '));
        $this->assertStringContainsString('WHERE b.`code` = %s AND p.`full_name` LIKE %s AND l.`payment_reference` LIKE %s', $inner);
        $this->assertSame(['receivables', '%AKIMA%', '2026%'], $params);
    }

    public function testViewGroupAllAndStaleActiveSkipCondition(): void
    {
        foreach (['all', 'active'] as $vg) {
            $viewer = $this->makeViewer();
            $viewer->selectRows(null, [['id' => 'viewGroup', 'value' => $vg]], 0);
            $this->assertStringNotContainsString('b.`code` = %s', $this->queries[0]['sql'], "viewGroup={$vg}");
        }
    }

    public function testIncludeClosedDropsOpennessCondition(): void
    {
        $viewer = $this->makeViewer();
        $viewer->selectRows(null, [['id' => 'include_closed', 'value' => '1']], 0);

        $sql = $this->queries[0]['sql'];
        $this->assertStringNotContainsString('c.[residual] <> 0', $sql);
        $this->assertStringContainsString(') c LEFT JOIN', $sql);
    }

    public function testKindAndOverdueAreCaseLevelConditions(): void
    {
        $viewer = $this->makeViewer();
        $viewer->selectRows(null, [
            ['id' => 'kind', 'value' => CaseQuery::KIND_OVERPAYMENT],
            ['id' => 'overdue', 'value' => '1'],
        ], 0);

        ['sql' => $sql, 'params' => $params] = $this->queries[0];
        $outer = substr($sql, (int) strpos($sql, ') c LEFT JOIN'));
        $this->assertStringContainsString(
            ' WHERE c.[residual] <> 0 AND c.[sum_requests] <> 0 AND c.[residual] < 0 AND c.[residual] > 0 AND c.[due_date] < %s',
            $outer,
        );
        $this->assertSame([date('Y-m-d')], $params, 'dny po splatnosti se počítají k dnešku, nic se neukládá');
    }

    public function testKindClosedImpliesIncludeClosed(): void
    {
        $viewer = $this->makeViewer();
        $viewer->selectRows(null, [['id' => 'kind', 'value' => CaseQuery::KIND_CLOSED]], 0);

        $sql = $this->queries[0]['sql'];
        $this->assertStringContainsString(' WHERE c.[residual] = 0 ORDER BY', $sql);
        $this->assertStringNotContainsString('c.[residual] <> 0', $sql);
    }

    public function testUnknownKindIsIgnored(): void
    {
        $viewer = $this->makeViewer();
        $viewer->selectRows(null, [['id' => 'kind', 'value' => 'nope']], 0);

        $this->assertStringContainsString(' WHERE c.[residual] <> 0 ORDER BY', $this->queries[0]['sql']);
    }

    // ── Grid: layout, sloupce, filtry ───────────────────────────────────────

    public function testDefaultLayoutIsGridWithoutSortableColumns(): void
    {
        $viewer = $this->makeViewer();
        $this->assertSame('grid', $viewer->getDefaultLayout());

        $columns = $viewer->getGridColumns();
        $this->assertSame(
            ['fiscal_year', 'payment_reference', 'specific_symbol', 'currency', 'sum_requests', 'sum_payments',
             'residual', 'residual_hc', 'due_date', 'overdue', 'moves', 'balance'],
            array_column($columns, 'id'),
        );
        foreach ($columns as $col) {
            $this->assertArrayNotHasKey('sortable', $col, $col['id']);
        }
        $this->assertSame([], $viewer->getToolbarActions(null), 'read-only');
    }

    public function testPeriodFilterIsFirstWithCurrentYearDefault(): void
    {
        $viewer = $this->makeViewer();
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $viewer->setFiscalPeriodProvider(new FakeFiscalPeriodProvider(
            [['id' => 2, 'name' => '2027'], ['id' => 1, 'name' => '2026']],
            [$today => ['id' => 1, 'name' => '2026']],
        ));

        $period = $viewer->getFilters()[0];

        $this->assertSame('fiscal_year', $period['id'], 'období je první filtr');
        $this->assertSame('select', $period['type']);
        $this->assertSame('Období', $period['label']);
        $this->assertSame([2, 1], array_column($period['options'], 'value'), 'nejnovější první');
        $this->assertSame('1', $period['default'], 'výchozí = rok obsahující dnešek');
    }

    public function testFiscalYearFilterIsKeyLevelConditionSharedByFooter(): void
    {
        $viewer = $this->makeViewer(fetchRowResult: []);
        $filters = [['id' => 'fiscal_year', 'value' => '1'], ['id' => 'viewGroup', 'value' => 'receivables']];

        $viewer->selectRows(null, $filters, 0);
        $viewer->renderGridFooter(null, $filters);

        foreach ($this->queries as $q) {
            $this->assertStringContainsString(
                'WHERE l.`fiscal_year` = %i AND b.`code` = %s GROUP BY',
                $q['sql'],
                'období před GROUP BY (součást klíče, idx_case)',
            );
            $this->assertSame([1, 'receivables'], $q['params']);
        }

        $this->makeViewer()->selectRows(null, [['id' => 'fiscal_year', 'value' => '']], 0);
        $this->assertStringNotContainsString('fiscal_year` =', $this->queries[0]['sql'], 'uvolněný filtr = všechna období');
    }

    public function testFiltersOfferKindsSeparately(): void
    {
        $filters = $this->makeViewer()->getFilters();

        $this->assertSame(['fiscal_year', 'partner', 'payment_reference', 'kind', 'overdue', 'include_closed'], array_column($filters, 'id'));
        $kind = $filters[3];
        $this->assertSame('select', $kind['type']);
        $this->assertSame(
            [CaseQuery::KIND_DEBT, CaseQuery::KIND_OVERPAYMENT, CaseQuery::KIND_UNREQUESTED, CaseQuery::KIND_CLOSED],
            array_column($kind['options'], 'value'),
            'úhrada bez předpisu je samostatný typ, ne schovaná mezi přeplatky',
        );
        $this->assertSame('Úhrada bez předpisu', $kind['options'][2]['label']);
    }

    // ── Grid: render řádků ──────────────────────────────────────────────────

    public function testRenderGridRowDebtWithPartnerSubtotal(): void
    {
        $row = $this->makeViewer()->renderGridRow(self::caseRow(['due_date' => '2099-12-31']));

        $this->assertSame(7, $row['id'], 'id = row_id (MIN id pohybů klíče)');
        $this->assertNull($row['stateStyle'], 'dluh v termínu bez proužku');
        $this->assertSame(
            ['key' => 'p42', 'label' => 'AKIMA, spol. s r.o. · zůstatek 9 700,00 CZK'],
            $row['group'],
        );
        $this->assertSame('2026', $row['cells']['fiscal_year']);
        $this->assertSame('CZK', $row['cells']['currency']);
        $this->assertSame(['text' => '12 100,00', 'class' => 'amount'], $row['cells']['sum_requests']);
        $this->assertSame(['text' => '10 000,00', 'class' => 'amount'], $row['cells']['residual']);
        $this->assertSame('31. 12. 2099', $row['cells']['due_date']);
        $this->assertNull($row['cells']['overdue']);
        $this->assertSame('2', $row['cells']['moves']);
        $this->assertSame('Pohledávky', $row['cells']['balance']);
    }

    public function testRenderGridRowOverdueDebtIsHighlighted(): void
    {
        $due = (new \DateTimeImmutable('today'))->modify('-10 days')->format('Y-m-d');
        $row = $this->makeViewer()->renderGridRow(self::caseRow(['due_date' => $due]));

        $this->assertSame('cancelled', $row['stateStyle'], 'po splatnosti = červený „pozor" z doc-state palety');
        $this->assertSame(['text' => '10', 'badge' => 'danger'], $row['cells']['overdue']);
    }

    public function testRenderGridRowOverpaymentAndClosed(): void
    {
        $viewer = $this->makeViewer();

        $over = $viewer->renderGridRow(self::caseRow([
            'sum_payments' => '15000.00', 'residual' => '-2900.00', 'residual_hc' => '-2900.00', 'due_date' => '2026-01-01',
        ]));
        $this->assertSame('concept', $over['stateStyle'], 'přeplatek odlišně od dluhu');
        $this->assertSame(['text' => '-2 900,00', 'class' => 'amount'], $over['cells']['residual']);
        $this->assertNull($over['cells']['overdue'], 'po splatnosti jen u dluhu');

        $closed = $viewer->renderGridRow(self::caseRow(['sum_payments' => '12100.00', 'residual' => '0.00', 'residual_hc' => '0.00']));
        $this->assertSame('archive', $closed['stateStyle']);

        $noPartner = $viewer->renderGridRow(self::caseRow(['partner' => null, 'partner_name' => null, 'partner_residual_hc' => '-500.00']));
        $this->assertSame('p0', $noPartner['group']['key']);
        $this->assertSame('(Bez partnera) · zůstatek -500,00 CZK', $noPartner['group']['label']);
    }

    public function testRenderRowListShape(): void
    {
        $row = $this->makeViewer()->renderRow(self::caseRow(['due_date' => '2099-12-31']));

        $this->assertSame(7, $row['id']);
        $this->assertSame('AKIMA, spol. s r.o.', $row['t1']);
        $this->assertSame('10 000,00 CZK', $row['i1']);
        $this->assertSame('Dluh', end($row['t2'])['text']);
    }

    // ── Footer ──────────────────────────────────────────────────────────────

    public function testRenderGridFooterSharesBothConditionLevels(): void
    {
        $viewer = $this->makeViewer(fetchRowResult: [
            'sum_requests' => 250000.0, 'sum_payments' => 100000.0, 'residual' => 150000.0, 'home_currency' => 'czk',
        ]);

        $footer = $viewer->renderGridFooter(null, [
            ['id' => 'viewGroup', 'value' => 'payables'],
            ['id' => 'kind', 'value' => CaseQuery::KIND_DEBT],
        ]);

        ['sql' => $sql, 'params' => $params] = $this->queries[0];
        $this->assertStringContainsString('SUM(c.`sum_requests_hc`) AS sum_requests, SUM(c.`sum_payments_hc`) AS sum_payments, SUM(c.`residual_hc`) AS residual', $sql);
        $this->assertStringContainsString('WHERE b.`code` = %s GROUP BY', $sql, 'klíčová podmínka ve vnitřním dotazu');
        $this->assertStringContainsString(') c WHERE c.[residual] <> 0 AND c.[residual] > 0', $sql, 'případové podmínky nad agregátem');
        $this->assertStringNotContainsString('LIMIT', $sql, 'přes všechny stránky');
        $this->assertSame(['payables'], $params);

        $this->assertSame(
            [['text' => 'Zůstatek', 'class' => 'muted'], ['text' => '150 000,00 CZK', 'class' => 'amount']],
            $footer['residual_hc'],
        );
        $this->assertSame([['text' => 'Předpisy', 'class' => 'muted'], ['text' => '250 000,00 CZK']], $footer['sum_requests']);
        $this->assertSame([['text' => 'Úhrady', 'class' => 'muted'], ['text' => '100 000,00 CZK']], $footer['sum_payments']);
    }
}
