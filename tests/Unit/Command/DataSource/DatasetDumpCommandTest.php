<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\DatasetDumpCommand;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\ExportedRecord;
use Shipard\Module\Core\Exchange\Dataset\RecordExporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDatasetDumpCommand extends DatasetDumpCommand
{
    /** @var list<RecordExporter> */
    public array $exporters = [];

    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        private readonly string $dsDir,
    ) {
        parent::__construct($dsConfig, $dsConnection);
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }

    protected function buildResolver(): ModulePathResolver
    {
        return new ModulePathResolver([]);
    }

    protected function loadTables(DataSourceConfig $dsConfig, ModulePathResolver $resolver, string $lang): array
    {
        return [];
    }

    protected function createExporters(Connection $db, array $tables, DataSourceConfig $dsConfig, string $dsDir, ?ConfigRuntime $config = null): array
    {
        return ['setup' => null, 'records' => $this->exporters];
    }
}

class DatasetDumpCommandTest extends TestCase
{
    private string $tmp;
    private string $dsDir;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_dumpcmd_' . bin2hex(random_bytes(4));
        $this->dsDir = $this->tmp . '/ds';
        mkdir($this->dsDir . '/config', 0755, true);
        file_put_contents($this->dsDir . '/config/main.json', '{}');
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->tmp);
    }

    private function command(bool $withMainJson = true): TestableDatasetDumpCommand
    {
        if (!$withMainJson) {
            unlink($this->dsDir . '/config/main.json');
        }
        $dsConfig = $this->createMock(DataSourceConfig::class);
        $dsConfig->method('getName')->willReturn('Demo firma s.r.o.');
        $dsConfig->method('getDefaultLanguage')->willReturn('cs');
        $dsConfig->method('hasCountry')->willReturn(false);
        $dsConnection = $this->createMock(DataSourceConnection::class);
        $dsConnection->method('getDibiConnection')->willReturn($this->createMock(Connection::class));

        $cmd = new TestableDatasetDumpCommand($dsConfig, $dsConnection, $this->dsDir);
        $cmd->exporters = [new class implements RecordExporter {
            public function section(): string { return 'persons'; }
            public function exportAll(): array { return [new ExportedRecord(1, 'Acme', ['format' => 'shpd.persons.person'])]; }
            public function exportByIds(array $ids): array { return $this->exportAll(); }
            public function getWarnings(): array { return ['persons Acme: test warning']; }
        }];
        return $cmd;
    }

    public function testDumpWritesSetAndReportsCounts(): void
    {
        $tester = new CommandTester($this->command());
        $exit = $tester->execute(['dir' => $this->tmp . '/web-demo', '--description' => 'Popis']);

        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('persons:   1', $display);
        $this->assertStringContainsString('warning: persons Acme: test warning', $display);
        $this->assertStringContainsString('1 warning(s)', $display);

        $reader = DatasetReader::open($this->tmp . '/web-demo');
        $m = $reader->getManifest();
        $this->assertSame('web-demo', $m->name, 'name defaults to slug of the directory');
        $this->assertSame('Demo firma s.r.o.', $m->title, 'title defaults to DS name');
        $this->assertSame('Popis', $m->description);
        $this->assertSame(['persons' => 1], $m->counts);
        $this->assertSame(['persons/0001-acme.jsonc'], $reader->listFiles('persons'));
    }

    public function testZipOptionWithoutValueUsesDirNameAndNameOverride(): void
    {
        $tester = new CommandTester($this->command());
        $exit = $tester->execute(['dir' => $this->tmp . '/out', '--zip' => null, '--name' => 'custom-name']);

        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertFileExists($this->tmp . '/out.zip');
        $reader = DatasetReader::open($this->tmp . '/out.zip');
        $this->assertSame('custom-name', $reader->getManifest()->name);
        $reader->close();
    }

    public function testNonEmptyTargetFailsWithoutForce(): void
    {
        mkdir($this->tmp . '/busy');
        file_put_contents($this->tmp . '/busy/manifest.jsonc', '{}');

        $tester = new CommandTester($this->command());
        $this->assertSame(Command::FAILURE, $tester->execute(['dir' => $this->tmp . '/busy']));
        $this->assertStringContainsString('not empty', $tester->getDisplay());

        $tester = new CommandTester($this->command());
        $this->assertSame(Command::SUCCESS, $tester->execute(['dir' => $this->tmp . '/busy', '--force' => true]));
    }

    public function testFailsOutsideDataSourceDirectory(): void
    {
        $tester = new CommandTester($this->command(withMainJson: false));
        $this->assertSame(Command::FAILURE, $tester->execute(['dir' => $this->tmp . '/x']));
        $this->assertStringContainsString('config/main.json', $tester->getDisplay());
    }
}
