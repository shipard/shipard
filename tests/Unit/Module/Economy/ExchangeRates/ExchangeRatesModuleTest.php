<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\ExchangeRates;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\ExchangeRates\Checks\ListMissingCheck;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListImporter;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListsViewer;

/**
 * Deklarace modulu economy.exchangeRates nad skutečnými soubory
 * (tasks/exchange-rates-core.md §3, §9): dvě tabulky s unikátními tableId
 * a vazbou hlavička → hodnoty, viewer v Nastavení → Účetnictví, modul
 * v install.base a v závislostech docs.core, cfgItemy.
 */
class ExchangeRatesModuleTest extends TestCase
{
    private const MODULES = __DIR__ . '/../../../../../modules';
    private const MODULE = self::MODULES . '/economy/exchangeRates';

    /** @return array<string, mixed> */
    private static function module(): array
    {
        return JsoncParser::parseFile(self::MODULE . '/module.jsonc');
    }

    public function testModuleDefinitionParsesAndDeclaresTables(): void
    {
        $module = ModuleDefinition::fromArray(self::module());

        $this->assertSame('economy.exchangeRates', $module->id);
        $this->assertSame(['core.system', 'world.base'], $module->dependencies);

        $ids = [];
        foreach ($module->tables as $table) {
            $def = TableDefinition::fromArray(JsoncParser::parseFile(self::MODULE . '/tables/' . $table . '.jsonc'));
            $ids[$table] = $def->tableId;
            $this->assertTrue($def->systemManaged, "{$table}: převzatá data, generické CRUD odmítnout");
        }
        $this->assertSame(
            ['economy_exchange_rates_lists' => 464, 'economy_exchange_rates_values' => 465],
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

    public function testListsOwnValuesAsChildTable(): void
    {
        $lists = TableDefinition::fromArray(JsoncParser::parseFile(self::MODULE . '/tables/economy_exchange_rates_lists.jsonc'));
        $this->assertSame(
            [['table' => ExchangeRateListImporter::TABLE_VALUES, 'foreignKey' => 'list', 'dataKey' => ExchangeRateListImporter::VALUES_KEY]],
            $lists->childTables,
        );
        $this->assertNull($lists->docStates, 'bez stavů dokladu');

        $raw = JsoncParser::parseFile(self::MODULE . '/tables/economy_exchange_rates_lists.jsonc');
        $this->assertSame('unique', $raw['indexes'][0]['type']);
        $this->assertSame([['column' => 'list_type'], ['column' => 'valid_from']], $raw['indexes'][0]['columns']);
        $columns = array_column($raw['columns'], null, 'id');
        $this->assertSame('economy.exchangeRates.listSources', $columns['source']['cfgItem']);
        $this->assertSame('world.base.currencies', $columns['home_currency']['cfgItem']);

        $values = JsoncParser::parseFile(self::MODULE . '/tables/economy_exchange_rates_values.jsonc');
        $this->assertTrue($values['hideFromNavigation']);
        $vcols = array_column($values['columns'], null, 'id');
        $this->assertSame('economy_exchange_rates_lists', $vcols['list']['reference']);
        $this->assertSame(['precision' => 15, 'scale' => 6], ['precision' => $vcols['rate']['precision'], 'scale' => $vcols['rate']['scale']]);
        $this->assertSame([['column' => 'list'], ['column' => 'currency']], $values['indexes'][0]['columns']);
        $this->assertSame('unique', $values['indexes'][0]['type']);
    }

    public function testViewerLivesInAccountingSettingsSectionReadOnly(): void
    {
        $module = self::module();
        $this->assertSame(
            ['economy.exchangeRates.lists' => 'accounting'],
            array_column($module['settingsItems'], 'section', 'viewer'),
        );
        $viewers = array_column($module['viewers'], null, 'id');
        $viewer = $viewers['economy.exchangeRates.lists'];
        $this->assertSame(ExchangeRateListsViewer::class, $viewer['class']);
        $this->assertSame('economy_exchange_rates_lists', $viewer['table']);
        $this->assertArrayNotHasKey('navSection', $viewer, 'jen v Nastavení');
        $this->assertArrayNotHasKey('forms', $module, 'žádný formulář — jen ke čtení');

        $sections = JsoncParser::parseFile(self::MODULES . '/install/base/config/settingsSections.jsonc')['sections'];
        $this->assertContains('accounting', array_column($sections, 'id'));

        $icons = (string) file_get_contents(self::MODULES . '/../frontend/src/icons.js');
        $this->assertStringContainsString("'" . $viewer['icon'] . "':", $icons, 'ikona vieweru v iconMap');
    }

    public function testModuleIsInstalledWithBaseAndRequiredByDocsCore(): void
    {
        $install = JsoncParser::parseFile(self::MODULES . '/install/base/module.jsonc');
        $this->assertContains('economy.exchangeRates', $install['dependencies']);

        $docsCore = JsoncParser::parseFile(self::MODULES . '/docs/core/module.jsonc');
        $this->assertContains('economy.exchangeRates', $docsCore['dependencies'], 'formuláře a applier volají resolver');
    }

    /** Task B U6: upozornění na chybějící lístek — warning, 1 h, sekce Účetnictví, třída existuje. */
    public function testDeclaresListMissingAlertCheck(): void
    {
        $checks = array_column(self::module()['alertChecks'] ?? [], null, 'id');
        $check = $checks['economy.exchangeRates.list_missing'] ?? null;

        $this->assertNotNull($check);
        $this->assertSame(['warning', '1h', 'accounting'], [$check['severity'], $check['interval'], $check['navSection']]);
        $this->assertSame(ListMissingCheck::class, $check['class']);
        $this->assertTrue(class_exists($check['class']));
        $this->assertArrayHasKey('name:cs', $check);
        $this->assertArrayHasKey('description:cs', $check);
    }

    public function testConfigItemsExist(): void
    {
        $config = array_column(self::module()['config'], 'file', 'id');
        $this->assertSame(
            ['economy.exchangeRates.listSources' => 'config/listSources.jsonc', 'economy.exchangeRates.viewerDetailLabels' => 'config/viewerDetailLabels.jsonc'],
            $config,
        );
        $sources = JsoncParser::parseFile(self::MODULE . '/config/listSources.jsonc');
        $this->assertSame(
            [ExchangeRateListImporter::SOURCE_FILE, ExchangeRateListImporter::SOURCE_DATASET, ExchangeRateListImporter::SOURCE_SERVICE],
            array_map('intval', array_keys($sources)),
        );
        foreach ($sources as $entry) {
            $this->assertArrayHasKey('name:cs', $entry);
        }
        $labels = JsoncParser::parseFile(self::MODULE . '/config/viewerDetailLabels.jsonc');
        $this->assertArrayHasKey('values', $labels['tabs']);
    }
}
