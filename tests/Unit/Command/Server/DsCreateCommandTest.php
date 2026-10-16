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
    /** Server nabízí serverové AI připojení (klíč dev-ai-key) — jen souhrn (#126 D9). */
    public bool $serverAi = false;

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

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-16T12:00:00Z');
    }

    protected function serverOffersAi(ServerConfig $config): bool
    {
        return $this->serverAi;
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

    private function createCommandTester(bool $serverAi = false): CommandTester
    {
        $command = new TestableDsCreateCommand(
            $this->serverConfig,
            $this->databaseManager,
            $this->tempDir,
            $this->modulesDir,
        );
        $command->serverAi = $serverAi;

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
        $this->assertArrayHasKey('created', $config);
        $this->assertSame(0600, fileperms($configFile) & 0777);

        // Heslo k databázi jde do secrets/db.password, ne do main.json (#126 D1, D2).
        $this->assertArrayNotHasKey('database_password', $config);
        $passwordFile = $dirs[0] . '/secrets/db.password';
        $this->assertFileExists($passwordFile);
        $this->assertSame('TestPassword123!@#$', file_get_contents($passwordFile));
        $this->assertSame(0600, fileperms($passwordFile) & 0777);
        $this->assertStringNotContainsString('TestPassword123', $tester->getDisplay());
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

    /** @return array<string, mixed> main.json jediného založeného zdroje dat */
    private function createdMainJson(): array
    {
        $dirs = glob($this->tempDir . '/*', GLOB_ONLYDIR);
        $this->assertCount(1, $dirs);
        return json_decode((string) file_get_contents($dirs[0] . '/config/main.json'), true);
    }

    // ── třída dat (#126 D6, D7, D9) ──────────────────────────────────────────

    private const array BASE_ARGS = ['--name' => 'Dev', '--language' => 'cs', '--country' => 'cz'];

    public function testDsCreateOnADevelopmentServerIsFreeAndUsesTheServerAi(): void
    {
        // D7: ve vývoji free s původem ds-create; D9: serverové AI podle třídy a klíče.
        $this->serverConfig->method('getMode')->willReturn('development');
        $tester = $this->createCommandTester(serverAi: true);

        $this->assertSame(0, $tester->execute(self::BASE_ARGS));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Data class:    free', $display);
        $this->assertStringContainsString('Server AI:     on (free data source)', $display);
        $this->assertStringNotContainsString('Purpose:', $display);
        $this->assertStringNotContainsString('a real copy usually runs', $display);

        $config = $this->createdMainJson();
        $this->assertSame('free', $config['dataClass']);
        $this->assertSame('ds-create', $config['origin']);
        $this->assertSame('2026-10-16T12:00:00Z', $config['dataClassChanged']);
        $this->assertArrayNotHasKey('ai', $config, 'žádný vlastní příznak serverového AI');
        $this->assertArrayNotHasKey('purpose', $config);
    }

    public function testDsCreateFreeWithoutAServerKeyReportsServerAiOff(): void
    {
        $this->serverConfig->method('getMode')->willReturn('development');
        $tester = $this->createCommandTester(serverAi: false);

        $this->assertSame(0, $tester->execute(self::BASE_ARGS));
        $this->assertStringContainsString('Server AI:     off (no server AI key — shpd-server dev-ai-key)', $tester->getDisplay());
        $this->assertSame('free', $this->createdMainJson()['dataClass']);
    }

    public function testDsCreateRealCopyOnADevelopmentServer(): void
    {
        $this->serverConfig->method('getMode')->willReturn('development');
        $tester = $this->createCommandTester(serverAi: true);

        $this->assertSame(0, $tester->execute(self::BASE_ARGS + ['--real-copy' => true]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Data class:    realCopy', $display);
        $this->assertStringContainsString('Server AI:     off (realCopy)', $display);
        // D7: třída cron nemění — jen připomínka pro cíl importu.
        $this->assertStringContainsString('Cron:          on (a real copy usually runs without periodic jobs', $display);
        $this->assertFileDoesNotExist(glob($this->tempDir . '/*', GLOB_ONLYDIR)[0] . '/config/state.json');

        $config = $this->createdMainJson();
        $this->assertSame('realCopy', $config['dataClass']);
        $this->assertSame('ds-create --real-copy', $config['origin']);
    }

    public function testDsCreateRealCopyWithNoCronHasNoCronReminder(): void
    {
        $this->serverConfig->method('getMode')->willReturn('development');
        $tester = $this->createCommandTester();

        $this->assertSame(0, $tester->execute(self::BASE_ARGS + ['--real-copy' => true, '--no-cron' => true]));
        $this->assertStringContainsString('Cron:          off', $tester->getDisplay());
        $this->assertStringNotContainsString('a real copy usually runs', $tester->getDisplay());
    }

    public function testDsCreateInProductionIsAlwaysRealCopy(): void
    {
        $this->serverConfig->method('getMode')->willReturn('production');

        $tester = $this->createCommandTester(serverAi: true);
        $this->assertSame(0, $tester->execute(self::BASE_ARGS));
        $this->assertStringNotContainsString('Server AI:', $tester->getDisplay(), 'mimo development řádek chybí');
        $config = $this->createdMainJson();
        $this->assertSame('realCopy', $config['dataClass']);
        $this->assertSame('ds-create', $config['origin']);
        $this->assertArrayNotHasKey('ai', $config);
    }

    public function testDsCreateInProductionWithRealCopyKeepsTheOrigin(): void
    {
        $this->serverConfig->method('getMode')->willReturn('production');
        $tester = $this->createCommandTester();

        $this->assertSame(0, $tester->execute(self::BASE_ARGS + ['--real-copy' => true]));
        $this->assertSame('ds-create --real-copy', $this->createdMainJson()['origin']);
    }

    public function testDsCreateNoServerAiIsADeprecatedAliasOfRealCopy(): void
    {
        // U3: jedno vydání zůstává kvůli starým návodům a skriptům importu.
        $this->serverConfig->method('getMode')->willReturn('development');
        $tester = $this->createCommandTester(serverAi: true);

        $this->assertSame(0, $tester->execute(self::BASE_ARGS + ['--no-server-ai' => true]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('--no-server-ai is deprecated — use --real-copy', $display);
        $this->assertStringContainsString('Server AI:     off (realCopy)', $display);
        $config = $this->createdMainJson();
        $this->assertSame('realCopy', $config['dataClass']);
        $this->assertSame('ds-create --real-copy', $config['origin']);
    }

    public function testDsCreatePurposeIsStoredNormalized(): void
    {
        $this->serverConfig->method('getMode')->willReturn('development');
        $tester = $this->createCommandTester();

        $this->assertSame(0, $tester->execute(self::BASE_ARGS + ['--purpose' => '  Ověřování importu  ']));
        $this->assertStringContainsString('Purpose:       Ověřování importu', $tester->getDisplay());
        $this->assertSame('Ověřování importu', $this->createdMainJson()['purpose']);
    }

    public function testDsCreateEmptyPurposeLeavesTheKeyOut(): void
    {
        $this->serverConfig->method('getMode')->willReturn('development');
        $tester = $this->createCommandTester();

        $this->assertSame(0, $tester->execute(self::BASE_ARGS + ['--purpose' => '   ']));
        $this->assertArrayNotHasKey('purpose', $this->createdMainJson());
    }

    public function testDsCreateInvalidPurposeFailsBeforeAnyMutation(): void
    {
        $this->serverConfig->method('getMode')->willReturn('development');
        $tester = $this->createCommandTester();

        $this->assertSame(1, $tester->execute(self::BASE_ARGS + ['--purpose' => "první\ndruhý"]));
        $this->assertStringContainsString('Invalid --purpose: Purpose must be a single line', $tester->getDisplay());
        $this->assertCount(0, glob($this->tempDir . '/*', GLOB_ONLYDIR), 'žádný adresář zdroje dat');

        $tester = $this->createCommandTester();
        $this->assertSame(1, $tester->execute(self::BASE_ARGS + ['--purpose' => str_repeat('x', 201)]));
        $this->assertStringContainsString('Purpose is too long (max 200 characters)', $tester->getDisplay());
        $this->assertCount(0, glob($this->tempDir . '/*', GLOB_ONLYDIR));
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
