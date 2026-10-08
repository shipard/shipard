<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Utils\JsoncParser;

/**
 * Deklarace modulu economy.workOrders nad skutečnými soubory
 * (tasks/work-orders-phase1.md §1–§2, P1): tabulky s unikátními tableId,
 * druhy a řady v Nastavení → Zakázky, modul v install.base, cfgItem typů
 * se čtyřmi typy, čítače jako u dokladů.
 */
class WorkOrdersModuleTest extends TestCase
{
    private const MODULES = __DIR__ . '/../../../../../modules';
    private const MODULE = self::MODULES . '/economy/workOrders';

    /** @return array<string, mixed> */
    private static function module(): array
    {
        return JsoncParser::parseFile(self::MODULE . '/module.jsonc');
    }

    public function testModuleDefinitionParsesAndDeclaresTables(): void
    {
        $module = ModuleDefinition::fromArray(self::module());

        $this->assertSame('economy.workOrders', $module->id);
        foreach (['core.system', 'core.attachments', 'base.persons', 'economy.codebooks', 'docs.core', 'economy.accounting'] as $dependency) {
            $this->assertContains($dependency, $module->dependencies, $dependency);
        }

        $ids = [];
        foreach ($module->tables as $table) {
            $def = TableDefinition::fromArray(JsoncParser::parseFile(self::MODULE . '/tables/' . $table . '.jsonc'));
            $this->assertSame($table, basename($table), $table);
            $ids[$table] = $def->tableId;
        }
        $this->assertSame(
            ['economy_work_orders_kinds' => 457, 'economy_work_orders_number_series' => 458, 'economy_work_orders_number_counters' => 459],
            $ids,
        );
    }

    public function testTableIdsAreUniqueAcrossAllModules(): void
    {
        $seen = [];
        foreach (glob(self::MODULES . '/*/*/tables/*.jsonc') as $file) {
            $id = (int) (JsoncParser::parseFile($file)['tableId'] ?? 0);
            $this->assertArrayNotHasKey($id, $seen, "tableId {$id}: {$file} i " . ($seen[$id] ?? ''));
            $seen[$id] = $file;
        }
    }

    public function testKindsAndSeriesLiveInWorkOrdersSettingsSection(): void
    {
        $module = self::module();
        $items = array_column($module['settingsItems'], 'section', 'viewer');
        $this->assertSame(
            ['economy.workOrders.kinds' => 'workOrders', 'economy.workOrders.numberSeries' => 'workOrders'],
            $items,
        );

        $sections = JsoncParser::parseFile(self::MODULES . '/install/base/config/settingsSections.jsonc')['sections'];
        $orders = array_column($sections, 'order', 'id');
        $this->assertArrayHasKey('workOrders', $orders);
        $this->assertGreaterThan($orders['accounting'], $orders['workOrders']);
        $this->assertLessThan($orders['assets'], $orders['workOrders']);

        $install = JsoncParser::parseFile(self::MODULES . '/install/base/module.jsonc');
        $this->assertContains('economy.workOrders', $install['dependencies']);
    }

    public function testTypesConfigHasFourTypesWithFlags(): void
    {
        $types = JsoncParser::parseFile(self::MODULE . '/config/types.jsonc');
        $this->assertSame(['periodic', 'project', 'overhead', 'internal'], array_keys($types));
        foreach ($types as $key => $type) {
            $this->assertIsBool($type['external'], $key);
            $this->assertIsBool($type['oneOff'], $key);
            $this->assertArrayHasKey('invoicing', $type, $key);
            $this->assertArrayHasKey('name:cs', $type, $key);
        }
        $config = array_column(self::module()['config'], 'file', 'id');
        $this->assertSame('config/types.jsonc', $config['economy.workOrders.types']);
    }

    public function testCountersMirrorDocumentCounters(): void
    {
        $counters = JsoncParser::parseFile(self::MODULE . '/tables/economy_work_orders_number_counters.jsonc');
        $docCounters = JsoncParser::parseFile(self::MODULES . '/docs/core/tables/docs_core_number_counters.jsonc');

        $this->assertTrue($counters['hideFromNavigation']);
        $this->assertSame(array_column($docCounters['columns'], 'id'), array_column($counters['columns'], 'id'));
        $this->assertSame('economy_work_orders_number_series', array_column($counters['columns'], 'reference', 'id')['number_series']);
        $this->assertSame(
            [['column' => 'number_series'], ['column' => 'fiscal_year']],
            $counters['indexes'][0]['columns'],
        );
        $this->assertSame('unique', $counters['indexes'][0]['type']);

        $series = JsoncParser::parseFile(self::MODULE . '/tables/economy_work_orders_number_series.jsonc');
        $columns = array_column($series['columns'], null, 'id');
        $this->assertSame('docs.core.resetScopes', $columns['reset_scope']['cfgItem']);
        $this->assertSame('fiscal_year', $columns['reset_scope']['default']);
        $this->assertSame('economy_work_orders_kinds', $columns['kind']['reference']);
    }
}
