<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\DsUpgradeCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Security\DsSecretCipher;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDsUpgradeCommand extends DsUpgradeCommand
{
    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        private readonly string $modulesPath,
        private readonly string $dsDir,
    ) {
        parent::__construct($dsConfig, $dsConnection);
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }

    protected function getModulePathResolver(): ModulePathResolver
    {
        return new ModulePathResolver([$this->modulesPath]);
    }
}

class DsUpgradeCommandTest extends TestCase
{
    private string $tempDir;
    private MockObject $dsConfig;
    private MockObject $dsConnection;
    private string $modulesPath;
    private string $dsDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd_upgrade_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->modulesPath = $this->tempDir . '/modules';
        $this->dsDir = $this->tempDir . '/ds';

        $this->createFixtures();

        $this->dsConfig = $this->createMock(DataSourceConfig::class);
        $this->dsConfig->method('getModules')->willReturn(['test.unit']);
        $this->dsConfig->method('getName')->willReturn('Test DS');
        $this->dsConfig->method('getId')->willReturn('test-0001-test-0001');
        $this->dsConfig->method('getDatabaseName')->willReturn('test_db');
        $this->dsConfig->method('getDatabaseUser')->willReturn('shpd_test0001');
        $this->dsConfig->method('getDatabasePassword')->willReturn('secret');
        $this->dsConfig->method('getDataSourceDir')->willReturn($this->dsDir);
        $this->dsConfig->method('shouldSkipProvisioning')->willReturn(false);

        $this->dsConnection = $this->createMock(DataSourceConnection::class);

        DsSecretCipher::resetCache();
    }

    protected function tearDown(): void
    {
        DsSecretCipher::resetCache();
        $this->rmdirRecursive($this->tempDir);
    }

    private function createFixtures(): void
    {
        // Create module structure
        $moduleDir = $this->modulesPath . '/test/unit';
        mkdir($moduleDir . '/tables', 0755, true);
        mkdir($this->dsDir . '/config/configuration', 0755, true);

        // module.jsonc
        file_put_contents($moduleDir . '/module.jsonc', json_encode([
            'id'           => 'test.unit',
            'name'         => 'Test Unit Module',
            'dependencies' => [],
            'tables'       => ['test_unit_items'],
            'extensions'   => [],
            'config'       => [],
        ]));

        // table definition
        file_put_contents($moduleDir . '/tables/test_unit_items.jsonc', json_encode([
            'tableId' => 1,
            'name'    => 'test_unit_items',
            'columns' => [
                [
                    'id'            => 'id',
                    'name'          => 'ID',
                    'type'          => 'int',
                    'primaryKey'    => true,
                    'autoIncrement' => true,
                    'nullable'      => false,
                ],
                [
                    'id'       => 'label',
                    'name'     => 'Label',
                    'type'     => 'varchar',
                    'length'   => 100,
                    'nullable' => false,
                ],
            ],
            'indexes' => [
                [
                    'id'      => 'idx_label',
                    'type'    => 'index',
                    'columns' => [['column' => 'label', 'order' => 'ASC']],
                ],
            ],
        ]));
    }

    /**
     * Minimal core.mail + core.ai fixture modules (empty tables) — enough
     * to pass the module guard in provisionMailAi(). The real modules
     * are not used so the test doesn't depend on their table definitions.
     */
    private function createAiFixtureModules(): void
    {
        foreach ([
            'core/ai'   => ['id' => 'core.ai', 'dependencies' => []],
            'core/mail' => ['id' => 'core.mail', 'dependencies' => ['core.ai']],
        ] as $dir => $def) {
            $moduleDir = $this->modulesPath . '/' . $dir;
            mkdir($moduleDir, 0755, true);
            file_put_contents($moduleDir . '/module.jsonc', json_encode($def + [
                'name'       => $def['id'],
                'tables'     => [],
                'extensions' => [],
                'config'     => [],
            ]));
        }
    }

    /**
     * Minimal economy.accounting fixture module (empty tables) — enough to
     * pass the module guard in provisionAccountChart() and to gate the
     * [TODO] block of undecided layer C parameters.
     */
    private function createEconomyAccountingFixtureModule(): void
    {
        $moduleDir = $this->modulesPath . '/economy/accounting';
        mkdir($moduleDir, 0755, true);
        file_put_contents($moduleDir . '/module.jsonc', json_encode([
            'id'           => 'economy.accounting',
            'name'         => 'economy.accounting',
            'dependencies' => [],
            'tables'       => [],
            'extensions'   => [],
            'config'       => [],
        ]));
    }

    private function createProvisioningConfig(array $modules): DataSourceConfig&MockObject
    {
        $dsConfig = $this->createMock(DataSourceConfig::class);
        $dsConfig->method('getModules')->willReturn($modules);
        $dsConfig->method('getName')->willReturn('Test DS');
        $dsConfig->method('getId')->willReturn('test-0001-test-0001');
        $dsConfig->method('getDatabaseName')->willReturn('test_db');
        $dsConfig->method('getDatabaseUser')->willReturn('shpd_test0001');
        $dsConfig->method('getDatabasePassword')->willReturn('secret');
        $dsConfig->method('getDataSourceDir')->willReturn($this->dsDir);
        $dsConfig->method('shouldSkipProvisioning')->willReturn(false);

        return $dsConfig;
    }

    private function createSkipProvisioningConfig(array $modules): DataSourceConfig&MockObject
    {
        $dsConfig = $this->createMock(DataSourceConfig::class);
        $dsConfig->method('getModules')->willReturn($modules);
        $dsConfig->method('getName')->willReturn('Test DS');
        $dsConfig->method('getId')->willReturn('test-0001-test-0001');
        $dsConfig->method('getDatabaseName')->willReturn('test_db');
        $dsConfig->method('getDatabaseUser')->willReturn('shpd_test0001');
        $dsConfig->method('getDatabasePassword')->willReturn('secret');
        $dsConfig->method('getDataSourceDir')->willReturn($this->dsDir);
        $dsConfig->method('shouldSkipProvisioning')->willReturn(true);

        return $dsConfig;
    }

    private function createCommandTester(): CommandTester
    {
        $command = new TestableDsUpgradeCommand(
            $this->dsConfig,
            $this->dsConnection,
            $this->modulesPath,
            $this->dsDir,
        );

        $app = new Application();
        $app->add($command);

        return new CommandTester($command);
    }

    public function testUpgradeCreatesNewTable(): void
    {
        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);

        $executedSQLs = [];
        $this->dsConnection->method('executeSQL')
            ->willReturnCallback(function (string $sql) use (&$executedSQLs): void {
                $executedSQLs[] = $sql;
            });

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('[CREATE]', $tester->getDisplay());

        $hasCREATETABLE = false;
        foreach ($executedSQLs as $sql) {
            if (str_contains(strtoupper($sql), 'CREATE TABLE')) {
                $hasCREATETABLE = true;
                break;
            }
        }
        $this->assertTrue($hasCREATETABLE, 'Expected CREATE TABLE SQL to be executed');
    }

    public function testUpgradeAltersExistingTable(): void
    {
        // Simulate table exists with only 'id' column — 'label' is missing
        $this->dsConnection->method('getTableColumns')->willReturn([
            'id' => 'int(11)',
        ]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);

        $executedSQLs = [];
        $this->dsConnection->method('executeSQL')
            ->willReturnCallback(function (string $sql) use (&$executedSQLs): void {
                $executedSQLs[] = $sql;
            });

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('[ALTER]', $tester->getDisplay());
    }

    public function testUpgradeNoChanges(): void
    {
        $this->dsConnection->method('getTableColumns')->willReturn([
            'id'    => 'int(11)',
            'label' => 'varchar(100)',
        ]);
        $this->dsConnection->method('getTableIndexes')->willReturn(['idx_label']);
        $this->dsConnection->expects($this->never())->method('executeSQL');

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('[OK]', $tester->getDisplay());
    }

    public function testUpgradeCreatesWritableDirsWithSpecMode(): void
    {
        $this->dsConnection->method('getTableColumns')->willReturn([
            'id'    => 'int(11)',
            'label' => 'varchar(100)',
        ]);
        $this->dsConnection->method('getTableIndexes')->willReturn(['idx_label']);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        clearstatcache();
        foreach (['att', 'branding', 'cache', 'cache/thumbnails', 'cache/oidc'] as $subdir) {
            $dir = $this->dsDir . '/' . $subdir;
            $this->assertDirectoryExists($dir);
            $this->assertSame(0750, fileperms($dir) & 0777, "mode of {$subdir}");
        }
    }

    public function testUpgradeValidationErrorAborts(): void
    {
        // Create a second module with duplicate tableId=1 to trigger validation error
        $moduleDir2 = $this->modulesPath . '/test/extra';
        mkdir($moduleDir2 . '/tables', 0755, true);

        file_put_contents($moduleDir2 . '/module.jsonc', json_encode([
            'id'           => 'test.extra',
            'name'         => 'Test Extra Module',
            'dependencies' => [],
            'tables'       => ['test_extra_things'],
            'extensions'   => [],
            'config'       => [],
        ]));

        file_put_contents($moduleDir2 . '/tables/test_extra_things.jsonc', json_encode([
            'tableId' => 1, // duplicate!
            'name'    => 'test_extra_things',
            'columns' => [
                [
                    'id'            => 'id',
                    'name'          => 'ID',
                    'type'          => 'int',
                    'primaryKey'    => true,
                    'autoIncrement' => true,
                    'nullable'      => false,
                ],
            ],
            'indexes' => [],
        ]));

        // Both modules activated
        $this->dsConfig = $this->createMock(DataSourceConfig::class);
        $this->dsConfig->method('getModules')->willReturn(['test.unit', 'test.extra']);
        $this->dsConfig->method('getName')->willReturn('Test DS');
        $this->dsConfig->method('getId')->willReturn('test-0001-test-0001');
        $this->dsConfig->method('getDatabaseName')->willReturn('test_db');
        $this->dsConfig->method('getDatabaseUser')->willReturn('shpd_test0001');
        $this->dsConfig->method('getDatabasePassword')->willReturn('secret');
        $this->dsConfig->method('getDataSourceDir')->willReturn($this->dsDir);

        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Duplicate tableId', $tester->getDisplay());
    }

    public function testUpgradeSummaryLine(): void
    {
        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertMatchesRegularExpression(
            '/Upgrade complete\. \d+ tables? created, \d+ tables? altered, \d+ tables? unchanged\./',
            $display
        );
    }

    public function testUpgradeGeneratesMissingSecretsKey(): void
    {
        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');

        $keyFile = $this->dsDir . '/secrets/secrets.key';
        $this->assertFileDoesNotExist($keyFile);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertFileExists($keyFile);
        $this->assertSame(0600, fileperms($keyFile) & 0777);
        $this->assertSame(0700, fileperms($this->dsDir . '/secrets') & 0777);
        $this->assertStringContainsString('Created secrets/secrets.key', $tester->getDisplay());
    }

    public function testUpgradeLeavesExistingSecretsKeyAlone(): void
    {
        // Pre-create a key
        DsSecretCipher::generateKey($this->dsDir);
        $keyFile = $this->dsDir . '/secrets/secrets.key';
        $original = file_get_contents($keyFile);
        $originalMtime = filemtime($keyFile);

        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');

        clearstatcache();
        sleep(1); // ensure mtime would change if rewritten

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame($original, file_get_contents($keyFile));
        $this->assertSame($originalMtime, filemtime($keyFile));
        $this->assertStringNotContainsString('Created secrets/secrets.key', $tester->getDisplay());
    }

    public function testUpgradeLogsInfoForEncryptedColumnAdd(): void
    {
        // Override the fixture: drop the existing module and create one with an encrypted_text column
        $this->rmdirRecursive($this->modulesPath);
        $moduleDir = $this->modulesPath . '/test/unit';
        mkdir($moduleDir . '/tables', 0755, true);

        file_put_contents($moduleDir . '/module.jsonc', json_encode([
            'id'           => 'test.unit',
            'name'         => 'Test Unit Module',
            'dependencies' => [],
            'tables'       => ['test_unit_secret'],
            'extensions'   => [],
            'config'       => [],
        ]));

        file_put_contents($moduleDir . '/tables/test_unit_secret.jsonc', json_encode([
            'tableId' => 1,
            'name'    => 'test_unit_secret',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true, 'autoIncrement' => true, 'nullable' => false],
                ['id' => 'api_key', 'name' => 'API key', 'type' => 'encrypted_text', 'nullable' => true],
            ],
            'indexes' => [],
        ]));

        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString("[INFO] Adding encrypted_text column 'test_unit_secret.api_key'", $display);
        $this->assertStringContainsString('Application layer must use DsSecretCipher', $display);
    }

    public function testUpgradeWritesCompiledConfig(): void
    {
        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');

        $tester = $this->createCommandTester();
        $tester->execute([]);

        $this->assertFileExists($this->dsDir . '/config/configuration/compiled.cs.json');
        $this->assertFileExists($this->dsDir . '/config/configuration/compiled.en.json');
        $this->assertFileDoesNotExist($this->dsDir . '/config/configuration/compiled.sk.json');
    }

    public function testUpgradeCompilesConfigForDocumentLanguages(): void
    {
        // Jazyky dokumentů (#90 D29) — tisk čte konfiguraci v jazyce dokladu.
        $moduleDir = $this->modulesPath . '/world/base';
        mkdir($moduleDir . '/config', 0755, true);
        file_put_contents($moduleDir . '/module.jsonc', json_encode([
            'id'           => 'world.base',
            'name'         => 'world.base',
            'dependencies' => [],
            'tables'       => [],
            'extensions'   => [],
            'config'       => [['id' => 'world.base.documentLanguages', 'file' => 'config/documentLanguages.jsonc']],
        ]));
        file_put_contents($moduleDir . '/config/documentLanguages.jsonc', json_encode([
            'cs' => ['name' => 'Czech', 'name:cs' => 'čeština', 'name:sk' => 'čeština (sk)'],
            'en' => ['name' => 'English'],
            'sk' => ['name' => 'Slovak'],
        ]));
        $this->dsConfig = $this->createProvisioningConfig(['test.unit', 'world.base']);

        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');

        $tester = $this->createCommandTester();
        $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        $this->assertStringContainsString('Languages: cs, en, sk', $tester->getDisplay());
        $compiled = json_decode(
            (string) file_get_contents($this->dsDir . '/config/configuration/compiled.sk.json'),
            true,
        );
        $this->assertSame('sk', $compiled['_meta']['language']);
        $this->assertSame('čeština (sk)', $compiled['items']['world.base.documentLanguages']['cs']['name']);
    }

    public function testUpgradeRunsProvisioningByDefault(): void
    {
        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringNotContainsString('Provisioning disabled via config', $tester->getDisplay());
    }

    public function testUpgradeSkipsProvisioningWhenConfigured(): void
    {
        $this->dsConfig = $this->createSkipProvisioningConfig(['test.unit']);

        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Provisioning disabled via config', $display);
        $this->assertStringContainsString('Upgrade complete.', $display);
        // AI pošty už není součástí gatovaného provisioningu — hláška
        // ho nesmí uvádět mezi přeskočenými položkami.
        $this->assertStringNotContainsString('AI analyzer', $display);
        $this->assertStringNotContainsString('mail AI', $display);
    }

    public function testMailAiProvisionedUnderSkipProvisioning(): void
    {
        $this->createAiFixtureModules();
        $this->dsConfig = $this->createSkipProvisioningConfig(['test.unit', 'core.mail']);

        // fetchRow → null (mock default) = nic neexistuje, provisioner jde
        // do CREATE větví přes mocked insertRow.
        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString("[CREATE] backend 'default'", $display);
        $this->assertStringContainsString('ai-backend-set-key', $display);
        $this->assertStringContainsString("[CREATE] profile 'czech_general'", $display);
        $this->assertStringContainsString('Provisioning disabled via config', $display);
        // Uživatel _ai_analyzer se nezakládá (#85 D21); čistý DS — nikdo
        // k deaktivaci (getAffectedRows → 0), legacy profil neexistuje.
        $this->assertStringNotContainsString("user '_ai_analyzer'", $display);
        $this->assertStringNotContainsString('[DEACTIVATE]', $display);
        $this->assertStringNotContainsString('[RENAME]', $display);
    }

    public function testMailAiDeactivatesLegacyAnalyzerUserOnlyWhenSomethingChanged(): void
    {
        $this->createAiFixtureModules();
        $this->dsConfig = $this->createSkipProvisioningConfig(['test.unit', 'core.mail']);

        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');
        // getAffectedRows: 1. rename, 2. queue fix, 3. API klíče, 4. uživatel
        $this->dsConnection->method('getAffectedRows')->willReturnOnConsecutiveCalls(0, 0, 2, 1);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString(
            "[DEACTIVATE] user '_ai_analyzer' (1 user, 2 API key(s)) — external analyzer retired",
            $tester->getDisplay(),
        );
    }

    public function testPreprocessRulesProvisionedUnderSkipProvisioning(): void
    {
        $this->createAiFixtureModules();
        $this->dsConfig = $this->createSkipProvisioningConfig(['test.unit', 'core.mail']);

        // fetchRow → null = katalog se zakládá celý; běží i pod skipProvisioning.
        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString("[CREATE] preprocess rule 'bolt-invoice-link'", $display);
        $this->assertStringContainsString("[CREATE] preprocess rule 'apple-invoice-body'", $display);
        $this->assertStringContainsString('Provisioning disabled via config', $display);
    }

    public function testMailAiRenamesLegacyProfile(): void
    {
        $this->createAiFixtureModules();
        $this->dsConfig = $this->createSkipProvisioningConfig(['test.unit', 'core.mail']);

        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');
        // getAffectedRows: 1. rename legacy profilu, 2. queue fix, 3.–4. deaktivace _ai_analyzer
        $this->dsConnection->method('getAffectedRows')->willReturnOnConsecutiveCalls(1, 0, 0, 0);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString(
            "[RENAME] profile 'czech_invoices' → 'czech_general'",
            $tester->getDisplay(),
        );
    }

    public function testMailAiSkippedWithoutMailModule(): void
    {
        $this->dsConfig = $this->createSkipProvisioningConfig(['test.unit']);

        // Bez fixture core.mail/core.ai — guard musí vrátit před prvním
        // SQL na mail/ai tabulky.
        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');
        $this->dsConnection->expects($this->never())->method('fetchRow');
        $this->dsConnection->expects($this->never())->method('insertRow');

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringNotContainsString('_ai_analyzer', $tester->getDisplay());
        $this->assertStringNotContainsString('preprocess rule', $tester->getDisplay());
    }

    public function testUndecidedAccountChartSkipsSeedAndReportsTodo(): void
    {
        $this->createEconomyAccountingFixtureModule();
        $this->dsConfig = $this->createProvisioningConfig(['test.unit', 'economy.accounting']);

        // fetchSingle → null (mock default) = žádný settings klíč = nerozhodnuto.
        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('economy.accountChart není rozhodnuto', $display);
        $this->assertStringContainsString('[TODO] Nerozhodnuté parametry', $display);
        $this->assertStringContainsString('ds-setting set economy.accountChart default', $display);
        // economy.codebooks není aktivní — jeho parametr do [TODO] nepatří.
        $this->assertStringNotContainsString('economy.fiscalYearStartMonth', $display);
    }

    /**
     * Minimal economy.codebooks fixture module (empty tables) — enough to
     * pass the module guards in provisionFiscalYears()/provisionVatPeriods()
     * and to gate its layer C parameters in the [TODO] block.
     */
    private function createEconomyCodebooksFixtureModule(): void
    {
        $moduleDir = $this->modulesPath . '/economy/codebooks';
        mkdir($moduleDir, 0755, true);
        file_put_contents($moduleDir . '/module.jsonc', json_encode([
            'id'           => 'economy.codebooks',
            'name'         => 'economy.codebooks',
            'dependencies' => [],
            'tables'       => [],
            'extensions'   => [],
            'config'       => [],
        ]));
    }

    public function testUndecidedCodebooksParamsReportedAsTodo(): void
    {
        // Fixture modul s id economy.codebooks — gate pro fiscalYearStartMonth,
        // vatAgenda a homeCurrency v [TODO] výpisu (SPECS se iterují celé).
        $this->createEconomyCodebooksFixtureModule();
        $this->dsConfig = $this->createProvisioningConfig(['test.unit', 'economy.codebooks']);

        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('[TODO] Nerozhodnuté parametry', $display);
        $this->assertStringContainsString('ds-setting set economy.fiscalYearStartMonth 1', $display);
        $this->assertStringContainsString('ds-setting set economy.vatAgenda true', $display);
        $this->assertStringContainsString('ds-setting set economy.homeCurrency czk', $display);
        // economy.accounting není aktivní — jeho parametr do [TODO] nepatří.
        $this->assertStringNotContainsString('economy.accountChart', $display);
    }

    // ── Gate fiskálních roků — oba klíče (ds-setup Task 04) ──────────────────

    public function testFiscalYearsSkippedWhenHomeCurrencyUndecided(): void
    {
        $this->createEconomyCodebooksFixtureModule();
        $this->dsConfig = $this->createProvisioningConfig(['test.unit', 'economy.codebooks']);

        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');
        $this->dsConnection->method('fetchSingle')->willReturnCallback(
            static fn(mixed ...$args): mixed => ($args[1] ?? null) === 'economy.fiscalYearStartMonth' ? '1' : null,
        );
        $insertedYears = $this->captureFiscalYearInserts();

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        // [SKIP] jmenuje právě chybějící klíč — rozhodnutý měsíc v něm není.
        $this->assertStringContainsString('[SKIP] economy.homeCurrency není rozhodnuto', $display);
        $this->assertStringNotContainsString('economy.fiscalYearStartMonth není rozhodnuto', $display);
        $this->assertSame([], $insertedYears->rows);
    }

    public function testFiscalYearsSkippedWhenStartMonthUndecided(): void
    {
        $this->createEconomyCodebooksFixtureModule();
        $this->dsConfig = $this->createProvisioningConfig(['test.unit', 'economy.codebooks']);

        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');
        $this->dsConnection->method('fetchSingle')->willReturnCallback(
            static fn(mixed ...$args): mixed => ($args[1] ?? null) === 'economy.homeCurrency' ? '"eur"' : null,
        );
        $insertedYears = $this->captureFiscalYearInserts();

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString(
            '[SKIP] economy.fiscalYearStartMonth není rozhodnuto',
            $tester->getDisplay(),
        );
        $this->assertSame([], $insertedYears->rows);
    }

    public function testFiscalYearsSeededWithDecidedCurrency(): void
    {
        $this->createEconomyCodebooksFixtureModule();
        $this->dsConfig = $this->createProvisioningConfig(['test.unit', 'economy.codebooks']);

        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');
        $this->dsConnection->method('fetchSingle')->willReturnCallback(
            static fn(mixed ...$args): mixed => match ($args[1] ?? null) {
                'economy.fiscalYearStartMonth' => '1',
                'economy.homeCurrency'         => '"eur"',
                default                        => null,
            },
        );
        $insertedYears = $this->captureFiscalYearInserts();

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringNotContainsString('fiskální roky se neseedují', $tester->getDisplay());
        $this->assertNotSame([], $insertedYears->rows);
        foreach ($insertedYears->rows as $row) {
            $this->assertSame('eur', $row['currency']);
        }
    }

    /**
     * Zachytí insertRow do economy_codebooks_fiscal_years (ostatní tabulky
     * ignoruje — ReportPeriodsProvisioner apod. běží ve stejném průchodu).
     *
     * @return object{rows: list<array<string, mixed>>}
     */
    private function captureFiscalYearInserts(): object
    {
        $captured = new class {
            /** @var list<array<string, mixed>> */
            public array $rows = [];
        };
        $this->dsConnection->method('insertRow')->willReturnCallback(
            static function (string $table, array $row) use ($captured): int {
                if ($table === 'economy_codebooks_fiscal_years') {
                    $captured->rows[] = $row;
                }
                return 1;
            },
        );
        return $captured;
    }

    public function testDecidedAccountChartDoesNotReportTodo(): void
    {
        $this->createEconomyAccountingFixtureModule();
        $this->dsConfig = $this->createProvisioningConfig(['test.unit', 'economy.accounting']);

        $this->dsConnection->method('getTableColumns')->willReturn([]);
        $this->dsConnection->method('getTableIndexes')->willReturn([]);
        $this->dsConnection->method('executeSQL');
        $this->dsConnection->method('fetchSingle')->willReturnCallback(
            fn(mixed ...$args): mixed => ($args[1] ?? null) === 'economy.accountChart' ? '"default"' : null,
        );

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringNotContainsString('není rozhodnuto', $display);
        $this->assertStringNotContainsString('[TODO]', $display);
        // Seed soubor ve fixture modulu není — provisioner to hlásí, ale
        // rozhodnutý klíč se jako nerozhodnutý objevit nesmí.
        $this->assertStringContainsString('Account chart seed file not found', $display);
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
