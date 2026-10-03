<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigCompiler;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModulePathResolver;

class ConfigCompilerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = "$path/$entry";
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($path);
    }

    private function makeModule(string $id, array $config): ModuleDefinition
    {
        return ModuleDefinition::fromArray([
            'id' => $id,
            'name' => $id,
            'config' => $config,
        ]);
    }

    private function writeConfigFile(string $modulePath, string $relPath, array $data): void
    {
        if (!is_dir($modulePath)) {
            mkdir($modulePath, 0755, true);
        }
        // Stub module.jsonc so ModulePathResolver discovers this module dir.
        if (!is_file($modulePath . '/module.jsonc')) {
            file_put_contents($modulePath . '/module.jsonc', '');
        }
        $fullPath = $modulePath . '/' . $relPath;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($fullPath, json_encode($data));
    }

    public function testSingleModuleSingleLanguage(): void
    {
        $modulePath = $this->tmpDir . '/modules/core/system';
        $this->writeConfigFile($modulePath, 'config/app.jsonc', ['name' => 'App', 'debug' => false]);

        $module = $this->makeModule('core.system', [['id' => 'core.app', 'file' => 'config/app.jsonc']]);
        $outputPath = $this->tmpDir . '/output';

        ConfigCompiler::compile([$module], new ModulePathResolver([$this->tmpDir . '/modules']), ['en'], $outputPath);

        $this->assertFileExists($outputPath . '/compiled.en.json');
        $data = json_decode(file_get_contents($outputPath . '/compiled.en.json'), true);
        $this->assertArrayHasKey('core.app', $data['items']);
        $this->assertSame('App', $data['items']['core.app']['name']);
    }

    public function testMultipleModulesMultipleLanguages(): void
    {
        $mod1Path = $this->tmpDir . '/modules/core/system';
        $mod2Path = $this->tmpDir . '/modules/economy/docs';
        $this->writeConfigFile($mod1Path, 'config/sys.jsonc', ['name' => 'System']);
        $this->writeConfigFile($mod2Path, 'config/docs.jsonc', ['name' => 'Docs']);

        $modules = [
            $this->makeModule('core.system', [['id' => 'core.sys', 'file' => 'config/sys.jsonc']]),
            $this->makeModule('economy.docs', [['id' => 'economy.docs', 'file' => 'config/docs.jsonc']]),
        ];
        $outputPath = $this->tmpDir . '/output';

        ConfigCompiler::compile($modules, new ModulePathResolver([$this->tmpDir . '/modules']), ['cs', 'en'], $outputPath);

        $this->assertFileExists($outputPath . '/compiled.cs.json');
        $this->assertFileExists($outputPath . '/compiled.en.json');

        foreach (['cs', 'en'] as $lang) {
            $data = json_decode(file_get_contents($outputPath . "/compiled.$lang.json"), true);
            $this->assertArrayHasKey('core.sys', $data['items']);
            $this->assertArrayHasKey('economy.docs', $data['items']);
        }
    }

    public function testLocalizationCs(): void
    {
        $modulePath = $this->tmpDir . '/modules/core/system';
        $this->writeConfigFile($modulePath, 'config/app.jsonc', [
            'name' => 'App',
            'name:cs' => 'Aplikace',
            'name:en' => 'Application',
        ]);

        $module = $this->makeModule('core.system', [['id' => 'core.app', 'file' => 'config/app.jsonc']]);
        $outputPath = $this->tmpDir . '/output';

        ConfigCompiler::compile([$module], new ModulePathResolver([$this->tmpDir . '/modules']), ['cs'], $outputPath);

        $data = json_decode(file_get_contents($outputPath . '/compiled.cs.json'), true);
        $item = $data['items']['core.app'];
        $this->assertSame('Aplikace', $item['name']);
        $this->assertArrayNotHasKey('name:cs', $item);
    }

    public function testLocalizationEn(): void
    {
        $modulePath = $this->tmpDir . '/modules/core/system';
        $this->writeConfigFile($modulePath, 'config/app.jsonc', [
            'name' => 'App',
            'name:cs' => 'Aplikace',
            'name:en' => 'Application',
        ]);

        $module = $this->makeModule('core.system', [['id' => 'core.app', 'file' => 'config/app.jsonc']]);
        $outputPath = $this->tmpDir . '/output';

        ConfigCompiler::compile([$module], new ModulePathResolver([$this->tmpDir . '/modules']), ['en'], $outputPath);

        $data = json_decode(file_get_contents($outputPath . '/compiled.en.json'), true);
        $item = $data['items']['core.app'];
        $this->assertSame('Application', $item['name']);
        $this->assertArrayNotHasKey('name:en', $item);
    }

    public function testFallbackMissingTranslation(): void
    {
        $modulePath = $this->tmpDir . '/modules/core/system';
        $this->writeConfigFile($modulePath, 'config/app.jsonc', ['name' => 'App']);

        $module = $this->makeModule('core.system', [['id' => 'core.app', 'file' => 'config/app.jsonc']]);
        $outputPath = $this->tmpDir . '/output';

        ConfigCompiler::compile([$module], new ModulePathResolver([$this->tmpDir . '/modules']), ['cs', 'en'], $outputPath);

        foreach (['cs', 'en'] as $lang) {
            $data = json_decode(file_get_contents($outputPath . "/compiled.$lang.json"), true);
            $this->assertSame('App', $data['items']['core.app']['name']);
        }
    }

    public function testMetaSection(): void
    {
        $modulePath = $this->tmpDir . '/modules/core/system';
        $this->writeConfigFile($modulePath, 'config/app.jsonc', ['name' => 'App']);

        $module = $this->makeModule('core.system', [['id' => 'core.app', 'file' => 'config/app.jsonc']]);
        $outputPath = $this->tmpDir . '/output';

        ConfigCompiler::compile([$module], new ModulePathResolver([$this->tmpDir . '/modules']), ['en'], $outputPath);

        $data = json_decode(file_get_contents($outputPath . '/compiled.en.json'), true);
        $meta = $data['_meta'];
        $this->assertArrayHasKey('compiled', $meta);
        $this->assertSame('0.1.0', $meta['version']);
        $this->assertSame('en', $meta['language']);
        $this->assertSame(['core.system'], $meta['modules']);
    }

    public function testOutputDirCreated(): void
    {
        $modulePath = $this->tmpDir . '/modules/core/system';
        $this->writeConfigFile($modulePath, 'config/app.jsonc', ['name' => 'App']);

        $module = $this->makeModule('core.system', [['id' => 'core.app', 'file' => 'config/app.jsonc']]);
        $outputPath = $this->tmpDir . '/new/nested/output';

        $this->assertDirectoryDoesNotExist($outputPath);

        ConfigCompiler::compile([$module], new ModulePathResolver([$this->tmpDir . '/modules']), ['en'], $outputPath);

        $this->assertDirectoryExists($outputPath);
        $this->assertFileExists($outputPath . '/compiled.en.json');
    }

    // ── Strukturovaná schémata (#74) ─────────────────────────────────────────

    /** @return array{ModuleDefinition, string} modul se schématem + output path */
    private function moduleWithSchema(array $schema): array
    {
        $modulePath = $this->tmpDir . '/modules/economy/vat';
        $this->writeConfigFile($modulePath, 'config/filingProfileCz.jsonc', $schema);

        return [
            $this->makeModule('economy.vat', [
                ['id' => 'economy.vat.filingProfileCz', 'file' => 'config/filingProfileCz.jsonc'],
            ]),
            $this->tmpDir . '/output',
        ];
    }

    public function testStructuredSchemaCompilesAndLocalizes(): void
    {
        [$module, $outputPath] = $this->moduleWithSchema([
            'version' => '2026',
            'groups'  => [['id' => 'office', 'name' => 'Tax office', 'name:cs' => 'Finanční úřad']],
            'fields'  => [[
                'id' => 'c_ufo', 'type' => 'enumString', 'length' => 5,
                'cfgItem' => 'world.cz.taxOffices', 'group' => 'office',
                'name' => 'Tax office', 'name:cs' => 'Finanční úřad',
            ]],
        ]);

        ConfigCompiler::compile(
            [$module],
            new ModulePathResolver([$this->tmpDir . '/modules']),
            ['cs'],
            $outputPath,
            ['economy.vat.filingProfileCz' => 'economy_codebooks_vat_registrations.filing_profile'],
        );

        $data = json_decode(file_get_contents($outputPath . '/compiled.cs.json'), true);
        $schema = $data['items']['economy.vat.filingProfileCz'];
        $this->assertSame('Finanční úřad', $schema['groups'][0]['name']);
        $this->assertSame('Finanční úřad', $schema['fields'][0]['name']);
    }

    public function testInvalidStructuredSchemaStopsCompilation(): void
    {
        [$module, $outputPath] = $this->moduleWithSchema([
            'version' => '2026',
            'fields'  => [['id' => 'c_ufo', 'type' => 'enumString', 'length' => 5, 'name' => 'Tax office']],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/fields\[0\]\.cfgItem/");

        ConfigCompiler::compile(
            [$module],
            new ModulePathResolver([$this->tmpDir . '/modules']),
            ['cs'],
            $outputPath,
            ['economy.vat.filingProfileCz' => 'economy_codebooks_vat_registrations.filing_profile'],
        );
    }

    public function testUnknownStructuredSchemaCfgItemStopsCompilation(): void
    {
        $modulePath = $this->tmpDir . '/modules/core/system';
        $this->writeConfigFile($modulePath, 'config/app.jsonc', ['name' => 'App']);
        $module = $this->makeModule('core.system', [['id' => 'core.app', 'file' => 'config/app.jsonc']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/references unknown schema cfgItem/');

        ConfigCompiler::compile(
            [$module],
            new ModulePathResolver([$this->tmpDir . '/modules']),
            ['en'],
            $this->tmpDir . '/output',
            ['economy.vat.filingProfileCz' => 'economy_codebooks_vat_registrations.filing_profile'],
        );
    }

    // ── journalDimensions (assets D47) ──────────────────────────────────────

    /** Prázdný adresář modulu, ať ho ModulePathResolver najde. */
    private function stubModuleDir(string $moduleId): void
    {
        $path = $this->tmpDir . '/modules/' . str_replace('.', '/', $moduleId);
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        file_put_contents($path . '/module.jsonc', '');
    }

    private function dimensionModule(string $moduleId, string $dimensionId = 'asset'): ModuleDefinition
    {
        $this->stubModuleDir($moduleId);

        return ModuleDefinition::fromArray([
            'id'   => $moduleId,
            'name' => $moduleId,
            'journalDimensions' => [[
                'id' => $dimensionId, 'rowColumn' => 'asset', 'journalColumn' => 'asset',
                'table' => 'economy_assets_assets', 'name' => 'Asset', 'name:cs' => 'Majetek',
            ]],
        ]);
    }

    /** @return array<string, mixed> */
    private function compiledItems(string $language): array
    {
        return json_decode(file_get_contents($this->tmpDir . '/output/compiled.' . $language . '.json'), true)['items'];
    }

    public function testJournalDimensionsCompileIntoLocalizedCfgItem(): void
    {
        ConfigCompiler::compile(
            [$this->dimensionModule('economy.assets')],
            new ModulePathResolver([$this->tmpDir . '/modules']),
            ['cs', 'en'],
            $this->tmpDir . '/output',
            [],
            ['economy_assets_assets' => '{asset_number} — {name}'],
        );

        $this->assertSame(['asset' => [
            'id' => 'asset', 'rowColumn' => 'asset', 'headColumn' => null, 'journalColumn' => 'asset',
            'table' => 'economy_assets_assets', 'name' => 'Majetek', 'displayPattern' => '{asset_number} — {name}',
        ]], $this->compiledItems('cs')[ConfigCompiler::JOURNAL_DIMENSIONS_ITEM]);
        $this->assertSame('Asset', $this->compiledItems('en')[ConfigCompiler::JOURNAL_DIMENSIONS_ITEM]['asset']['name']);
    }

    public function testNoJournalDimensionsCompileToEmptyItem(): void
    {
        $this->stubModuleDir('core.system');

        ConfigCompiler::compile(
            [$this->makeModule('core.system', [])],
            new ModulePathResolver([$this->tmpDir . '/modules']),
            ['en'],
            $this->tmpDir . '/output',
        );

        $this->assertSame([], $this->compiledItems('en')[ConfigCompiler::JOURNAL_DIMENSIONS_ITEM]);
    }

    public function testJournalDimensionOfUnknownTableStopsCompilation(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/dimension 'asset'.*unknown table 'economy_assets_assets'/");

        ConfigCompiler::compile(
            [$this->dimensionModule('economy.assets')],
            new ModulePathResolver([$this->tmpDir . '/modules']),
            ['en'],
            $this->tmpDir . '/output',
            [],
            ['docs_core_rows' => null],
        );
    }

    public function testSameJournalDimensionInTwoModulesStopsCompilation(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/dimension 'asset' is declared by more than one module/");

        ConfigCompiler::compile(
            [$this->dimensionModule('economy.assets'), $this->dimensionModule('economy.other')],
            new ModulePathResolver([$this->tmpDir . '/modules']),
            ['en'],
            $this->tmpDir . '/output',
        );
    }

    // ── jazyky kompilace (#90 D29) ──────────────────────────────────────────

    public function testLanguagesAreUiLanguagesWithoutDocumentLanguages(): void
    {
        $modulePath = $this->tmpDir . '/modules/core/system';
        $this->writeConfigFile($modulePath, 'config/app.jsonc', ['name' => 'App']);
        $module = $this->makeModule('core.system', [['id' => 'core.app', 'file' => 'config/app.jsonc']]);

        $this->assertSame(
            ['cs', 'en'],
            ConfigCompiler::languages([$module], new ModulePathResolver([$this->tmpDir . '/modules'])),
        );
    }

    public function testLanguagesIncludeDocumentLanguagesFromRawConfig(): void
    {
        $modulePath = $this->tmpDir . '/modules/world/base';
        $this->writeConfigFile($modulePath, 'config/documentLanguages.jsonc', [
            'cs' => ['name' => 'Czech'],
            'en' => ['name' => 'English'],
            'sk' => ['name' => 'Slovak'],
            'de' => ['name' => 'German'],
        ]);
        $module = $this->makeModule('world.base', [
            ['id' => ConfigCompiler::DOCUMENT_LANGUAGES_ITEM, 'file' => 'config/documentLanguages.jsonc'],
        ]);

        $this->assertSame(
            ['cs', 'en', 'sk', 'de'],
            ConfigCompiler::languages([$module], new ModulePathResolver([$this->tmpDir . '/modules'])),
        );
    }
}
