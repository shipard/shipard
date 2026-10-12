<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Shipard\Command\Server\DsCreateCommand;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DatabaseManager;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Security\DsSecretCipher;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDsCreateCommand extends DsCreateCommand
{
    public function __construct(
        ServerConfig $mockConfig,
        DatabaseManager $mockDbManager,
        private readonly string $dataSourcesDir,
        private readonly string $modulesDir,
    ) {
        parent::__construct($mockConfig, $mockDbManager);
    }

    protected function getDataSourcesDir(): string
    {
        return $this->dataSourcesDir;
    }

    protected function getModulePathResolver(): ModulePathResolver
    {
        return new ModulePathResolver([$this->modulesDir]);
    }
}

class DsCreateCommandTest extends TestCase
{
    private string $rootDir;
    private string $tempDir;
    private string $modulesDir;
    private MockObject $serverConfig;
    private MockObject $databaseManager;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir() . '/shipard_cmd_test_' . uniqid();
        $this->tempDir = $this->rootDir . '/data-sources';
        $this->modulesDir = $this->rootDir . '/modules';
        mkdir($this->tempDir, 0755, true);

        mkdir($this->modulesDir . '/install/base', 0755, true);
        file_put_contents(
            $this->modulesDir . '/install/base/module.jsonc',
            (string) json_encode(['id' => 'install.base', 'name' => 'Base']),
        );

        $this->serverConfig = $this->createMock(ServerConfig::class);
        $this->serverConfig->method('load');

        $this->databaseManager = $this->createMock(DatabaseManager::class);
        $this->databaseManager->method('generatePassword')->willReturn('TestPassword123!@#$');
        $this->databaseManager->method('createDatabase');
        $this->databaseManager->method('createUser');
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->rootDir);
    }

    private function createCommandTester(): CommandTester
    {
        $command = new TestableDsCreateCommand(
            $this->serverConfig,
            $this->databaseManager,
            $this->tempDir,
            $this->modulesDir,
        );

        $app = new Application();
        $app->add($command);

        return new CommandTester($command);
    }

    private function createInstallModule(string $name): void
    {
        $dir = $this->modulesDir . '/install/' . $name;
        mkdir($dir, 0755, true);
        file_put_contents(
            $dir . '/module.jsonc',
            (string) json_encode(['id' => 'install.' . $name, 'name' => 'Module ' . $name]),
        );
    }

    public function testDsCreateRequiresName(): void
    {
        $tester = $this->createCommandTester();

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--name is required', $tester->getDisplay());
    }

    public function testDsCreateSucceeds(): void
    {
        $tester = $this->createCommandTester();

        $exitCode = $tester->execute(['--name' => 'My Test DS', '--language' => 'cs', '--country' => 'cz']);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Data source created successfully', $output);
        $this->assertStringContainsString('My Test DS', $output);
    }

    public function testDsCreateRequiresLanguage(): void
    {
        $this->databaseManager = $this->createMock(DatabaseManager::class);
        $this->databaseManager->expects($this->never())->method('createDatabase');
        $tester = $this->createCommandTester();

        $exitCode = $tester->execute(['--name' => 'Test', '--country' => 'cz']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--language is required', $tester->getDisplay());
        $this->assertCount(0, glob($this->tempDir . '/*', GLOB_ONLYDIR));
    }

    public function testDsCreateRequiresCountry(): void
    {
        $this->databaseManager = $this->createMock(DatabaseManager::class);
        $this->databaseManager->expects($this->never())->method('createDatabase');
        $tester = $this->createCommandTester();

        $exitCode = $tester->execute(['--name' => 'Test', '--language' => 'cs']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--country is required', $tester->getDisplay());
        $this->assertCount(0, glob($this->tempDir . '/*', GLOB_ONLYDIR));
    }

    public function testDsCreateRejectsInvalidLanguage(): void
    {
        $tester = $this->createCommandTester();

        foreach (['de', 'CS', 'czech'] as $invalid) {
            $exitCode = $tester->execute(['--name' => 'Test', '--language' => $invalid, '--country' => 'cz']);
            $this->assertSame(1, $exitCode, "language '{$invalid}' should be rejected");
            $this->assertStringContainsString('Invalid --language', $tester->getDisplay());
        }
        $this->assertCount(0, glob($this->tempDir . '/*', GLOB_ONLYDIR));
    }

    public function testDsCreateRejectsInvalidCountry(): void
    {
        $tester = $this->createCommandTester();

        foreach (['CZ', 'cze', 'c1', 'č'] as $invalid) {
            $exitCode = $tester->execute(['--name' => 'Test', '--language' => 'cs', '--country' => $invalid]);
            $this->assertSame(1, $exitCode, "country '{$invalid}' should be rejected");
            $this->assertStringContainsString('Invalid --country', $tester->getDisplay());
        }
        $this->assertCount(0, glob($this->tempDir . '/*', GLOB_ONLYDIR));
    }

    public function testDsCreateWritesConfigFile(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['--name' => 'Test', '--language' => 'cs', '--country' => 'cz']);

        $dirs = glob($this->tempDir . '/*', GLOB_ONLYDIR);
        $this->assertCount(1, $dirs);

        $configFile = $dirs[0] . '/config/main.json';
        $this->assertFileExists($configFile);

        $config = json_decode(file_get_contents($configFile), true);
        $this->assertSame('Test', $config['name']);
        $this->assertSame('cs', $config['defaultLanguage']);
        $this->assertSame('cz', $config['country']);
        $this->assertArrayHasKey('id', $config);
        $this->assertArrayHasKey('database_name', $config);
        $this->assertArrayHasKey('database_user', $config);
        $this->assertArrayHasKey('database_password', $config);
        $this->assertArrayHasKey('created', $config);
        $this->assertSame(0600, fileperms($configFile) & 0777);
    }

    public function testDsCreateNoCronWritesStateJson(): void
    {
        // #115 D14: cíl importu nesmí nikdy běžet se zapnutým cronem.
        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--name' => 'Import', '--language' => 'cs', '--country' => 'cz', '--no-cron' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Cron:          off', $tester->getDisplay());

        $dirs = glob($this->tempDir . '/*', GLOB_ONLYDIR);
        $this->assertCount(1, $dirs);
        $stateFile = $dirs[0] . '/config/state.json';
        $this->assertFileExists($stateFile);
        $raw = json_decode((string) file_get_contents($stateFile), true);
        $this->assertSame(1, $raw['version']);
        $this->assertSame('active', $raw['state']);
        $this->assertFalse($raw['cron']);
        $this->assertSame('ds-create', $raw['changedBy']);
        $this->assertFalse(\Shipard\Core\Config\DataSourceState::load($dirs[0])->isCronEnabled());
    }

    public function testDsCreateWithoutNoCronWritesNoStateJson(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['--name' => 'Plain', '--language' => 'cs', '--country' => 'cz']);

        $dirs = glob($this->tempDir . '/*', GLOB_ONLYDIR);
        $this->assertCount(1, $dirs);
        $this->assertFileDoesNotExist($dirs[0] . '/config/state.json');
        $this->assertStringNotContainsString('Cron:', $tester->getDisplay());
    }

    public function testDsCreateIdFormat(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['--name' => 'Test', '--language' => 'cs', '--country' => 'cz']);

        $dirs = glob($this->tempDir . '/*', GLOB_ONLYDIR);
        $dirName = basename($dirs[0]);

        $this->assertMatchesRegularExpression('/^[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$/', $dirName);
    }

    public function testDsCreateWritesDefaultModuleInMainJson(): void
    {
        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--name' => 'Test', '--language' => 'cs', '--country' => 'cz']);
        $this->assertSame(0, $exitCode);

        $dirs = glob($this->tempDir . '/*', GLOB_ONLYDIR);
        $config = json_decode(file_get_contents($dirs[0] . '/config/main.json'), true);
        $this->assertSame(['install.base'], $config['modules']);
    }

    public function testDsCreateWritesExplicitModuleInMainJson(): void
    {
        $this->createInstallModule('foo');
        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--name' => 'Test', '--language' => 'cs', '--country' => 'cz', '--module' => 'install.foo']);
        $this->assertSame(0, $exitCode);

        $dirs = glob($this->tempDir . '/*', GLOB_ONLYDIR);
        $config = json_decode(file_get_contents($dirs[0] . '/config/main.json'), true);
        $this->assertSame(['install.foo'], $config['modules']);
    }

    public function testDsCreateRejectsInvalidModuleFormat(): void
    {
        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--name' => 'Test', '--language' => 'cs', '--country' => 'cz', '--module' => 'core.system']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid install module id', $tester->getDisplay());
        $this->assertCount(0, glob($this->tempDir . '/*', GLOB_ONLYDIR));
    }

    public function testDsCreateRejectsNonExistentModule(): void
    {
        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--name' => 'Test', '--language' => 'cs', '--country' => 'cz', '--module' => 'install.nope']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Install module not found', $tester->getDisplay());
        $this->assertCount(0, glob($this->tempDir . '/*', GLOB_ONLYDIR));
    }

    public function testDsCreateRejectsNonExistentModuleListsAvailable(): void
    {
        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--name' => 'Test', '--language' => 'cs', '--country' => 'cz', '--module' => 'install.zzz']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Available: install.base', $tester->getDisplay());
    }

    public function testDsCreateOutputContainsModule(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['--name' => 'Test', '--language' => 'cs', '--country' => 'cz']);
        $this->assertStringContainsString('install.base', $tester->getDisplay());
    }

    public function testDsCreateOutputContainsLanguageAndCountry(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['--name' => 'Test', '--language' => 'en', '--country' => 'sk']);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Language:', $output);
        $this->assertStringContainsString('Country:', $output);
    }

    public function testDsCreateWithExplicitDsId(): void
    {
        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--name' => 'Test', '--language' => 'cs', '--country' => 'cz', '--ds-id' => 'ab12-cd34-ef56-gh78']);

        $this->assertSame(0, $exitCode);
        $this->assertDirectoryExists($this->tempDir . '/ab12-cd34-ef56-gh78');
        $config = json_decode(file_get_contents($this->tempDir . '/ab12-cd34-ef56-gh78/config/main.json'), true);
        $this->assertSame('ab12-cd34-ef56-gh78', $config['id']);
        $this->assertSame('ab12_cd34_ef56_gh78', $config['database_name']);
    }

    public function testDsCreateRejectsInvalidDsIdFormat(): void
    {
        $tester = $this->createCommandTester();

        foreach (['short', 'ABCD-EFGH-IJKL-MNOP', 'ab12-cd34-ef56', 'ab12_cd34_ef56_gh78'] as $invalid) {
            $exitCode = $tester->execute(['--name' => 'Test', '--language' => 'cs', '--country' => 'cz', '--ds-id' => $invalid]);
            $this->assertSame(1, $exitCode, "ds-id '{$invalid}' should be rejected");
            $this->assertStringContainsString('Invalid --ds-id', $tester->getDisplay());
        }
        $this->assertCount(0, glob($this->tempDir . '/*', GLOB_ONLYDIR));
    }

    public function testDsCreateRejectsExistingDsIdDirectory(): void
    {
        mkdir($this->tempDir . '/ab12-cd34-ef56-gh78', 0755, true);
        $tester = $this->createCommandTester();

        $exitCode = $tester->execute(['--name' => 'Test', '--language' => 'cs', '--country' => 'cz', '--ds-id' => 'ab12-cd34-ef56-gh78']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('already exists', $tester->getDisplay());
    }

    public function testDsCreateSetsSpecModeOnCreatedDirs(): void
    {
        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--name' => 'Test', '--language' => 'cs', '--country' => 'cz']);
        $this->assertSame(0, $exitCode);

        $dirs = glob($this->tempDir . '/*', GLOB_ONLYDIR);
        $this->assertCount(1, $dirs);
        $dsDir = $dirs[0];

        clearstatcache();
        foreach (['', '/config', '/att', '/branding', '/cache', '/cache/thumbnails', '/cache/oidc'] as $subdir) {
            $this->assertDirectoryExists($dsDir . $subdir);
            $this->assertSame(0750, fileperms($dsDir . $subdir) & 0777, "mode of '{$subdir}'");
        }
    }

    public function testDsCreateGeneratesSecretsKey(): void
    {
        DsSecretCipher::resetCache();
        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--name' => 'Secrets Test', '--language' => 'cs', '--country' => 'cz']);
        $this->assertSame(0, $exitCode);

        $dirs = glob($this->tempDir . '/*', GLOB_ONLYDIR);
        $this->assertCount(1, $dirs);
        $dsDir = $dirs[0];

        $secretsDir = $dsDir . '/secrets';
        $keyFile = $secretsDir . '/secrets.key';

        $this->assertDirectoryExists($secretsDir);
        $this->assertFileExists($keyFile);

        $this->assertSame(0700, fileperms($secretsDir) & 0777);
        $this->assertSame(0600, fileperms($keyFile) & 0777);
        $this->assertSame(DsSecretCipher::KEY_BYTES, filesize($keyFile));
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
