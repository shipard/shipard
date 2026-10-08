<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\WorkOrders\WorkOrdersViewer;
use Shipard\Module\Economy\WorkOrders\WorkOrderTypes;

/**
 * Viewer zakázek: skupiny stavů z vlastní sady, spodní taby = řady
 * s předvyplněním řady (P6), filtry druh / typ, hledání, řádek a Přehled.
 */
class WorkOrdersViewerTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../../../modules/economy/workOrders';

    private const TYPES = [
        'project'  => ['name' => 'Externí jednorázová', 'external' => true, 'oneOff' => true, 'invoicing' => null],
        'periodic' => ['name' => 'Periodická', 'external' => true, 'oneOff' => false, 'invoicing' => 'periodic'],
    ];

    /** @param list<list<mixed>> $queries */
    private function viewer(?array &$queries = null, ?array $detailRow = null, array $fetchAllRows = []): WorkOrdersViewer
    {
        $queries = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(function (...$args) use (&$queries, $fetchAllRows): array {
            $queries[] = $args;
            return $fetchAllRows;
        });
        $db->method('fetchRow')->willReturn($detailRow);
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            [WorkOrderTypes::CFG_ITEM, self::TYPES],
            ['economy.workOrders.docStates', JsoncParser::parseFile(self::MODULE . '/config/docStates.jsonc')],
            ['economy.workOrders.viewerLabels', ['label.kind' => ['name' => 'Druh'], 'group.customer' => ['name' => 'Zákazník']]],
        ]);
        $viewer = new WorkOrdersViewer($db, 'economy_work_orders_heads');
        $viewer->setConfig($config);
        return $viewer;
    }

    public function testViewGroupsComeFromOwnStateSet(): void
    {
        $this->assertSame(['active', 'archive', 'trash'], $this->viewer()->getViewGroups());
    }

    public function testBottomTabsAreConfirmedSeriesWithSeriesDefault(): void
    {
        $viewer = $this->viewer($queries, fetchAllRows: [['id' => 1, 'name' => 'Projekty 2026'], ['id' => 2, 'name' => 'Režie']]);

        $this->assertSame([
            ['id' => 1, 'label' => 'Projekty 2026', 'newRecordDefaults' => ['number_series' => 1]],
            ['id' => 2, 'label' => 'Režie', 'newRecordDefaults' => ['number_series' => 2]],
        ], $viewer->getBottomTabs());
        $this->assertStringContainsString('WHERE s.`docState` = 40', $queries[0][0]);
    }

    public function testSelectRowsAppliesTabKindTypeAndSearch(): void
    {
        $viewer = $this->viewer($queries);

        $viewer->selectRows('hala', [
            ['id' => 'viewGroup', 'value' => 'archive'],
            ['id' => 'bottomTab', 'value' => '1'],
            ['id' => 'kind', 'value' => '11'],
            ['id' => 'type', 'value' => 'project'],
            ['id' => 'type', 'value' => 'bogus'],
        ], 1);

        $sql = $queries[0][0];
        $this->assertStringContainsString('w.`docState` IN (%i, %i)', $sql);
        $this->assertStringContainsString('w.`number_series` = %i', $sql);
        $this->assertStringContainsString('w.`kind` = %i', $sql);
        $this->assertSame(1, substr_count($sql, 'w.`type` = %s'), 'neznámý typ se nefiltruje');
        $this->assertStringContainsString('p.`full_name`', $sql);
        $this->assertStringContainsString('ORDER BY w.`docStateMain` ASC, w.`number` DESC', $sql);
        // archiv = Ukončeno (70) a Zrušeno (30)
        $this->assertSame([70, 30, 1, 11, 'project'], array_slice($queries[0], 1, 5));
    }

    public function testFiltersOfferKindsAndTypes(): void
    {
        $viewer = $this->viewer($queries, fetchAllRows: [['id' => 11, 'name' => 'Projekty']]);
        $filters = $viewer->getFilters();

        $this->assertSame(['kind', 'type'], array_column($filters, 'id'));
        $this->assertSame('Druh', $filters[0]['label']);
        $this->assertSame([['value' => 11, 'label' => 'Projekty']], $filters[0]['options']);
        $this->assertSame(['project', 'periodic'], array_column($filters[1]['options'], 'value'));
    }

    public function testRowAndDetail(): void
    {
        $record = [
            'id' => 5, 'number' => 'Z260007', 'title' => 'Rekonstrukce haly', 'type' => 'project',
            'date_start' => '2026-03-01', 'date_end' => null, 'docState' => 40, 'kind' => 11,
            'kind_name' => 'Projekty', 'customer_name' => 'Alfa s.r.o.', 'series_name' => 'Projekty 2026',
            'currency' => 'czk', 'payment_reference' => '2026007', 'parent' => 2, 'parent_number' => 'Z260002',
            'parent_title' => 'Areál', 'cost_center_code' => 'S1', 'cost_center_name' => 'Stavby', 'internal_note' => null,
        ];
        $viewer = $this->viewer($queries, $record);

        $row = $viewer->renderRow($record);
        $this->assertSame('Rekonstrukce haly', $row['t1']);
        $this->assertSame('Z260007', $row['i1']);
        $this->assertSame(
            [
                ['text' => 'Alfa s.r.o.', 'class' => 'primary'],
                ['text' => 'Projekty', 'class' => 'muted'],
                ['text' => '01.03.2026'],
                ['text' => 'Confirmed', 'class' => 'success'],
            ],
            $row['t2'],
        );
        $this->assertSame('done', $row['stateStyle']);

        $groups = $viewer->renderDetail(5)['tabs'][0]['content']['groups'];
        $this->assertSame(['Identity', 'Zákazník', 'Hierarchy', 'Validity'], array_column($groups, 'title'));
        $this->assertSame(['label' => 'Druh', 'value' => 'Projekty'], $groups[0]['items'][2]);
        $this->assertSame('CZK', $groups[1]['items'][1]['value']);
        $this->assertSame('Z260002 — Areál', $groups[2]['items'][0]['value']);
        $this->assertSame('S1 — Stavby', $groups[2]['items'][1]['value']);
    }
}
