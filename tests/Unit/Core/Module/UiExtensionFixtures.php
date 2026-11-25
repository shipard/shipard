<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Module;

use Shipard\Api\TableLoader;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModuleClassLoader;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;
use Shipard\Core\Module\ModuleUiExtensions;

/**
 * Společné zázemí testů rozšíření UI z modulů (#172 D16–D19): fixturové
 * moduly `test.uiHost` + `test.uiExtensions` (`tests/Fixtures/modules`),
 * dočasný zdroj dat s vybranými moduly, definice tabulek včetně sloupce
 * z rozšíření a log do dočasného souboru (izolace poskytovatele loguje).
 *
 * Test volá `setUpUiExtensionFixtures()` v `setUp()` a
 * `tearDownUiExtensionFixtures()` v `tearDown()`.
 */
trait UiExtensionFixtures
{
    private const UI_TABLE = 'test_uihost_records';
    private const UI_NAMESPACE = 'Shipard\\Module\\Test\\UiExtensions\\';

    private string $uiTmpDir;
    private string $uiLogPath;

    private static function uiMainModulesPath(): string
    {
        return dirname(__DIR__, 4) . '/modules';
    }

    private static function uiFixtureModulesPath(): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/modules';
    }

    private static function uiFixtureResolver(): ModulePathResolver
    {
        return new ModulePathResolver([self::uiFixtureModulesPath()]);
    }

    private function setUpUiExtensionFixtures(): void
    {
        // Fixturové třídy leží ve vlastním kořeni modulů.
        ModuleClassLoader::register(new ModulePathResolver([self::uiMainModulesPath(), self::uiFixtureModulesPath()]));

        $this->uiTmpDir = sys_get_temp_dir() . '/shpd-ui-ext-' . uniqid('', true);
        mkdir($this->uiTmpDir . '/ds/config', 0755, true);
        $this->uiLogPath = $this->uiTmpDir . '/shipard.log';
        ErrorLogger::resetForTesting();
        ErrorLogger::setLogPath($this->uiLogPath);
    }

    private function tearDownUiExtensionFixtures(): void
    {
        ErrorLogger::resetForTesting();
        exec('rm -rf ' . escapeshellarg($this->uiTmpDir));
        ModuleClassLoader::register(new ModulePathResolver([self::uiMainModulesPath()]));
    }

    /** @param list<string> $modules moduly zapnuté ve zdroji dat */
    private function uiDataSource(array $modules = ['test.uiExtensions']): DataSourceConfig
    {
        file_put_contents($this->uiTmpDir . '/ds/config/main.json', json_encode([
            'id'            => 'abcd-efgh-ijkl-mnop',
            'name'          => 'Test DS',
            'database_name' => 'abcd_efgh_ijkl_mnop',
            'database_user' => 'shpd_abcdefgh',
            'created'       => '2026-11-25T10:00:00+00:00',
            'modules'       => $modules,
        ]));
        return new DataSourceConfig($this->uiTmpDir . '/ds');
    }

    /**
     * Definice tabulek zdroje dat s fixturovými moduly — `test_uihost_records`
     * nese i sloupec `contact_payment` z rozšíření `test.uiExtensions`.
     *
     * @param list<string> $modules
     * @return array<string, TableDefinition>
     */
    private function uiTables(array $modules = ['test.uiExtensions']): array
    {
        return TableLoader::load($this->uiDataSource($modules), self::uiFixtureResolver(), 'en');
    }

    /** @param list<string> $modules moduly zapnuté ve zdroji dat */
    private function uiExtensionsOf(array $modules = ['test.uiExtensions']): ModuleUiExtensions
    {
        $errors = [];
        return ModuleUiExtensions::fromModules(ModuleResolver::resolve(
            ModuleLoader::loadAllModules(self::uiFixtureResolver()),
            $modules,
            $errors,
        ));
    }

    /**
     * Záznamy logu chyb „Module UI extension failed“ (kontext `ctx`).
     *
     * @return list<array<string, mixed>>
     */
    private function uiExtensionFailures(): array
    {
        return $this->uiLogEntries('Module UI extension failed');
    }

    /**
     * Kontexty (`ctx`) záznamů logu s danou zprávou, v pořadí zápisu.
     *
     * @return list<array<string, mixed>>
     */
    private function uiLogEntries(string $message): array
    {
        $entries = [];
        foreach ($this->uiLogLines() as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry) && ($entry['msg'] ?? null) === $message) {
                $entries[] = $entry['ctx'] ?? [];
            }
        }
        return $entries;
    }

    /**
     * Surové řádky logu — pro kontrolu, že se do něj nedostala hodnota.
     *
     * @return list<string>
     */
    private function uiLogLines(): array
    {
        if (!is_file($this->uiLogPath)) {
            return [];
        }
        return file($this->uiLogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    }
}
