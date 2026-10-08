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

    public function testOutputDirectoryCreatedWithSpecMode(): void
    {
        $modulePath = $this->tmpDir . '/modules/core/system';
        $this->writeConfigFile($modulePath, 'config/app.jsonc', ['name' => 'App']);

        $module = $this->makeModule('core.system', [['id' => 'core.app', 'file' => 'config/app.jsonc']]);
        $outputPath = $this->tmpDir . '/config/configuration';

        ConfigCompiler::compile([$module], new ModulePathResolver([$this->tmpDir . '/modules']), ['en'], $outputPath);

        clearstatcache();
        $this->assertSame(0750, fileperms($outputPath) & 0777);
    }

    public function testUncreatableOutputDirectoryThrows(): void
    {
        mkdir($this->tmpDir . '/modules');
        file_put_contents($this->tmpDir . '/config', '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot create configuration directory');

        ConfigCompiler::compile(
            [],
            new ModulePathResolver([$this->tmpDir . '/modules']),
            ['en'],
            $this->tmpDir . '/config/configuration',
        );
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

    // ── sendPurposes (#90 D34) ──────────────────────────────────────────────

    /** @param list<array<string, mixed>> $purposes */
    private function purposeModule(string $moduleId, array $purposes): ModuleDefinition
    {
        $this->stubModuleDir($moduleId);

        return ModuleDefinition::fromArray(['id' => $moduleId, 'name' => $moduleId, 'sendPurposes' => $purposes]);
    }

    public function testSendPurposesOfAllModulesCompileIntoOneOrderedCfgItem(): void
    {
        ConfigCompiler::compile(
            [
                $this->purposeModule('base.persons', [
                    ['id' => 'reminders', 'name' => 'Payment reminders', 'name:cs' => 'Upomínky', 'order' => 20],
                    ['id' => 'invoices', 'name' => 'Invoices', 'name:cs' => 'Faktury', 'order' => 10],
                ]),
                // Modul přidá vlastní účel bez zásahu do base.persons.
                $this->purposeModule('economy.contracts', [
                    ['id' => 'contracts', 'name' => 'Contracts', 'name:cs' => 'Smlouvy', 'order' => 15],
                ]),
            ],
            new ModulePathResolver([$this->tmpDir . '/modules']),
            ['cs', 'en'],
            $this->tmpDir . '/output',
        );

        $this->assertSame([
            'invoices'  => ['order' => 10, 'name' => 'Faktury'],
            'contracts' => ['order' => 15, 'name' => 'Smlouvy'],
            'reminders' => ['order' => 20, 'name' => 'Upomínky'],
        ], $this->compiledItems('cs')[ConfigCompiler::SEND_PURPOSES_ITEM]);
        $this->assertSame('Contracts', $this->compiledItems('en')[ConfigCompiler::SEND_PURPOSES_ITEM]['contracts']['name']);
    }

    // ── workOrderInvoiceContributors (#110 D10) ─────────────────────────────

    public function testInvoiceContributorsOfAllModulesCompileIntoOneCfgItem(): void
    {
        $this->stubModuleDir('economy.energy');
        $this->stubModuleDir('economy.water');
        ConfigCompiler::compile(
            [
                ModuleDefinition::fromArray(['id' => 'economy.energy', 'name' => 'Energy', 'workOrderInvoiceContributors' => [
                    ['id' => 'energy.consumption', 'class' => 'Foo\\Energy', 'name' => 'Energy consumption', 'name:cs' => 'Spotřeba energií'],
                ]]),
                ModuleDefinition::fromArray(['id' => 'economy.water', 'name' => 'Water', 'workOrderInvoiceContributors' => [
                    ['id' => 'water.consumption', 'class' => 'Foo\\Water', 'name' => 'Water consumption', 'name:cs' => 'Spotřeba vody'],
                ]]),
            ],
            new ModulePathResolver([$this->tmpDir . '/modules']),
            ['cs', 'en'],
            $this->tmpDir . '/output',
        );

        $this->assertSame([
            'energy.consumption' => ['class' => 'Foo\\Energy', 'name' => 'Spotřeba energií'],
            'water.consumption'  => ['class' => 'Foo\\Water', 'name' => 'Spotřeba vody'],
        ], $this->compiledItems('cs')[ConfigCompiler::INVOICE_CONTRIBUTORS_ITEM]);
        $this->assertSame('Energy consumption', $this->compiledItems('en')[ConfigCompiler::INVOICE_CONTRIBUTORS_ITEM]['energy.consumption']['name']);
    }

    public function testDuplicateInvoiceContributorAcrossModulesIsAnError(): void
    {
        $this->stubModuleDir('economy.energy');
        $this->stubModuleDir('economy.water');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Invoice contributor 'consumption' is declared by more than one module");
        ConfigCompiler::compile(
            [
                ModuleDefinition::fromArray(['id' => 'economy.energy', 'name' => 'Energy', 'workOrderInvoiceContributors' => [['id' => 'consumption', 'class' => 'A', 'name' => 'A']]]),
                ModuleDefinition::fromArray(['id' => 'economy.water', 'name' => 'Water', 'workOrderInvoiceContributors' => [['id' => 'consumption', 'class' => 'B', 'name' => 'B']]]),
            ],
            new ModulePathResolver([$this->tmpDir . '/modules']),
            ['en'],
            $this->tmpDir . '/output',
        );
    }

    public function testNoSendPurposesCompileToEmptyItem(): void
    {
        $this->stubModuleDir('core.system');

        ConfigCompiler::compile(
            [$this->makeModule('core.system', [])],
            new ModulePathResolver([$this->tmpDir . '/modules']),
            ['en'],
            $this->tmpDir . '/output',
        );

        $this->assertSame([], $this->compiledItems('en')[ConfigCompiler::SEND_PURPOSES_ITEM]);
    }

    // ── deklarace tisků (#90 D47) ───────────────────────────────────────────

    /** @param list<array<string, mixed>> $declarations */
    private function printsModule(string $moduleId, array $declarations): ModuleDefinition
    {
        $this->writeConfigFile(
            $this->tmpDir . '/modules/' . str_replace('.', '/', $moduleId),
            'config/prints.jsonc',
            $declarations,
        );

        return ModuleDefinition::fromArray([
            'id' => $moduleId, 'name' => $moduleId, 'prints' => [['file' => 'config/prints.jsonc']],
        ]);
    }

    public function testPrintDeclarationsCompileIntoOneLocalizedCfgItem(): void
    {
        ConfigCompiler::compile(
            [
                $this->printsModule('docs.invoicesOut', [[
                    'id'        => 'docs.invoicesOut.invoice',
                    'name'      => 'Invoice', 'name:cs' => 'Faktura',
                    'table'     => 'docs_core_heads',
                    'filter'    => ['doc_type' => ['invno']],
                    'docStates' => [40],
                    'builder'   => 'Some\\Builder',
                    'template'  => '@docs.invoicesOut/invoice',
                    'textSlots' => ['header', 'emailBody'],
                    'order'     => 10,
                ]]),
                $this->printsModule('economy.accounting', [[
                    'id'    => 'economy.accounting.docJournal',
                    'name'  => 'Accounting entries', 'name:cs' => 'Kontace',
                    'table' => 'docs_core_heads',
                ]]),
            ],
            new ModulePathResolver([$this->tmpDir . '/modules']),
            ['cs', 'en'],
            $this->tmpDir . '/output',
        );

        // Jen to, co potřebuje agenda textů — builder ani šablona v konfiguraci nejsou.
        $this->assertSame([
            'docs.invoicesOut.invoice' => [
                'name'      => 'Faktura',
                'table'     => 'docs_core_heads',
                'filter'    => ['doc_type' => ['invno']],
                'textSlots' => ['header', 'emailBody'],
                'order'     => 10,
            ],
            'economy.accounting.docJournal' => ['name' => 'Kontace', 'table' => 'docs_core_heads'],
        ], $this->compiledItems('cs')[ConfigCompiler::PRINTS_ITEM]);
        $this->assertSame(
            'Invoice',
            $this->compiledItems('en')[ConfigCompiler::PRINTS_ITEM]['docs.invoicesOut.invoice']['name'],
        );
    }

    public function testNoPrintDeclarationsCompileToEmptyItem(): void
    {
        $this->stubModuleDir('core.system');

        ConfigCompiler::compile(
            [$this->makeModule('core.system', [])],
            new ModulePathResolver([$this->tmpDir . '/modules']),
            ['en'],
            $this->tmpDir . '/output',
        );

        $this->assertSame([], $this->compiledItems('en')[ConfigCompiler::PRINTS_ITEM]);
    }

    public function testPrintDeclarationsCfgItemIsReserved(): void
    {
        $modulePath = $this->tmpDir . '/modules/core/prints';
        $this->writeConfigFile($modulePath, 'config/declarations.jsonc', ['x' => ['name' => 'X']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("cfgItem 'core.prints.declarations' is reserved for print declarations");

        ConfigCompiler::compile(
            [$this->makeModule('core.prints', [
                ['id' => ConfigCompiler::PRINTS_ITEM, 'file' => 'config/declarations.jsonc'],
            ])],
            new ModulePathResolver([$this->tmpDir . '/modules']),
            ['en'],
            $this->tmpDir . '/output',
        );
    }

    public function testSendPurposeDeclaredByTwoModulesStopsCompilation(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/Send purpose 'invoices' is declared by more than one module/");

        ConfigCompiler::compile(
            [
                $this->purposeModule('base.persons', [['id' => 'invoices', 'name' => 'Invoices']]),
                $this->purposeModule('economy.contracts', [['id' => 'invoices', 'name' => 'Invoices again']]),
            ],
            new ModulePathResolver([$this->tmpDir . '/modules']),
            ['en'],
            $this->tmpDir . '/output',
        );
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
