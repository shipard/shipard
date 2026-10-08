<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\WorkOrders\KindsViewer;
use Shipard\Module\Economy\WorkOrders\WorkOrderSeriesViewer;
use Shipard\Module\Economy\WorkOrders\WorkOrderTypes;

/**
 * Viewery Nastavení → Zakázky: filtr viewGroup s aliasem, hledání, řádek
 * s typem druhu, vzorcem a badge stavu, detail s popisky z cfgItem.
 */
class WorkOrderSettingsViewersTest extends TestCase
{
    private const STATES = [
        '10' => ['stateName' => 'Koncept', 'stateStyle' => 'concept', 'mainState' => 1, 'viewGroup' => 'active', 'goto' => [40]],
        '40' => ['stateName' => 'V pořádku', 'stateStyle' => 'done', 'mainState' => 3, 'viewGroup' => 'active', 'goto' => [70]],
        '70' => ['stateName' => 'V archívu', 'stateStyle' => 'archive', 'mainState' => 4, 'viewGroup' => 'archive', 'goto' => []],
    ];

    private function config(): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            [WorkOrderTypes::CFG_ITEM, ['project' => ['name' => 'Externí jednorázová']]],
            ['core.system.docStatesArchive', self::STATES],
            ['docs.core.resetScopes', ['fiscal_year' => ['name' => 'Po roce']]],
            ['economy.workOrders.viewerLabels', ['label.kind' => ['name' => 'Druh'], 'group.numbering' => ['name' => 'Číslování']]],
        ]);
        return $config;
    }

    /** @param list<mixed>|null $captured */
    private function db(?array &$captured = null, ?array $row = null): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(function (...$args) use (&$captured): array {
            $captured = $args;
            return [];
        });
        $db->method('fetchRow')->willReturn($row);
        return $db;
    }

    public function testKindsViewerFiltersViewGroupAndSearchesName(): void
    {
        $viewer = new KindsViewer($this->db($captured), 'economy_work_orders_kinds');
        $viewer->setConfig($this->config());

        $viewer->selectRows('serv', [['id' => 'viewGroup', 'value' => 'archive']], 1);

        $this->assertStringContainsString('k.`docState` IN (%i)', $captured[0]);
        $this->assertStringContainsString('k.`name`', $captured[0]);
        $this->assertSame(70, $captured[1]);
        $this->assertSame(['active', 'archive'], $viewer->getViewGroups());

        $row = $viewer->renderRow(['id' => 3, 'name' => 'Servis', 'type' => 'project', 'notice' => 'pozn.', 'docState' => 40]);
        $this->assertSame('Servis', $row['t1']);
        $this->assertSame(
            [['text' => 'Externí jednorázová', 'class' => 'primary'], ['text' => 'V pořádku', 'class' => 'success']],
            $row['t2'],
        );
        $this->assertSame('pozn.', $row['t3']);
        $this->assertSame('done', $row['stateStyle']);
    }

    public function testSeriesViewerJoinsKindAndRendersNumbering(): void
    {
        $record = [
            'id' => 9, 'kind' => 3, 'name' => 'Zakázky servisu', 'number_code' => 'ZS', 'number_pattern' => '%C%y%4',
            'reset_scope' => 'fiscal_year', 'valid_from' => '2026-01-01', 'valid_to' => null, 'notice' => null,
            'docState' => 10, 'kind_name' => 'Servis', 'kind_type' => 'project',
        ];
        $viewer = new WorkOrderSeriesViewer($this->db($captured, $record), 'economy_work_orders_number_series');
        $viewer->setConfig($this->config());

        $viewer->selectRows(null, [], 1);
        $this->assertStringContainsString('LEFT JOIN `economy_work_orders_kinds` k ON k.`id` = s.`kind`', $captured[0]);
        $this->assertStringContainsString('s.`docState` IN (%i, %i)', $captured[0]);

        $row = $viewer->renderRow($record);
        $this->assertSame('Zakázky servisu', $row['t1']);
        $this->assertSame('ZS', $row['i1']);
        $this->assertSame(
            [
                ['text' => 'Servis', 'class' => 'primary'],
                ['text' => 'Externí jednorázová', 'class' => 'muted'],
                ['text' => '%C%y%4'],
                ['text' => 'Po roce', 'class' => 'muted'],
                ['text' => '01.01.2026 – …', 'class' => 'muted'],
            ],
            $row['t2'],
        );

        $detail = $viewer->renderDetail(9);
        $groups = $detail['tabs'][0]['content']['groups'];
        $this->assertSame(['Identity', 'Číslování', 'Validity'], array_column($groups, 'title'));
        $this->assertSame(['label' => 'Druh', 'value' => 'Servis (Externí jednorázová)'], $groups[0]['items'][1]);
        $this->assertSame('Po roce', $groups[1]['items'][2]['value']);
    }
}
