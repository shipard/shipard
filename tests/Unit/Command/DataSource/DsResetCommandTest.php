<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\DsResetCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDsResetCommand extends DsResetCommand
{
    public bool $upgradeRan = false;

    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        private readonly string $modulesPath,
        private readonly string $dsDir,
        private readonly string $serverMode = 'development',
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

    protected function getServerMode(): string
    {
        return $this->serverMode;
    }

    protected function runUpgrade(OutputInterface $output): int
    {
        $this->upgradeRan = true;
        return Command::SUCCESS;
    }
}

class DsResetCommandTest extends TestCase
{
    private string $tempDir;
    private string $modulesPath;
    private string $dsDir;
    private MockObject $dsConfig;
    private MockObject $dsConnection;
    private TestableDsResetCommand $command;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd_reset_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->modulesPath = $this->tempDir . '/modules';
        $this->dsDir = $this->tempDir . '/ds';

        $this->createFixtures();

        $this->dsConfig = $this->createMock(DataSourceConfig::class);
        $this->dsConfig->method('getModules')->willReturn(['test.unit']);
        $this->dsConfig->method('getName')->willReturn('Test DS');
        $this->dsConfig->method('getId')->willReturn('test-0001-test-0001');

        $this->dsConnection = $this->createMock(DataSourceConnection::class);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tempDir);
    }

    private function createFixtures(): void
    {
        $moduleDir = $this->modulesPath . '/test/unit';
        mkdir($moduleDir, 0755, true);
        mkdir($this->dsDir . '/config', 0755, true);

        file_put_contents($moduleDir . '/module.jsonc', json_encode([
            'id'           => 'test.unit',
            'name'         => 'Test Unit Module',
            'dependencies' => [],
            'tables'       => ['keep_me', 'drop_me'],
            'extensions'   => [],
            'config'       => [],
            'keepOnReset'  => ['keep_me'],
        ]));

        // Marker so the dir-guard in execute() passes when needed.
        file_put_contents($this->dsDir . '/config/main.json', '{}');
    }

    /**
     * @param string[] $tables names returned by getAllTableNames()
     */
    private function createCommandTester(
        array $tables,
        array &$executedSQLs,
        string $serverMode = 'development',
        bool $allowsReset = false,
    ): CommandTester {
        $this->dsConfig->method('allowsReset')->willReturn($allowsReset);
        $this->dsConnection->method('getAllTableNames')->willReturn($tables);
        $this->dsConnection->method('executeSQL')
            ->willReturnCallback(function (string $sql) use (&$executedSQLs): void {
                $executedSQLs[] = $sql;
            });

        $this->command = new TestableDsResetCommand(
            $this->dsConfig,
            $this->dsConnection,
            $this->modulesPath,
            $this->dsDir,
            $serverMode,
        );

        $app = new Application();
        $app->add($this->command);

        return new CommandTester($this->command);
    }

    public function testDropSetExcludesKeep(): void
    {
        $executed = [];
        $tester = $this->createCommandTester(['keep_me', 'drop_me', 'orphan_table'], $executed);
        $exitCode = $tester->execute(['--yes' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $dropSql = implode("\n", array_filter($executed, fn(string $s) => str_contains($s, 'DROP TABLE')));
        $this->assertStringContainsString('drop_me', $dropSql);
        $this->assertStringContainsString('orphan_table', $dropSql);
        $this->assertStringNotContainsString('keep_me', $dropSql);

        $this->assertTrue($this->command->upgradeRan);
    }

    public function testDryRunChangesNothing(): void
    {
        $executed = [];
        $tester = $this->createCommandTester(['keep_me', 'drop_me'], $executed);
        $exitCode = $tester->execute(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame([], $executed);

        $this->assertFalse($this->command->upgradeRan);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('[drop] drop_me', $display);
        $this->assertStringContainsString('[keep]', $display);
        $this->assertStringContainsString('keep_me', $display);
    }

    public function testProductionModeRefuses(): void
    {
        $executed = [];
        $tester = $this->createCommandTester(['drop_me'], $executed, 'production');
        $exitCode = $tester->execute(['--yes' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame([], $executed);
        $this->assertFalse($this->command->upgradeRan);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('production mode', $display);
        $this->assertStringContainsString('enableReset', $display);
    }

    public function testProductionModeAllowedWithEnableResetFlag(): void
    {
        $executed = [];
        $tester = $this->createCommandTester(['keep_me', 'drop_me'], $executed, 'production', allowsReset: true);
        $exitCode = $tester->execute(['--yes' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $dropSql = implode("\n", array_filter($executed, fn(string $s) => str_contains($s, 'DROP TABLE')));
        $this->assertStringContainsString('drop_me', $dropSql);

        $this->assertTrue($this->command->upgradeRan);
        $this->assertStringContainsString('PRODUCTION', $tester->getDisplay());
    }

    public function testDevelopmentModeWithoutFlagUnchanged(): void
    {
        $executed = [];
        $tester = $this->createCommandTester(['keep_me', 'drop_me'], $executed);
        $exitCode = $tester->execute(['--yes' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertTrue($this->command->upgradeRan);
        $this->assertStringNotContainsString('PRODUCTION', $tester->getDisplay());
    }

    public function testConfirmationDeclined(): void
    {
        $executed = [];
        $tester = $this->createCommandTester(['keep_me', 'drop_me'], $executed);
        $tester->setInputs(['n']);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame([], $executed);

        $this->assertFalse($this->command->upgradeRan);
        $this->assertStringContainsString('Aborted', $tester->getDisplay());
    }

    public function testConfirmationAccepted(): void
    {
        $executed = [];
        $tester = $this->createCommandTester(['keep_me', 'drop_me'], $executed);
        $tester->setInputs(['y']);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $dropSql = implode("\n", array_filter($executed, fn(string $s) => str_contains($s, 'DROP TABLE')));
        $this->assertStringContainsString('drop_me', $dropSql);

        $this->assertTrue($this->command->upgradeRan);
    }

    public function testAdHocKeepOption(): void
    {
        $executed = [];
        $tester = $this->createCommandTester(['keep_me', 'drop_me', 'orphan_table'], $executed);
        $exitCode = $tester->execute(['--yes' => true, '--keep' => ['orphan_table']]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $dropSql = implode("\n", array_filter($executed, fn(string $s) => str_contains($s, 'DROP TABLE')));
        $this->assertStringContainsString('drop_me', $dropSql);
        $this->assertStringNotContainsString('orphan_table', $dropSql);
    }

    public function testEmptyDropSetStillUpgrades(): void
    {
        $executed = [];
        $tester = $this->createCommandTester(['keep_me'], $executed);
        $exitCode = $tester->execute(['--yes' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame([], $executed);

        $this->assertTrue($this->command->upgradeRan);
    }

    public function testCleansAttachmentFoldersWhenFilesTableDropped(): void
    {
        mkdir($this->dsDir . '/att', 0755, true);
        mkdir($this->dsDir . '/cache/thumbnails', 0755, true);
        file_put_contents($this->dsDir . '/att/x.bin', 'data');
        file_put_contents($this->dsDir . '/cache/thumbnails/y.jpg', 'img');

        $executed = [];
        $tester = $this->createCommandTester(['keep_me', 'core_attachments_files'], $executed);
        $exitCode = $tester->execute(['--yes' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertFileDoesNotExist($this->dsDir . '/att/x.bin');
        $this->assertFileDoesNotExist($this->dsDir . '/cache/thumbnails/y.jpg');
        $this->assertDirectoryExists($this->dsDir . '/att');
        $this->assertDirectoryExists($this->dsDir . '/cache/thumbnails');
    }

    public function testNoFolderCleanWithoutAttachmentsTable(): void
    {
        mkdir($this->dsDir . '/att', 0755, true);
        file_put_contents($this->dsDir . '/att/x.bin', 'data');

        $executed = [];
        $tester = $this->createCommandTester(['keep_me', 'drop_me'], $executed);
        $exitCode = $tester->execute(['--yes' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertFileExists($this->dsDir . '/att/x.bin');
    }

    public function testResetKeepsStateJson(): void
    {
        // #115 D13: přepínač periodických úloh přežije cyklus reset → reimport.
        $stateJson = '{"version":1,"state":"active","cron":false,"changedBy":"cli","changed":"2026-10-12T10:00:00Z"}';
        file_put_contents($this->dsDir . '/config/state.json', $stateJson);

        $executed = [];
        $tester = $this->createCommandTester(['keep_me', 'drop_me', 'core_attachments_files'], $executed);
        $exitCode = $tester->execute(['--yes' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame($stateJson, file_get_contents($this->dsDir . '/config/state.json'));
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
