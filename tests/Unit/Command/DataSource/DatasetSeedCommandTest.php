<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\DatasetSeedCommand;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\DatasetWriter;
use Shipard\Module\Core\Exchange\Dataset\SectionSeeder;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Dataset\SeedReport;
use Shipard\Tests\Unit\Module\Core\Exchange\Dataset\DatasetPreflightTest;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDatasetSeedCommand extends DatasetSeedCommand
{
    public int $resetRuns = 0;
    public int $upgradeRuns = 0;
    public int $upgradeResult = Command::SUCCESS;
    public bool $settingsTable = true;
    public int $settingsApplied = -1;
    public int $settingsToReport = 3;
    public int $resetResult = Command::SUCCESS;
    public ?bool $lastMerge = null;
    public string $serverMode = 'development';
    /** Server nabízí serverové AI připojení (klíč dev-ai-key) — jen souhrn (#126 D9). */
    public bool $serverAi = false;
    /** @var list<array{string, string, ?string}> volání markFree: adresář, původ, účel z titulku */
    public array $marked = [];
    public ?string $markError = null;
    /** @var list<string> pořadí kroků upgrade / settings / reset / markFree */
    public array $order = [];
    /** @var list<SectionSeeder> */
    public array $seeders = [];

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

    protected function hasSettingsTable(DataSourceConnection $dsConnection): bool
    {
        return $this->settingsTable;
    }

    protected function runUpgrade(OutputInterface $output): int
    {
        $this->upgradeRuns++;
        $this->order[] = 'upgrade';
        return $this->upgradeResult;
    }

    protected function applySettingsBeforeReset(DatasetReader $reader, DataSourceConfig $dsConfig): int
    {
        $this->settingsApplied = $this->resetRuns; // musí být 0 = před resetem
        $this->order[] = 'settings';
        return $this->settingsToReport;
    }

    protected function runReset(OutputInterface $output): int
    {
        $this->resetRuns++;
        $this->order[] = 'reset';
        return $this->resetResult;
    }

    protected function getServerMode(): string
    {
        return $this->serverMode;
    }

    protected function serverOffersAi(): bool
    {
        return $this->serverAi;
    }

    protected function markFree(string $dsDir, string $origin, ?string $purpose): void
    {
        if ($this->markError !== null) {
            throw new \RuntimeException($this->markError);
        }
        $this->order[] = 'markFree';
        $this->marked[] = [$dsDir, $origin, $purpose];
    }

    /** @var (\Closure(DatasetReader, DataSourceConfig, DataSourceConnection, string, bool): SeedContext)|null */
    public ?\Closure $contextFactory = null;

    protected function createContext(
        DatasetReader $reader,
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        string $dsDir,
        bool $merge,
    ): SeedContext {
        $this->lastMerge = $merge;
        return ($this->contextFactory)($reader, $dsConfig, $dsConnection, $dsDir, $merge);
    }

    protected function createSeeders(SeedContext $ctx): array
    {
        return $this->seeders;
    }
}

class DatasetSeedCommandTest extends TestCase
{
    private string $tmp;
    private string $dsDir;
    private string $setDir;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_seedcmd_' . bin2hex(random_bytes(4));
        $this->dsDir = $this->tmp . '/ds';
        mkdir($this->dsDir . '/config', 0755, true);
        file_put_contents($this->dsDir . '/config/main.json', '{}');
        $this->setDir = $this->writeSet();
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->tmp);
    }

    private function writeSet(bool $valid = true, ?string $country = null): string
    {
        $dir = $this->tmp . '/set' . ($valid ? '' : '-bad') . ($country !== null ? "-{$country}" : '');
        $w = DatasetWriter::create($dir);
        $w->writeJsonc('persons/0001-acme.jsonc', $valid ? DatasetPreflightTest::person() : ['format' => 'shpd.persons.person']);
        $w->writeManifest(new DatasetManifest(
            'demo', 'Demo', null, 'fixed', '2026-08-26T10:00:00Z', ['persons' => 1],
            country: $country,
        ));
        return $dir;
    }

    // ── třída dat (#126 D7, D9, D11) ─────────────────────────────────────────

    public function testResetMarksTheDataSourceFreeAfterASuccessfulReset(): void
    {
        // D7/U2/U4: free až po úspěšném resetu, původ dataset-seed <sada>, účel z titulku sady.
        $cmd = $this->command();
        $tester = new CommandTester($cmd);
        $exit = $tester->execute(['path' => $this->setDir, '--yes' => true]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $exit, $display);
        $this->assertSame([[$this->dsDir, 'dataset-seed demo', 'Demo']], $cmd->marked);
        $this->assertSame(['settings', 'reset', 'markFree'], $cmd->order);
        $this->assertStringContainsString('Data class: free (origin: dataset-seed demo)', $display);
        $this->assertStringContainsString('Server AI: off (no server AI key — shpd-server dev-ai-key)', $display);
    }

    public function testFreeDataSourceWithAServerKeyReportsServerAiOn(): void
    {
        $cmd = $this->command();
        $cmd->serverAi = true;
        $tester = new CommandTester($cmd);

        $this->assertSame(Command::SUCCESS, $tester->execute(['path' => $this->setDir, '--yes' => true]));
        $this->assertStringContainsString('Server AI: on (free data source)', $tester->getDisplay());
    }

    public function testProductionSeedMarksFreeWithoutAServerAiLine(): void
    {
        // Platí v obou režimech (zdroj s enableReset na produkci); řádek Server AI jen ve vývoji.
        $cmd = $this->command();
        $cmd->serverMode = 'production';
        $tester = new CommandTester($cmd);

        $this->assertSame(Command::SUCCESS, $tester->execute(['path' => $this->setDir, '--yes' => true]));
        $this->assertCount(1, $cmd->marked);
        $this->assertStringNotContainsString('Server AI:', $tester->getDisplay());
    }

    public function testFailedResetLeavesTheDataClassAlone(): void
    {
        $cmd = $this->command();
        $cmd->resetResult = Command::FAILURE;
        $tester = new CommandTester($cmd);

        $this->assertSame(Command::FAILURE, $tester->execute(['path' => $this->setDir, '--yes' => true]));
        $this->assertSame([], $cmd->marked, 'selhaný reset = data jsou původní, třída se nemění');
        $this->assertStringNotContainsString('Data class: free (origin', $tester->getDisplay());
    }

    public function testNoResetLeavesTheDataClassAlone(): void
    {
        $cmd = $this->command();
        $tester = new CommandTester($cmd);

        $this->assertSame(Command::SUCCESS, $tester->execute(['path' => $this->setDir, '--yes' => true, '--no-reset' => true]));
        $this->assertSame([], $cmd->marked);
        $this->assertStringNotContainsString('Data class: free (origin', $tester->getDisplay());
    }

    public function testMarkFreeFailureAfterResetIsAnError(): void
    {
        $cmd = $this->command();
        $cmd->markError = 'cannot replace main.json';
        $tester = new CommandTester($cmd);

        $this->assertSame(Command::FAILURE, $tester->execute(['path' => $this->setDir, '--yes' => true]));
        $display = $tester->getDisplay();
        $this->assertSame(1, $cmd->resetRuns);
        $this->assertStringContainsString('Data class update failed after reset: cannot replace main.json', $display);
        $this->assertStringContainsString('shpd-ds ds-class set free', $display);
        $this->assertStringNotContainsString('Seed complete', $display);
    }

    public function testPromptNamesTheDataClassOfARealCopy(): void
    {
        // D11: řádek třídy a dotaz uvádějí REAL COPY s účelem; chování --yes se nemění.
        $cmd = $this->command(purpose: 'Ověřování importu');
        $tester = new CommandTester($cmd);
        $tester->setInputs(['n']);

        $this->assertSame(Command::SUCCESS, $tester->execute(['path' => $this->setDir]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Data class: realCopy — Ověřování importu', $display);
        $this->assertStringContainsString("RESET data source 'Test DS' — a REAL COPY (purpose: Ověřování importu) (all data tables dropped)", $display);
        $this->assertStringContainsString('Aborted.', $display);
        $this->assertSame(0, $cmd->resetRuns);
    }

    public function testRealCopyWithoutPurposeAndWithYesStillPrintsTheClassLine(): void
    {
        $cmd = $this->command();
        $tester = new CommandTester($cmd);

        $this->assertSame(Command::SUCCESS, $tester->execute(['path' => $this->setDir, '--yes' => true]));
        $this->assertStringContainsString('Data class: realCopy — no purpose set', $tester->getDisplay());
    }

    public function testPromptForAFreeDataSourceIsPlain(): void
    {
        $cmd = $this->command(free: true);
        $tester = new CommandTester($cmd);
        $tester->setInputs(['n']);

        $this->assertSame(Command::SUCCESS, $tester->execute(['path' => $this->setDir]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Data class: free', $display);
        $this->assertStringNotContainsString('REAL COPY', $display);
    }

    private function command(string $dsCountry = 'cz', bool $free = false, ?string $purpose = null): TestableDatasetSeedCommand
    {
        $dsConfig = $this->createMock(DataSourceConfig::class);
        $dsConfig->method('getName')->willReturn('Test DS');
        $dsConfig->method('getDefaultLanguage')->willReturn('cs');
        $dsConfig->method('getCountry')->willReturn($dsCountry);
        $dsConfig->method('hasCountry')->willReturn(true);
        $dsConfig->method('isFree')->willReturn($free);
        $dsConfig->method('getPurpose')->willReturn($purpose);
        $dsConnection = $this->createMock(DataSourceConnection::class);

        $cmd = new TestableDatasetSeedCommand($dsConfig, $dsConnection, $this->dsDir);
        $cmd->setHelperSet(new HelperSet([new QuestionHelper()]));
        $cmd->contextFactory = fn(DatasetReader $reader, DataSourceConfig $cfg, DataSourceConnection $conn, string $dir, bool $merge): SeedContext
            => new SeedContext(
                reader: $reader,
                db: $this->createMock(Connection::class),
                dsConnection: $conn,
                config: $this->createMock(ConfigRuntime::class),
                dsConfig: $cfg,
                registry: new DocumentRegistry(),
                tables: [],
                dsDir: $dir,
                attachments: $this->createMock(AttachmentService::class),
                dispatcher: null,
                merge: $merge,
            );
        $cmd->seeders = [new class implements SectionSeeder {
            public function section(): string { return 'persons'; }
            public function seed(SeedContext $ctx, SeedReport $report): void
            {
                foreach ($ctx->reader->listFiles('persons') as $rel) {
                    $report->ok('persons');
                }
            }
        }];
        return $cmd;
    }

    private function contextAwareCommand(): TestableDatasetSeedCommand
    {
        return $this->command();
    }

    public function testResetAndSeedWithYes(): void
    {
        $cmd = $this->contextAwareCommand();
        $tester = new CommandTester($cmd);
        $exit = $tester->execute(['path' => $this->setDir, '--yes' => true]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $exit, $display);
        $this->assertSame(1, $cmd->resetRuns);
        $this->assertSame(0, $cmd->settingsApplied, 'settings are applied before the reset runs');
        $this->assertStringContainsString('Applied 3 data source setting(s)', $display);
        $this->assertFalse($cmd->lastMerge);
        $this->assertStringContainsString('Dataset demo — Demo (persons 1)', $display);
        $this->assertStringContainsString('persons:          ok 1', $display);
        $this->assertStringContainsString('Seed complete.', $display);
        $this->assertSame(0, $cmd->upgradeRuns, 'existing schema → no ds-upgrade before settings');
        $this->assertSame(['settings', 'reset', 'markFree'], $cmd->order);
    }

    public function testFreshDataSourceGetsSchemaBeforeSettings(): void
    {
        $cmd = $this->contextAwareCommand();
        $cmd->settingsTable = false;
        $tester = new CommandTester($cmd);
        $exit = $tester->execute(['path' => $this->setDir, '--yes' => true]);

        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertSame(['upgrade', 'settings', 'reset', 'markFree'], $cmd->order);
        $this->assertStringContainsString('Fresh data source', $tester->getDisplay());
    }

    public function testFreshDataSourceUpgradeFailureStopsBeforeSettings(): void
    {
        $cmd = $this->contextAwareCommand();
        $cmd->settingsTable = false;
        $cmd->upgradeResult = Command::FAILURE;
        $tester = new CommandTester($cmd);
        $exit = $tester->execute(['path' => $this->setDir, '--yes' => true]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame(['upgrade'], $cmd->order);
        $this->assertStringContainsString('ds-upgrade failed', $tester->getDisplay());
    }

    public function testCountryMismatchFailsInPreflightBeforeAnyChange(): void
    {
        $cmd = $this->command('cz');
        $tester = new CommandTester($cmd);
        $exit = $tester->execute(['path' => $this->writeSet(country: 'sk'), '--yes' => true]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::FAILURE, $exit, $display);
        $this->assertSame([], $cmd->order, 'no upgrade / settings / reset after a country mismatch');
        $this->assertSame(-1, $cmd->settingsApplied);
        $this->assertStringContainsString('preflight failed', $display);
        $this->assertStringContainsString("country 'sk'", $display);
    }

    public function testMatchingCountrySeeds(): void
    {
        $cmd = $this->command('cz');
        $tester = new CommandTester($cmd);
        $exit = $tester->execute(['path' => $this->writeSet(country: 'cz'), '--yes' => true]);

        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertSame(1, $cmd->resetRuns);
    }

    public function testNoResetSkipsResetAndSetsMerge(): void
    {
        $cmd = $this->contextAwareCommand();
        $tester = new CommandTester($cmd);
        $exit = $tester->execute(['path' => $this->setDir, '--yes' => true, '--no-reset' => true]);

        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertSame(0, $cmd->resetRuns);
        $this->assertSame(-1, $cmd->settingsApplied, 'merge mode leaves DS settings alone');
        $this->assertTrue($cmd->lastMerge);
    }

    public function testDeclinedConfirmationAbortsBeforeReset(): void
    {
        $cmd = $this->contextAwareCommand();
        $tester = new CommandTester($cmd);
        $tester->setInputs(['n']);
        $exit = $tester->execute(['path' => $this->setDir]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame(0, $cmd->resetRuns);
        $this->assertStringContainsString('RESET data source', $tester->getDisplay());
        $this->assertStringContainsString('Aborted.', $tester->getDisplay());
    }

    public function testPreflightFailureStopsBeforeReset(): void
    {
        $cmd = $this->contextAwareCommand();
        $tester = new CommandTester($cmd);
        $exit = $tester->execute(['path' => $this->writeSet(valid: false), '--yes' => true]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame(0, $cmd->resetRuns);
        $this->assertStringContainsString('preflight failed', $tester->getDisplay());
        $this->assertStringContainsString('persons/0001-acme.jsonc:', $tester->getDisplay());
    }

    public function testResetFailureStopsSeed(): void
    {
        $cmd = $this->contextAwareCommand();
        $cmd->resetResult = Command::FAILURE;
        $tester = new CommandTester($cmd);
        $exit = $tester->execute(['path' => $this->setDir, '--yes' => true]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('ds-reset failed', $tester->getDisplay());
        $this->assertStringNotContainsString('Seed complete', $tester->getDisplay());
    }

    public function testRecordErrorsGiveFailureExit(): void
    {
        $cmd = $this->contextAwareCommand();
        $cmd->seeders = [new class implements SectionSeeder {
            public function section(): string { return 'persons'; }
            public function seed(SeedContext $ctx, SeedReport $report): void
            {
                $report->failed('persons', '0001-acme.jsonc: validation_failed — x');
            }
        }];
        $tester = new CommandTester($cmd);
        $exit = $tester->execute(['path' => $this->setDir, '--yes' => true]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('failed 1', $tester->getDisplay());
        $this->assertStringContainsString('Seed finished with 1 error(s)', $tester->getDisplay());
    }

    public function testInvalidPathFails(): void
    {
        $tester = new CommandTester($this->contextAwareCommand());
        $this->assertSame(Command::FAILURE, $tester->execute(['path' => $this->tmp . '/nope', '--yes' => true]));
        $this->assertStringContainsString('not found', $tester->getDisplay());
    }

    public function testOutsideDataSourceDirectoryFails(): void
    {
        unlink($this->dsDir . '/config/main.json');
        $tester = new CommandTester($this->contextAwareCommand());
        $this->assertSame(Command::FAILURE, $tester->execute(['path' => $this->setDir, '--yes' => true]));
    }
}
