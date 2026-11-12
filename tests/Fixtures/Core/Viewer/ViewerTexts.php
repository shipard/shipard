<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Core\Viewer;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/**
 * Texty viewerů pro unit testy ze skutečných zdrojů (#133 D66): viewer
 * bere texty z katalogu modulu a popisky sloupců z definice tabulky, bez
 * nich by test viděl jen anglický fallback. Helper čte zdrojové `.jsonc`
 * z `modules/` a lokalizuje je stejně jako kompilace konfigurace
 * a `TableLoader` — test tak hlídá i znění v katalogu.
 */
final class ViewerTexts
{
    /** Sdílené texty viewerů — přidávají se ke každé konfiguraci. */
    private const SHARED = 'core.system.viewerDetailLabels';

    /** @var ?array<string, string> cfgItem id → cesta k souboru */
    private static ?array $configFiles = null;

    /**
     * Konfigurace s lokalizovanými katalogy (a sdílenými texty `core.system`).
     *
     * @param list<string> $cfgItemIds  např. `economy.accbal.viewerDetailLabels`, i výčty modulu
     * @param array<string, mixed> $extraItems  další cfgItems testu (mají přednost)
     */
    public static function config(array $cfgItemIds, string $language = 'cs', array $extraItems = []): ConfigRuntime
    {
        $items = [];
        foreach (array_unique([self::SHARED, ...$cfgItemIds]) as $id) {
            $items[$id] = self::catalog($id, $language);
        }
        return ConfigRuntimeFactory::fromItems($extraItems + $items);
    }

    /** Lokalizovaný obsah jednoho cfgItem ze zdrojového souboru modulu. */
    public static function catalog(string $cfgItemId, string $language = 'cs'): mixed
    {
        $file = self::configFiles()[$cfgItemId] ?? null;
        if ($file === null) {
            throw new \InvalidArgumentException("cfgItem {$cfgItemId} není registrovaný v žádném module.jsonc");
        }
        return ConfigLocalizer::localize(JsoncParser::parseFile($file), $language);
    }

    /**
     * Lokalizované definice tabulek pro `TableViewer::setTables()` (popisky
     * sloupců pro `columnLabel()`); bez extensions jiných modulů.
     *
     * @param list<string> $tableNames
     * @return array<string, TableDefinition>
     */
    public static function tables(array $tableNames, string $language = 'cs'): array
    {
        $tables = [];
        foreach ($tableNames as $name) {
            $files = glob(self::modulesDir() . '/*/*/tables/' . $name . '.jsonc') ?: [];
            if (count($files) !== 1) {
                throw new \InvalidArgumentException("Definice tabulky {$name} nenalezena");
            }
            $tables[$name] = TableDefinition::fromArray(ConfigLocalizer::localize(JsoncParser::parseFile($files[0]), $language));
        }
        return $tables;
    }

    /** @return array<string, string> */
    private static function configFiles(): array
    {
        if (self::$configFiles !== null) {
            return self::$configFiles;
        }
        $resolver = new ModulePathResolver([self::modulesDir()]);
        $files = [];
        foreach (ModuleLoader::loadAllModules($resolver) as $module) {
            foreach ($module->config as $entry) {
                if (isset($entry['id'], $entry['file'])) {
                    $files[(string) $entry['id']] = $resolver->getPath($module->id) . '/' . $entry['file'];
                }
            }
        }
        return self::$configFiles = $files;
    }

    private static function modulesDir(): string
    {
        return dirname(__DIR__, 4) . '/modules';
    }
}
