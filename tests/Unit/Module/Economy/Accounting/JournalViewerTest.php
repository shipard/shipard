<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Accounting\JournalViewer;
use Shipard\Tests\Fixtures\Reports\FakeFiscalPeriodProvider;

/**
 * Viewer účetního deníku (Fáze 3): read-only seznam s filtry (fiskální
 * rok/měsíc, prefix účtu, partner, jen chyby) a fulltextem, render řádků
 * vč. chybových a cizoměnových, detail properties s akcí Otevřít doklad.
 */
class JournalViewerTest extends TestCase
{
    /** @var list<array{sql: string, params: array}> */
    private array $queries = [];

    private function makeViewer(array $fetchAllRows = [], ?array $detailRow = null): JournalViewer
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
            function (string $sql, ...$params) use ($detailRow): ?array {
                $this->queries[] = ['sql' => $sql, 'params' => $params];
                return $detailRow;
            },
        );

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => match ($id) {
                'docs.core.docTypes'      => ['invni' => ['name' => 'Faktura přijatá']],
                'docs.core.rowOperations' => ['purchase.goods' => ['name' => 'Nákup zboží']],
                default                   => null,
            },
        );

        $viewer = new JournalViewer($db, 'economy_accounting_journal');
        $viewer->setConfig($config);
        $viewer->setLanguage('cs');
        return $viewer;
    }

    // ── selectRows: filtry ──────────────────────────────────────────────────

    public function testSelectRowsWithoutFiltersHasDefaultOrderAndLimit(): void
    {
        $viewer = $this->makeViewer();
        $viewer->selectRows(null, [], 0);

        $sql = $this->queries[0]['sql'];
        $this->assertStringNotContainsString('WHERE', $sql);
        $this->assertStringContainsString('ORDER BY j.`accounting_date` DESC, j.`id` DESC', $sql);
        $this->assertStringContainsString('LIMIT 0, 51', $sql, 'pageSize + 1 kvůli hasMore');
    }

    public function testFiscalPeriodAndErrorFilters(): void
    {
        $viewer = $this->makeViewer();
        $viewer->selectRows(null, [
            ['id' => 'fiscal_year', 'value' => '3'],
            ['id' => 'fiscal_month', 'value' => '15'],
            ['id' => 'only_errors', 'value' => '1'],
        ], 0);

        ['sql' => $sql, 'params' => $params] = $this->queries[0];
        $this->assertStringContainsString('j.`fiscal_year` = %i', $sql);
        $this->assertStringContainsString('j.`fiscal_month` = %i', $sql);
        $this->assertStringContainsString('j.`is_error` = 1', $sql);
        $this->assertSame([3, 15], $params);
    }

    public function testAccountFilterIsPrefixMatchPartnerIsContains(): void
    {
        $viewer = $this->makeViewer();
        $viewer->selectRows(null, [
            ['id' => 'account', 'value' => '504'],
            ['id' => 'partner', 'value' => 'Tech'],
        ], 0);

        ['sql' => $sql, 'params' => $params] = $this->queries[0];
        $this->assertStringContainsString('j.`account_number` LIKE %s', $sql);
        $this->assertStringContainsString('p.`full_name` LIKE %s', $sql);
        $this->assertSame(['504%', '%Tech%'], $params);
    }

    public function testUncheckedErrorFilterAndEmptyValuesAreIgnored(): void
    {
        $viewer = $this->makeViewer();
        $viewer->selectRows(null, [
            ['id' => 'only_errors', 'value' => ''],
            ['id' => 'fiscal_year', 'value' => ''],
        ], 0);

        $this->assertStringNotContainsString('WHERE', $this->queries[0]['sql']);
    }

    public function testSearchCoversTextDocNumberAndAccount(): void
    {
        $viewer = $this->makeViewer();
        $viewer->selectRows('518', [], 0);

        ['sql' => $sql, 'params' => $params] = $this->queries[0];
        $this->assertStringContainsString(
            '(j.`text` LIKE %s OR j.`doc_number` LIKE %s OR j.`account_number` LIKE %s OR j.`payment_reference` LIKE %s)',
            $sql,
        );
        $this->assertSame(['%518%', '%518%', '%518%', '%518%'], $params);
    }

    // ── renderRow ───────────────────────────────────────────────────────────

    private function journalRow(array $overrides = []): array
    {
        return array_merge([
            'id'              => 42,
            'accounting_date' => '2026-05-28',
            'doc_head'        => 7,
            'doc_number'      => 'FP-2026-0016',
            'account_number'  => '518100',
            'text'            => 'Konzultace',
            'money_dr'        => '6000.00',
            'money_cr'        => '0.00',
            'currency'        => 'czk',
            'money_dr_cur'    => '0.00',
            'money_cr_cur'    => '0.00',
            'is_error'        => 0,
            'partner_name'    => 'Česká Tech, s.r.o.',
        ], $overrides);
    }

    public function testRenderRowCarriesTextAccountDateAndAmount(): void
    {
        $row = $this->makeViewer()->renderRow($this->journalRow());

        $this->assertSame(42, $row['id']);
        $this->assertSame('Konzultace', $row['t1']);
        $this->assertSame('518100', $row['i1']);
        $this->assertSame('done', $row['stateStyle']);
        $this->assertSame(
            [
                ['text' => '28. 5. 2026'],
                ['text' => 'FP-2026-0016', 'class' => 'muted'],
                ['text' => 'Česká Tech, s.r.o.', 'class' => 'muted'],
            ],
            $row['t2'],
        );
        $this->assertSame(
            [
                ['text' => 'MD', 'class' => 'muted'],
                ['text' => '6 000,00', 'class' => 'amount'],
            ],
            $row['i2'],
        );
    }

    public function testErrorRowGetsErrorStyleAndWarningChip(): void
    {
        $row = $this->makeViewer()->renderRow($this->journalRow([
            'account_number' => '504???',
            'is_error'       => 1,
        ]));

        $this->assertSame('error', $row['stateStyle']);
        $this->assertContains(['text' => '⚠', 'class' => 'danger'], $row['t2']);
    }

    public function testForeignCurrencyRowAppendsCurAmount(): void
    {
        $row = $this->makeViewer()->renderRow($this->journalRow([
            'money_dr'     => '0.00',
            'money_cr'     => '2450.00',
            'currency'     => 'eur',
            'money_cr_cur' => '100.00',
        ]));

        $this->assertSame(
            [
                ['text' => 'DAL', 'class' => 'muted'],
                ['text' => '2 450,00', 'class' => 'amount'],
                ['text' => '100,00 EUR', 'class' => 'muted'],
            ],
            $row['i2'],
        );
    }

    // ── Grid layout (pilot F1) ──────────────────────────────────────────────

    public function testGridDefaultLayoutAndColumnsShape(): void
    {
        $viewer = $this->makeViewer();

        $this->assertSame('grid', $viewer->getDefaultLayout());

        $columns = $viewer->getGridColumns();
        $this->assertSame(
            ['accounting_date', 'doc_number', 'account_number', 'money_dr', 'money_cr', 'payment_reference', 'partner_name', 'text'],
            array_column($columns, 'id'),
        );
        $byId = array_column($columns, null, 'id');
        $this->assertSame('right', $byId['money_dr']['align']);
        $this->assertSame('right', $byId['money_cr']['align']);
        $this->assertTrue($byId['text']['grow']);
        $this->assertSame('Datum', $byId['accounting_date']['label'], 'cs labely');
    }

    public function testSortableColumns(): void
    {
        $columns = $this->makeViewer()->getGridColumns();

        $sortable = array_column(
            array_filter($columns, static fn (array $c): bool => ($c['sortable'] ?? false) === true),
            'id',
        );
        $this->assertSame(['accounting_date', 'account_number', 'money_dr', 'money_cr'], $sortable);
    }

    public function testInjectedSortOverridesOrderByWithUniqueTail(): void
    {
        $viewer = $this->makeViewer();
        $viewer->setSort(['column' => 'money_dr', 'dir' => 'desc']);
        $viewer->selectRows(null, [], 0);

        $sql = $this->queries[0]['sql'];
        $this->assertStringContainsString('ORDER BY j.`money_dr` DESC, j.`id` DESC', $sql);
        $this->assertStringNotContainsString('j.`accounting_date` DESC', $sql);
    }

    public function testSortOutsideMapKeepsDefaultOrder(): void
    {
        $viewer = $this->makeViewer();
        $viewer->setSort(['column' => 'text', 'dir' => 'asc']);
        $viewer->selectRows(null, [], 0);

        $this->assertStringContainsString(
            'ORDER BY j.`accounting_date` DESC, j.`id` DESC',
            $this->queries[0]['sql'],
        );
    }

    public function testRenderGridRowMapsCellsWithFormatting(): void
    {
        $row = $this->makeViewer()->renderGridRow($this->journalRow());

        $this->assertSame(42, $row['id']);
        $this->assertNull($row['stateStyle'], 'Proužek u deníku nenese informaci');
        $this->assertNull($row['rowClass']);
        $this->assertSame('28. 5. 2026', $row['cells']['accounting_date']);
        $this->assertSame(['text' => 'FP-2026-0016', 'class' => 'primary'], $row['cells']['doc_number']);
        $this->assertSame('518100', $row['cells']['account_number']);
        $this->assertSame([['text' => '6 000,00', 'class' => 'amount']], $row['cells']['money_dr']);
        $this->assertNull($row['cells']['money_cr'], 'Nulová strana = prázdná buňka');
        $this->assertSame('Česká Tech, s.r.o.', $row['cells']['partner_name']);
        $this->assertSame('Konzultace', $row['cells']['text']);
    }

    public function testRenderGridRowErrorGetsErrorRowClass(): void
    {
        $row = $this->makeViewer()->renderGridRow($this->journalRow(['is_error' => 1]));

        $this->assertSame('error', $row['rowClass']);
    }

    public function testRenderGridRowForeignCurrencyAppendsMutedSpan(): void
    {
        $row = $this->makeViewer()->renderGridRow($this->journalRow([
            'money_dr'     => '0.00',
            'money_cr'     => '2450.00',
            'currency'     => 'eur',
            'money_cr_cur' => '100.00',
        ]));

        $this->assertNull($row['cells']['money_dr']);
        $this->assertSame(
            [
                ['text' => '2 450,00', 'class' => 'amount'],
                ['text' => '100,00 EUR', 'class' => 'muted'],
            ],
            $row['cells']['money_cr'],
        );
    }

    public function testGridFooterSharesWhereConditionsWithSelectRows(): void
    {
        $viewer = $this->makeViewer([], ['sum_dr' => '6000.00', 'sum_cr' => '6000.00']);
        $search = '518';
        $filters = [
            ['id' => 'fiscal_year', 'value' => '3'],
            ['id' => 'partner', 'value' => 'Tech'],
            ['id' => 'only_errors', 'value' => '1'],
        ];

        $viewer->selectRows($search, $filters, 0);
        $footer = $viewer->renderGridFooter($search, $filters);

        [$rowsQuery, $footerQuery] = $this->queries;
        $this->assertStringContainsString('SUM(j.`money_dr`)', $footerQuery['sql']);
        $this->assertSame(
            $this->extractWhere($rowsQuery['sql']),
            $this->extractWhere($footerQuery['sql']),
            'Footer agreguje se stejnou WHERE skladbou jako selectRows',
        );
        $this->assertSame($rowsQuery['params'], $footerQuery['params']);

        $this->assertSame('Σ', $footer['accounting_date']);
        $this->assertSame(['text' => '6 000,00', 'class' => 'amount'], $footer['money_dr']);
        $this->assertSame(['text' => '6 000,00', 'class' => 'amount'], $footer['money_cr']);
    }

    /** WHERE část SQL bez ORDER BY/LIMIT — pro porovnání rows vs. footer. */
    private function extractWhere(string $sql): string
    {
        $start = strpos($sql, ' WHERE ');
        $this->assertNotFalse($start, 'Dotaz má WHERE');
        $where = substr($sql, $start);
        $end = strpos($where, ' ORDER BY');
        return $end !== false ? substr($where, 0, $end) : $where;
    }

    // ── renderDetail ────────────────────────────────────────────────────────

    public function testDetailRendersPropertiesAndOpenDocAction(): void
    {
        $detailRow = $this->journalRow([
            'doc_type'         => 'invni',
            'operation'        => 'purchase.goods',
            'account'          => 9,
            'account_name'     => 'Ostatní služby',
            'fiscal_year'      => 3,
            'fiscal_year_name' => '2026',
            'fiscal_month'     => 15,
            'calendar_year'    => 2026,
            'calendar_month'   => 5,
            'partner'          => 5,
        ]);
        $detail = $this->makeViewer(detailRow: $detailRow)->renderDetail(42);

        $this->assertCount(1, $detail['tabs']);
        $content = $detail['tabs'][0]['content'];
        $this->assertSame('properties', $content['type']);

        $titles = array_column($content['groups'], 'title');
        $this->assertSame(['Zápis', 'Částky', 'Doklad'], $titles);

        $entry = array_column($content['groups'][0]['items'], 'value', 'label');
        $this->assertSame('518100 — Ostatní služby', $entry['Účet']);
        $this->assertSame('Nákup zboží', $entry['Operace']);
        $this->assertSame('5/2026', $entry['Fiskální měsíc']);
        $this->assertArrayNotHasKey('Chybový řádek', $entry);

        $amounts = array_column($content['groups'][1]['items'], 'value', 'label');
        $this->assertSame('6 000,00', $amounts['MD']);
        $this->assertSame('0,00', $amounts['DAL']);
        $this->assertArrayNotHasKey('MD CZK', $amounts, 'Domácí doklad nemá cur položky');

        $this->assertSame([[
            'id'       => 'open_doc',
            'label'    => 'Otevřít doklad',
            'kind'     => 'open_viewer',
            'viewerId' => 'docs.core.heads',
            'recordId' => 7,
            'variant'  => 'secondary',
        ]], $detail['actions']);
    }

    public function testDetailMarksErrorLineAndForeignAmounts(): void
    {
        $detailRow = $this->journalRow([
            'is_error'     => 1,
            'currency'     => 'eur',
            'money_dr_cur' => '100.00',
        ]);
        $detail = $this->makeViewer(detailRow: $detailRow)->renderDetail(42);
        $content = $detail['tabs'][0]['content'];

        $entry = array_column($content['groups'][0]['items'], 'value', 'label');
        $this->assertSame('⚠ Ano', $entry['Chybový řádek']);

        $amounts = array_column($content['groups'][1]['items'], 'value', 'label');
        $this->assertSame('100,00', $amounts['MD EUR']);
        $this->assertSame('0,00', $amounts['DAL EUR']);
    }

    // ── getFilters / read-only ──────────────────────────────────────────────

    public function testGetFiltersBuildsPeriodOptionsWithParentLinkAndCurrentYearDefault(): void
    {
        // Roky jdou z provideru (fake), měsíce z mockovaného fetchAll.
        $viewer = $this->makeViewer([
            ['id' => 15, 'fiscal_year' => 3, 'calendar_year' => 2026, 'calendar_month' => 5],
        ]);
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $viewer->setFiscalPeriodProvider(new FakeFiscalPeriodProvider(
            [['id' => 4, 'name' => '2027'], ['id' => 3, 'name' => '2026']],
            [$today => ['id' => 3, 'name' => '2026']],
        ));
        $filters = $viewer->getFilters();

        $this->assertSame(
            ['fiscal_year', 'fiscal_month', 'account', 'partner', 'payment_reference', 'only_errors'],
            array_column($filters, 'id'),
        );
        $this->assertSame(['select', 'select', 'text', 'text', 'text', 'checkbox'], array_column($filters, 'type'));

        $this->assertSame([['value' => 4, 'label' => '2027'], ['value' => 3, 'label' => '2026']], $filters[0]['options']);
        $this->assertSame('3', $filters[0]['default'], 'deník se otevře s aktuálním rokem');
        $this->assertSame('fiscal_year', $filters[1]['parentFilter']);
        $this->assertArrayNotHasKey('default', $filters[1], 'měsíc bez výchozí hodnoty');
        $this->assertSame([['value' => 15, 'label' => '5/2026', 'parent' => 3]], $filters[1]['options']);
    }

    public function testViewerIsReadOnly(): void
    {
        $viewer = $this->makeViewer();
        $this->assertSame([], $viewer->getToolbarActions(null), 'Žádné Add v seznamu');
        $this->assertSame([], $viewer->getToolbarActions(['id' => 1]), 'Žádné Open na řádku');
        $this->assertSame([], $viewer->getViewGroups(), 'Bez docState tabů');
    }
}
