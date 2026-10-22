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
use Shipard\Module\Economy\Items\Dataset\ProvisionedItemsDetector;
use Shipard\Module\Economy\Items\Dataset\ProvisionedItemsResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDatasetDumpCommand extends DatasetDumpCommand
{
    /** @var list<RecordExporter> */
    public array $exporters = [];

    public ?ProvisionedItemsDetector $detector = null;

    /** @var list<int>|null id vyloučených položek, jak je dostal createExporters */
    public ?array $excludedItemIds = null;

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

    protected function createDetector(DataSourceConnection $dsConnection, array $tables, string $lang, ModulePathResolver $resolver): ?ProvisionedItemsDetector
    {
        return $this->detector;
    }

    protected function createExporters(Connection $db, array $tables, DataSourceConfig $dsConfig, string $dsDir, ?ConfigRuntime $config = null, array $excludedItemIds = []): array
    {
        $this->excludedItemIds = $excludedItemIds;
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

    /**
     * @param string|null $country země v main.json (null = DS před zavedením klíče)
     * @param ProvisionedItemsResult|null $provisioned výsledek detektoru; null = DS bez economy_items
     */
    private function command(
        bool $withMainJson = true,
        ?string $country = null,
        ?ProvisionedItemsResult $provisioned = null,
    ): TestableDatasetDumpCommand {
        if (!$withMainJson) {
            unlink($this->dsDir . '/config/main.json');
        }
        $dsConfig = $this->createMock(DataSourceConfig::class);
        $dsConfig->method('getName')->willReturn('Demo firma s.r.o.');
        $dsConfig->method('getDefaultLanguage')->willReturn('cs');
        $dsConfig->method('hasCountry')->willReturn($country !== null);
        $dsConfig->method('getCountry')->willReturn((string) $country);
        $dsConnection = $this->createMock(DataSourceConnection::class);
        $dsConnection->method('getDibiConnection')->willReturn($this->createMock(Connection::class));

        $cmd = new TestableDatasetDumpCommand($dsConfig, $dsConnection, $this->dsDir);
        $cmd->exporters = [new class implements RecordExporter {
            public function section(): string { return 'persons'; }
            public function exportAll(): array { return [new ExportedRecord(1, 'Acme', ['format' => 'shpd.persons.person'])]; }
            public function exportByIds(array $ids): array { return $this->exportAll(); }
            public function getWarnings(): array { return ['persons Acme: test warning']; }
        }];
        if ($provisioned !== null) {
            $detector = $this->createMock(ProvisionedItemsDetector::class);
            $detector->method('detect')->willReturn($provisioned);
            $cmd->detector = $detector;
        }
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

    public function testManifestCarriesCountryAndProvisionInOrder(): void
    {
        $cmd = $this->command(country: 'CZ', provisioned: new ProvisionedItemsResult([3, 4], 'all'));
        $tester = new CommandTester($cmd);
        $exit = $tester->execute(['dir' => $this->tmp . '/demo', '--description' => 'Popis']);

        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertSame([3, 4], $cmd->excludedItemIds, 'detected items are kept out of the items exporter');
        $this->assertStringContainsString('provision: accountingItems=all (2 items not written to items/)', $tester->getDisplay());

        $reader = DatasetReader::open($this->tmp . '/demo');
        $this->assertSame(
            ['format', 'name', 'title', 'description', 'dateMode', 'created', 'country', 'provision', 'counts'],
            array_keys($reader->readJsonc('manifest.jsonc')),
        );
        $m = $reader->getManifest();
        $this->assertSame('cz', $m->country, 'country is written lower-case');
        $this->assertSame('all', $m->accountingItemsRequest());
    }

    public function testProvisionCodeListIsWrittenAndCounted(): void
    {
        $cmd = $this->command(provisioned: new ProvisionedItemsResult([7, 8], ['568201', '562100']));
        $tester = new CommandTester($cmd);
        $exit = $tester->execute(['dir' => $this->tmp . '/partial']);

        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertSame([7, 8], $cmd->excludedItemIds);
        $this->assertStringContainsString('provision: accountingItems=2 codes (2 items not written to items/)', $tester->getDisplay());

        $reader = DatasetReader::open($this->tmp . '/partial');
        $this->assertSame(
            ['format', 'name', 'title', 'dateMode', 'created', 'provision', 'counts'],
            array_keys($reader->readJsonc('manifest.jsonc')),
            'no country on a data source without one',
        );
        $this->assertSame(['568201', '562100'], $reader->getManifest()->accountingItemsRequest());
    }

    public function testEmptyDetectionLeavesManifestWithoutProvision(): void
    {
        foreach ([null, ProvisionedItemsResult::none()] as $i => $provisioned) {
            $cmd = $this->command(provisioned: $provisioned);
            $tester = new CommandTester($cmd);
            $exit = $tester->execute(['dir' => $this->tmp . '/plain' . $i]);

            $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
            $this->assertSame([], $cmd->excludedItemIds, 'nothing excluded');
            $this->assertStringNotContainsString('provision:', $tester->getDisplay());

            $reader = DatasetReader::open($this->tmp . '/plain' . $i);
            $this->assertSame(
                ['format', 'name', 'title', 'dateMode', 'created', 'counts'],
                array_keys($reader->readJsonc('manifest.jsonc')),
            );
            $this->assertNull($reader->getManifest()->accountingItemsRequest());
        }
    }
}
