<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentLockProvider;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\WorkOrders\WorkOrderRowLockProvider;

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
            [
                'economy_work_orders_kinds' => 457, 'economy_work_orders_number_series' => 458,
                'economy_work_orders_number_counters' => 459, 'economy_work_orders_heads' => 460,
                'economy_work_orders_rows' => 461, 'economy_work_orders_periods' => 462,
            ],
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
        $viewers = array_filter($module['settingsItems'], static fn(array $i): bool => isset($i['viewer']));
        $this->assertSame(
            ['economy.workOrders.kinds' => 'workOrders', 'economy.workOrders.numberSeries' => 'workOrders'],
            array_column($viewers, 'section', 'viewer'),
        );
        // Stránka nastavení periodické fakturace (fáze 2) v téže sekci.
        $pages = array_filter($module['settingsItems'], static fn(array $i): bool => isset($i['page']));
        $this->assertSame(['workOrdersInvoicing' => 'workOrders'], array_column($pages, 'section', 'page'));
        $this->assertSame(['workOrdersInvoicing'], array_column($module['settingsPages'], 'id'));

        $sections = JsoncParser::parseFile(self::MODULES . '/install/base/config/settingsSections.jsonc')['sections'];
        $orders = array_column($sections, 'order', 'id');
        $this->assertArrayHasKey('workOrders', $orders);
        $this->assertGreaterThan($orders['accounting'], $orders['workOrders']);
        $this->assertLessThan($orders['assets'], $orders['workOrders']);

        $install = JsoncParser::parseFile(self::MODULES . '/install/base/module.jsonc');
        $this->assertContains('economy.workOrders', $install['dependencies']);
    }

    public function testWorkOrdersSectionSitsBetweenSalesAndAssets(): void
    {
        $sections = JsoncParser::parseFile(self::MODULES . '/install/base/config/navSections.jsonc')['sections'];
        $orders = array_column($sections, 'order', 'id');
        $this->assertGreaterThan($orders['sales'], $orders['workOrders']);
        $this->assertLessThan($orders['assets'], $orders['workOrders']);

        $viewers = array_column(self::module()['viewers'], null, 'id');
        $this->assertSame('workOrders', $viewers['economy.workOrders.heads']['navSection']);
        $this->assertArrayNotHasKey('navSection', $viewers['economy.workOrders.kinds']);
    }

    public function testStateSetMatchesTheAgreedAutomaton(): void
    {
        // P2: Ukončeno a Zrušeno v archivu se stejným mainState, potvrzená
        // zakázka se do Konceptu nevrací, smazat jde jen koncept.
        $states = JsoncParser::parseFile(self::MODULE . '/config/docStates.jsonc');
        $goto = array_map(static fn(array $s): array => $s['goto'], $states);
        $this->assertSame(
            ['10' => [40, 90], '80' => [40, 70, 30], '40' => [80, 70, 30], '70' => [80], '30' => [80], '90' => [10]],
            $goto,
        );
        $this->assertSame('archive', $states['70']['viewGroup']);
        $this->assertSame('archive', $states['30']['viewGroup']);
        $this->assertSame($states['70']['mainState'], $states['30']['mainState']);
        $this->assertSame('archive', $states['70']['stateStyle']);
        $this->assertSame('cancelled', $states['30']['stateStyle']);
        foreach (['40', '70', '30', '90'] as $readOnly) {
            $this->assertSame(1, $states[$readOnly]['readOnly'], $readOnly);
        }

        $heads = JsoncParser::parseFile(self::MODULE . '/tables/economy_work_orders_heads.jsonc');
        $this->assertSame('economy.workOrders.docStates', $heads['docStates']['cfgItem']);
        $this->assertTrue($heads['stateTransitionsRunDocumentHooks']);
        $this->assertSame('{number} — {title}', $heads['displayPattern']);
        $this->assertSame('unique', $heads['indexes'][0]['type']);
        $this->assertSame([['column' => 'number']], $heads['indexes'][0]['columns']);
        $config = array_column(self::module()['config'], 'file', 'id');
        $this->assertSame('config/docStates.jsonc', $config['economy.workOrders.docStates']);
    }

    public function testRowsAreLockedByWorkOrderState(): void
    {
        // tasks/work-orders-rows-readonly.md: řádky předpisu podléhají zámku
        // podle stavu zakázky — provider nad tabulkou řádků, ne nad hlavičkou.
        $expected = [['table' => 'economy_work_orders_rows', 'class' => WorkOrderRowLockProvider::class]];
        $this->assertSame($expected, self::module()['documentLockProviders']);
        $this->assertSame($expected, ModuleDefinition::fromArray(self::module())->documentLockProviders);
        $this->assertInstanceOf(DocumentLockProvider::class, new WorkOrderRowLockProvider());
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
