<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\DsSettingCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDsSettingCommand extends DsSettingCommand
{
    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        ServerConfig $serverConfig,
        private readonly string $modulesPath,
        private readonly string $dsDir,
    ) {
        parent::__construct($dsConfig, $dsConnection, $serverConfig);
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

class DsSettingCommandTest extends TestCase
{
    private string $tempDir;
    private string $modulesPath;
    private string $dsDir;
    private MockObject $dsConfig;
    private MockObject $dsConnection;

    /** @var array<string, string> in-memory core_system_settings: klíč => raw JSON */
    private array $kv = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd_dssetting_test_' . uniqid();
        $this->modulesPath = $this->tempDir . '/modules';
        $this->dsDir = $this->tempDir . '/ds';
        mkdir($this->dsDir . '/config', 0755, true);

        // Fixture modul se settingsPages — zdroj whitelistu mimo vrstvu C.
        $moduleDir = $this->modulesPath . '/test/unit';
        mkdir($moduleDir, 0755, true);
        file_put_contents($moduleDir . '/module.jsonc', json_encode([
            'id'            => 'test.unit',
            'name'          => 'Test Unit Module',
            'dependencies'  => [],
            'tables'        => [],
            'extensions'    => [],
            'config'        => [],
            'settingsPages' => [
                [
                    'id'     => 'testPage',
                    'name'   => 'Test page',
                    'fields' => [
                        ['id' => 'testx.someKey', 'type' => 'text', 'name' => 'Some key'],
                        ['id' => 'testx.someImage', 'type' => 'image', 'name' => 'Some image'],
                        ['id' => 'testx.someCheck', 'type' => 'checkbox', 'default' => true, 'name' => 'Some check'],
                        ['id' => 'testx.someSection', 'type' => 'section', 'name' => 'Some section'],
                    ],
                ],
            ],
        ]));

        $this->dsConfig = $this->createMock(DataSourceConfig::class);
        $this->dsConfig->method('getModules')->willReturn(['test.unit']);

        $this->kv = [];
        $this->dsConnection = $this->createMock(DataSourceConnection::class);
        $this->dsConnection->method('fetchSingle')->willReturnCallback(
            fn(mixed ...$args): mixed => $this->kv[(string) ($args[1] ?? '')] ?? null,
        );
        $this->dsConnection->method('execute')->willReturnCallback(
            function (mixed ...$args): void {
                // SettingsStore::set — INSERT ... ON DUPLICATE (sql, key, json, json)
                $this->kv[(string) $args[1]] = (string) $args[2];
            },
        );
        $this->dsConnection->method('deleteWhere')->willReturnCallback(
            function (string $table, string $where, mixed ...$params): void {
                unset($this->kv[(string) ($params[0] ?? '')]);
            },
        );
        $this->dsConnection->method('fetchAll')->willReturnCallback(
            function (): array {
                $rows = [];
                $keys = array_keys($this->kv);
                sort($keys);
                foreach ($keys as $key) {
                    $rows[] = ['key' => $key, 'value' => $this->kv[$key]];
                }
                return $rows;
            },
        );
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tempDir);
    }

    private function createCommandTester(): CommandTester
    {
        $serverConfig = $this->createMock(ServerConfig::class);

        $command = new TestableDsSettingCommand(
            $this->dsConfig,
            $this->dsConnection,
            $serverConfig,
            $this->modulesPath,
            $this->dsDir,
        );

        $app = new Application();
        $app->add($command);

        return new CommandTester($command);
    }

    public function testSetAndGetRoundtrip(): void
    {
        $tester = $this->createCommandTester();

        $exitCode = $tester->execute(['action' => 'set', 'key' => 'economy.accountChart', 'value' => 'npo']);
        $this->assertSame(0, $exitCode);
        $this->assertSame('"npo"', $this->kv['economy.accountChart']);

        $exitCode = $tester->execute(['action' => 'get', 'key' => 'economy.accountChart']);
        $this->assertSame(0, $exitCode);
        $this->assertSame('npo', trim($tester->getDisplay()));
    }

    public function testUnsetRemovesKeyAndGetFails(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['action' => 'set', 'key' => 'economy.accountChart', 'value' => 'default']);

        $exitCode = $tester->execute(['action' => 'set', 'key' => 'economy.accountChart', '--unset' => true]);
        $this->assertSame(0, $exitCode);
        $this->assertArrayNotHasKey('economy.accountChart', $this->kv);

        $exitCode = $tester->execute(['action' => 'get', 'key' => 'economy.accountChart']);
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('is not set', $tester->getDisplay());
    }

    public function testUnknownKeyRejectedAndNothingWritten(): void
    {
        $tester = $this->createCommandTester();

        $exitCode = $tester->execute(['action' => 'set', 'key' => 'economy.acountChart', 'value' => 'default']);

        $this->assertSame(1, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Unknown setting key: economy.acountChart', $display);
        $this->assertStringContainsString('economy.accountChart', $display); // výpis povolených
        $this->assertSame([], $this->kv);
    }

    public function testAccountChartValueOutsideWhitelistRejected(): void
    {
        $tester = $this->createCommandTester();

        $exitCode = $tester->execute(['action' => 'set', 'key' => 'economy.accountChart', 'value' => 'foo']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid value', $tester->getDisplay());
        $this->assertSame([], $this->kv);
    }

    public function testFiscalYearStartMonthRangeValidated(): void
    {
        $tester = $this->createCommandTester();

        foreach (['0', '13', 'leden'] as $invalid) {
            $exitCode = $tester->execute(['action' => 'set', 'key' => 'economy.fiscalYearStartMonth', 'value' => $invalid]);
            $this->assertSame(1, $exitCode, "value '{$invalid}' should be rejected");
            $this->assertStringContainsString('Invalid value', $tester->getDisplay());
        }
        $this->assertSame([], $this->kv);

        $exitCode = $tester->execute(['action' => 'set', 'key' => 'economy.fiscalYearStartMonth', 'value' => '4']);
        $this->assertSame(0, $exitCode);
        // Ukládá se jako int, ne string — provisioner hodnotu přímo použije.
        $this->assertSame('4', $this->kv['economy.fiscalYearStartMonth']);
    }

    public function testSettingsPagesKeyAllowed(): void
    {
        $tester = $this->createCommandTester();

        $exitCode = $tester->execute(['action' => 'set', 'key' => 'testx.someKey', 'value' => 'hello']);

        $this->assertSame(0, $exitCode);
        $this->assertSame('"hello"', $this->kv['testx.someKey']);
    }

    public function testCheckboxKeyStoresBoolean(): void
    {
        $tester = $this->createCommandTester();

        $this->assertSame(0, $tester->execute(['action' => 'set', 'key' => 'testx.someCheck', 'value' => 'false']));
        $this->assertSame('false', $this->kv['testx.someCheck'], 'boolean, ne řetězec "false"');

        $this->assertSame(0, $tester->execute(['action' => 'get', 'key' => 'testx.someCheck']));
        $this->assertSame('false', trim($tester->getDisplay()));

        $this->assertSame(0, $tester->execute(['action' => 'set', 'key' => 'testx.someCheck', 'value' => 'true']));
        $this->assertSame('true', $this->kv['testx.someCheck']);
    }

    public function testCheckboxKeyRejectsOtherValues(): void
    {
        $tester = $this->createCommandTester();

        foreach (['yes', '1', '0', 'TRUE'] as $value) {
            $exitCode = $tester->execute(['action' => 'set', 'key' => 'testx.someCheck', 'value' => $value]);

            $this->assertSame(1, $exitCode, $value);
            $this->assertStringContainsString('allowed values: true, false', $tester->getDisplay());
        }
        $this->assertSame([], $this->kv);
    }

    public function testSectionIsNotSettingKey(): void
    {
        // Mezinadpis stránky nastavení (#162 D138) není klíč: nejde nastavit,
        // smazat ani se nenabízí mezi povolenými klíči.
        $tester = $this->createCommandTester();

        $exitCode = $tester->execute(['action' => 'set', 'key' => 'testx.someSection', 'value' => 'x']);

        $this->assertSame(1, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Unknown setting key: testx.someSection', $display);
        $this->assertStringContainsString('testx.someCheck', $display, 'výpis povolených klíčů');
        $this->assertSame(
            1,
            substr_count($display, 'testx.someSection'),
            'mezinadpis je jen v hlášce o neznámém klíči, ne mezi povolenými',
        );
        $this->assertSame([], $this->kv);

        $this->assertSame(1, $tester->execute(['action' => 'set', 'key' => 'testx.someSection', '--unset' => true]));
        $this->assertSame([], $this->kv);
    }

    public function testStructuredKeyRefused(): void
    {
        $tester = $this->createCommandTester();

        $exitCode = $tester->execute(['action' => 'set', 'key' => 'testx.someImage', 'value' => 'x']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('structured data managed by the application', $tester->getDisplay());
        $this->assertSame([], $this->kv);
    }

    public function testValueAndUnsetAreMutuallyExclusive(): void
    {
        $tester = $this->createCommandTester();

        $exitCode = $tester->execute([
            'action'  => 'set',
            'key'     => 'economy.accountChart',
            'value'   => 'default',
            '--unset' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame([], $this->kv);
    }

    public function testListPrintsStoredKeys(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['action' => 'set', 'key' => 'economy.accountChart', 'value' => 'npo']);
        $tester->execute(['action' => 'set', 'key' => 'economy.fiscalYearStartMonth', 'value' => '7']);

        $exitCode = $tester->execute(['action' => 'list']);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('economy.accountChart', $display);
        $this->assertStringContainsString('"npo"', $display);
        $this->assertStringContainsString('economy.fiscalYearStartMonth', $display);
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
