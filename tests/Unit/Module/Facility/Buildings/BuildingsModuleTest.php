<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Facility\Buildings;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleKind;
use Shipard\Core\Module\ModuleKindRules;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleProvisioner;
use Shipard\Core\Module\ModuleResolver;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Facility\Buildings\ContractKindsProvisioner;

/**
 * Deklarace doplňku facility.buildings nad skutečnými soubory (#174 D1,
 * D20, D26, D27): doplněk mimo install.base, tabulky s unikátními tableId,
 * vlastní sekce vedle Zakázek, sada stavů, vazba míst jako child tabulka
 * a pravidlo „jádro o doplňku neví“.
 */
class BuildingsModuleTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../../..';
    private const MODULES = self::ROOT . '/modules';
    private const MODULE = self::MODULES . '/facility/buildings';

    /** @return array<string, mixed> */
    private static function module(): array
    {
        return JsoncParser::parseFile(self::MODULE . '/module.jsonc');
    }

    /** @return array<string, mixed> */
    private static function table(string $name): array
    {
        return JsoncParser::parseFile(self::MODULE . '/tables/' . $name . '.jsonc');
    }

    public function testModuleIsAnAddonWithItsTables(): void
    {
        $module = ModuleDefinition::fromArray(self::module());

        $this->assertSame('facility.buildings', $module->id);
        $this->assertSame(ModuleKind::Addon, $module->kind);
        foreach (['core.system', 'core.attachments', 'base.persons', 'base.places', 'economy.codebooks', 'docs.core', 'economy.periodicInvoicing'] as $dependency) {
            $this->assertContains($dependency, $module->dependencies, $dependency);
        }

        $ids = [];
        foreach ($module->tables as $table) {
            $ids[$table] = TableDefinition::fromArray(self::table($table))->tableId;
        }
        $this->assertSame(
            [
                'facility_buildings_contract_kinds' => 472, 'facility_buildings_number_counters' => 473,
                'facility_buildings_contracts' => 474, 'facility_buildings_contract_places' => 475,
                'facility_buildings_contract_kind_rows' => 477, 'facility_buildings_contract_rows' => 476,
                'facility_buildings_contract_periods' => 478,
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

    public function testBaseInstallationDoesNotPullTheAddon(): void
    {
        $all = ModuleLoader::loadAllModules(new ModulePathResolver([self::MODULES]));
        $this->assertSame([], ModuleKindRules::violations($all));

        $errors = [];
        $resolved = ModuleResolver::resolve($all, ['install.base'], $errors);
        $ids = array_map(static fn (ModuleDefinition $m): string => $m->id, $resolved);

        $this->assertSame([], $errors);
        $this->assertNotEmpty($ids);
        $this->assertNotContains('facility.buildings', $ids);

        // Zapnutý doplněk přinese jen sebe — závislosti už install.base má.
        $withAddon = array_map(
            static fn (ModuleDefinition $m): string => $m->id,
            ModuleResolver::resolve($all, ['install.base', 'facility.buildings'], $errors),
        );
        $this->assertSame([], $errors);
        $this->assertSame(['facility.buildings'], array_values(array_diff($withAddon, $ids)));
    }

    public function testCoreDoesNotNameTheAddon(): void
    {
        // Past č. 1: jádro, frontend ani jiné moduly doplněk ani jeho
        // tabulky nejmenují — napojení jen přes body rozšíření.
        $roots = [self::ROOT . '/src', self::ROOT . '/frontend/src', self::ROOT . '/bin', self::ROOT . '/public/index.php'];
        foreach (glob(self::MODULES . '/*/*', GLOB_ONLYDIR) as $moduleDir) {
            if (realpath($moduleDir) !== realpath(self::MODULE)) {
                $roots[] = $moduleDir;
            }
        }

        $offenders = [];
        foreach ($roots as $root) {
            $files = is_file($root)
                ? [new \SplFileInfo($root)]
                : new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'js', 'svelte', 'jsonc', 'json', 'twig', ''], true)) {
                    continue;
                }
                $content = (string) file_get_contents($file->getPathname());
                if (preg_match('/facility[._]buildings|Facility\\\\+Buildings/', $content) === 1) {
                    $offenders[] = substr((string) realpath($file->getPathname()), strlen((string) realpath(self::ROOT)) + 1);
                }
            }
        }
        $this->assertSame([], $offenders);
    }

    public function testOwnSectionsSitNextToWorkOrders(): void
    {
        $module = self::module();
        $nav = array_column(JsoncParser::parseFile(self::MODULES . '/install/base/config/navSections.jsonc')['sections'], 'order', 'id');
        $settings = array_column(JsoncParser::parseFile(self::MODULES . '/install/base/config/settingsSections.jsonc')['sections'], 'order', 'id');

        $this->assertSame(['buildings'], array_column($module['navSections'], 'id'));
        $this->assertSame(['buildings'], array_column($module['settingsSections'], 'id'));
        $this->assertArrayNotHasKey('buildings', $nav);
        $this->assertArrayNotHasKey('buildings', $settings);

        $navOrder = $module['navSections'][0]['order'];
        $this->assertGreaterThan($nav['workOrders'], $navOrder);
        $this->assertLessThan($nav['assets'], $navOrder);
        $this->assertNotContains($navOrder, $nav);

        $settingsOrder = $module['settingsSections'][0]['order'];
        $this->assertGreaterThan($settings['workOrders'], $settingsOrder);
        $this->assertLessThan($settings['assets'], $settingsOrder);
        $this->assertNotContains($settingsOrder, $settings);
    }

    public function testStateSetMatchesWorkOrdersWithFeminineTexts(): void
    {
        $states = JsoncParser::parseFile(self::MODULE . '/config/docStates.jsonc');
        $workOrders = JsoncParser::parseFile(self::MODULES . '/economy/workOrders/config/docStates.jsonc');

        $strip = static fn (array $set): array => array_map(
            static fn (array $state): array => array_diff_key($state, ['stateName:cs' => 1]),
            $set,
        );
        $this->assertSame($strip($workOrders), $strip($states), 'sada se od zakázek liší jen českými názvy stavů');
        $this->assertSame(
            ['10' => 'Koncept', '80' => 'V opravě', '40' => 'V pořádku', '70' => 'Ukončená', '30' => 'Zrušená', '90' => 'Smazaná'],
            array_map(static fn (array $state): string => $state['stateName:cs'], $states),
        );
        $this->assertSame([40, 90], $states['10']['goto'], 'smazat jde jen koncept');
        $this->assertFalse($states['40']['detailBadge']);
    }

    public function testContractsTableCarriesStatesNumberAndPlaces(): void
    {
        $contracts = self::table('facility_buildings_contracts');

        $this->assertSame('facility.buildings.docStates', $contracts['docStates']['cfgItem']);
        $this->assertTrue($contracts['stateTransitionsRunDocumentHooks']);
        $this->assertSame('{number} — {title}', $contracts['displayPattern']);
        $this->assertSame('number', $contracts['exchangeKey']);
        $this->assertSame('unique', $contracts['indexes'][0]['type']);
        $this->assertSame([['column' => 'number']], $contracts['indexes'][0]['columns']);
        $this->assertSame(
            [['table' => 'facility_buildings_contract_places', 'foreignKey' => 'contract', 'dataKey' => 'places']],
            $contracts['childTables'],
        );

        $columns = array_column($contracts['columns'], null, 'id');
        $this->assertSame('facility_buildings_contract_kinds', $columns['kind']['reference']);
        $this->assertSame('base_persons_persons', $columns['customer']['reference']);
        $this->assertSame('facility.buildings.contractTypes', $columns['type']['cfgItem']);
        foreach (['number', 'sequence_number', 'number_year', 'date_start', 'date_end'] as $nullable) {
            $this->assertTrue($columns[$nullable]['nullable'], $nullable);
        }
    }

    public function testContractPlacesBindOnePlaceColumnAndIndexIt(): void
    {
        $places = self::table('facility_buildings_contract_places');
        $columns = array_column($places['columns'], null, 'id');

        // Prvek multilookup hledá jediný sloupec s odkazem na tabulku míst.
        $references = array_filter(array_column($places['columns'], 'reference', 'id'));
        $this->assertSame(
            ['contract' => 'facility_buildings_contracts', 'place' => 'base_places_places'],
            $references,
        );
        $this->assertArrayHasKey('order_pos', $columns);
        $this->assertTrue($places['hideFromNavigation']);
        $this->assertContains([['column' => 'place']], array_column($places['indexes'], 'columns'), 'index pro dotazy překryvu');
    }

    public function testKindsUseArchiveStatesAndCountersArePerKindAndYear(): void
    {
        $kinds = self::table('facility_buildings_contract_kinds');
        $this->assertSame('core.system.docStatesArchive', $kinds['docStates']['cfgItem']);
        $this->assertTrue($kinds['stateTransitionsRunDocumentHooks']);
        $kindColumns = array_column($kinds['columns'], null, 'id');
        $this->assertSame('facility.buildings.resetScopes', $kindColumns['reset_scope']['cfgItem']);
        $this->assertSame('yearly', $kindColumns['reset_scope']['default']);
        $this->assertSame(
            ['continuous', 'yearly'],
            array_keys(JsoncParser::parseFile(self::MODULE . '/config/resetScopes.jsonc')),
        );

        $counters = self::table('facility_buildings_number_counters');
        $counterColumns = array_column($counters['columns'], null, 'id');
        $this->assertTrue($counterColumns['year']['nullable'], 'rok NULL = průběžný čítač');
        $this->assertSame('unique', $counters['indexes'][0]['type']);
        $this->assertSame([['column' => 'kind'], ['column' => 'year']], $counters['indexes'][0]['columns']);
        $this->assertTrue($counters['hideFromNavigation']);
    }

    public function testProvisionerOfDefaultKindsIsDeclared(): void
    {
        $module = ModuleDefinition::fromArray(self::module());

        $this->assertSame([ContractKindsProvisioner::class], array_column($module->provisioners, 'class'));
        $this->assertInstanceOf(ModuleProvisioner::class, new ContractKindsProvisioner());
    }

    public function testIconsAreRegisteredInTheFrontendUnderGenericNames(): void
    {
        $iconsJs = (string) file_get_contents(self::ROOT . '/frontend/src/icons.js');
        $module = self::module();
        $types = JsoncParser::parseFile(self::MODULE . '/config/contractTypes.jsonc');

        $icons = array_merge(
            array_column($types, 'icon'),
            array_column($module['navSections'], 'icon'),
            array_column($module['settingsSections'], 'icon'),
            array_column($module['viewers'] ?? [], 'icon'),
        );
        $this->assertCount(7, $types);
        foreach (array_unique($icons) as $icon) {
            $this->assertStringContainsString("'" . $icon . "':", $iconsJs, $icon);
            $this->assertStringNotContainsString('building', $icon, 'ikona nese obecný název podle významu');
        }
    }
}
