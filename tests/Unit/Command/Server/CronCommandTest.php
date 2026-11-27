<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\CronCommand;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Server\CronJob;
use Shipard\Core\Server\CronProvisioner;
use Shipard\Core\Server\DataSourceModules;
use Symfony\Component\Console\Tester\CommandTester;

/** Reálná rezoluce nad dočasným stromem modulů, jen s čítači volání švů. */
class CountingDataSourceModules extends DataSourceModules
{
    public int $definitionLoads = 0;
    public int $queries = 0;

    public function activeIds(string $dsDir): ?array
    {
        $this->queries++;
        return parent::activeIds($dsDir);
    }

    protected function loadDefinitions(): array
    {
        $this->definitionLoads++;
        return parent::loadDefinitions();
    }
}

class TestableCronCommand extends CronCommand
{
    /** @var array<string, array{exitCode: int, timedOut: bool, output: string}> klíč "dsId job" */
    private array $jobResults = [];

    /** @var list<array{ds: string, job: string}> */
    public array $callLog = [];

    /** null = strom modulů repa (main.json `{}` testů = moduly nelze určit → všechny joby). */
    public ?DataSourceModules $modules = null;

    public function __construct(
        private readonly string $dataSourcesDir,
        private readonly string $runDir,
        private readonly ?string $logPath,
    ) {
        parent::__construct();
    }

    /** @param array<string, array{exitCode: int, timedOut: bool, output: string}> $results */
    public function setJobResults(array $results): void
    {
        $this->jobResults = $results;
    }

    protected function getDataSourcesDir(): string
    {
        return $this->dataSourcesDir;
    }

    protected function getRunDir(): string
    {
        return $this->runDir;
    }

    protected function getLogPath(): ?string
    {
        return $this->logPath;
    }

    protected function createDataSourceModules(): DataSourceModules
    {
        // Jen hlavní strom repa — `extraModulesPath` ze server.json stroje,
        // na kterém testy běží, do úloh slotu mluvit nesmí.
        return $this->modules
            ??= new DataSourceModules(new ModulePathResolver([dirname(__DIR__, 4) . '/modules']));
    }

    protected function runJob(string $dsDir, string $job): array
    {
        $id = basename($dsDir);
        $this->callLog[] = ['ds' => $id, 'job' => $job];
        return $this->jobResults[$id . ' ' . $job]
            ?? ['exitCode' => 0, 'timedOut' => false, 'output' => ''];
    }

    protected function runServerJob(string $job): array
    {
        $this->callLog[] = ['ds' => '(server)', 'job' => $job];
        return $this->jobResults['(server) ' . $job]
            ?? ['exitCode' => 0, 'timedOut' => false, 'output' => ''];
    }
}

/** Timeout test potřebuje reálný runJob s podvrženou binárkou a krátkým limitem. */
class TimeoutCronCommand extends CronCommand
{
    public function __construct(private readonly string $shpdDsPath)
    {
        parent::__construct();
    }

    protected function getShpdDsPath(): string
    {
        return $this->shpdDsPath;
    }

    protected function getJobTimeoutSeconds(): int
    {
        return 1;
    }

    /** @return array{exitCode: int, timedOut: bool, output: string} */
    public function runJobPublic(string $dsDir, string $job): array
    {
        return $this->runJob($dsDir, $job);
    }
}

class CronCommandTest extends TestCase
{
    /**
     * Úlohy slotů, jak je deklarují moduly (shodu `module.jsonc` s touto
     * tabulkou hlídá CronJobRegistryTest).
     */
    private const SLOT_JOBS = [
        'minute'       => [
            'mail-outbox-run', 'mail-analysis-reap', 'mail-preprocess --sweep', 'mail-analyze --sweep',
            'sales-registration-run',
        ],
        'two-minutes'  => [],
        'five-minutes' => ['device-challenges-prune', 'exchange-rates-download', 'sales-registration-reconcile', 'alerts-run'],
        'daily'        => ['mail-idempotency-prune', 'vat-periods-ensure', 'work-orders-invoice-run'],
        'weekly'       => ['alerts-prune'],
    ];

    private string $tmpDir;
    private string $dsDir;
    private string $runDir;
    private string $logPath;

    protected function setUp(): void
    {
        ErrorLogger::resetForTesting();
        $this->tmpDir = sys_get_temp_dir() . '/shpd-cron-test-' . uniqid();
        $this->dsDir = $this->tmpDir . '/data-sources';
        $this->runDir = $this->tmpDir . '/run';
        $this->logPath = $this->tmpDir . '/shipard.log';
        mkdir($this->dsDir, 0755, true);
        mkdir($this->runDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
        ErrorLogger::resetForTesting();
    }

    /**
     * Bez `$modules` je main.json `{}` — DataSourceConfig ho odmítne, moduly
     * nelze určit a běží všechny joby (chování před D15). S `$modules` vznikne
     * úplný main.json, moduly se rozvinou nad stromem z makeModuleTree().
     *
     * @param list<string>|null $modules
     */
    private function createDs(string $id, ?string $stateJson = null, ?array $modules = null): string
    {
        $dir = $this->dsDir . '/' . $id;
        mkdir($dir . '/config', 0755, true);
        $main = $modules === null ? '{}' : (string) json_encode([
            'id' => $id,
            'name' => 'Test ' . $id,
            'modules' => $modules,
            'database_name' => 'db',
            'database_user' => 'user',
            'created' => '2026-10-13T10:00:00+00:00',
        ]);
        file_put_contents($dir . '/config/main.json', $main);
        if ($stateJson !== null) {
            file_put_contents($dir . '/config/state.json', $stateJson);
        }
        return $dir;
    }

    /**
     * Dočasný strom modulů: install.mailless = jen core.system + core.alerts
     * (jako install.hosting), install.full = všechny moduly s úlohou cronu.
     * Úlohy (`cronJobs`) kopírují deklarace skutečných modulů.
     */
    private function makeModuleTree(): ModulePathResolver
    {
        $root = $this->tmpDir . '/modules';
        if (is_dir($root)) {
            return new ModulePathResolver([$root]);
        }
        $cleanup = ['active', 'read_only'];
        $defs = [
            'core/system'        => ['core.system', [], []],
            'core/alerts'        => ['core.alerts', ['core.system'], [
                ['command' => 'alerts-run', 'slot' => 'five-minutes', 'order' => 900],
                ['command' => 'alerts-prune', 'slot' => 'weekly', 'states' => $cleanup],
            ]],
            'core/devices'       => ['core.devices', ['core.system'], [
                ['command' => 'device-challenges-prune', 'slot' => 'five-minutes', 'states' => $cleanup],
            ]],
            'core/mail'          => ['core.mail', ['core.system'], [
                ['command' => 'mail-outbox-run', 'slot' => 'minute'],
                ['command' => 'mail-analysis-reap', 'slot' => 'minute'],
                ['command' => 'mail-preprocess --sweep', 'slot' => 'minute'],
                ['command' => 'mail-analyze --sweep', 'slot' => 'minute'],
                ['command' => 'mail-idempotency-prune', 'slot' => 'daily', 'states' => $cleanup],
            ]],
            'economy/vat'        => ['economy.vat', ['core.system'], [
                ['command' => 'vat-periods-ensure', 'slot' => 'daily'],
            ]],
            'economy/workOrders' => ['economy.workOrders', ['core.system'], [
                ['command' => 'work-orders-invoice-run', 'slot' => 'daily'],
            ]],
            'economy/exchangeRates' => ['economy.exchangeRates', ['core.system'], [
                ['command' => 'exchange-rates-download', 'slot' => 'five-minutes'],
            ]],
            'economy/salesRegistration' => ['economy.salesRegistration', ['core.system'], [
                ['command' => 'sales-registration-run', 'slot' => 'minute'],
                ['command' => 'sales-registration-reconcile', 'slot' => 'five-minutes'],
            ]],
            'install/mailless'   => ['install.mailless', ['core.system', 'core.alerts'], []],
            'install/full'       => ['install.full', ['core.system', 'core.alerts', 'core.devices', 'core.mail', 'economy.vat', 'economy.workOrders', 'economy.exchangeRates', 'economy.salesRegistration'], []],
        ];
        foreach ($defs as $path => [$id, $deps, $cronJobs]) {
            mkdir($root . '/' . $path, 0755, true);
            file_put_contents($root . '/' . $path . '/module.jsonc', json_encode([
                'id' => $id,
                'name' => $id,
                'dependencies' => $deps,
                'cronJobs' => $cronJobs,
            ]));
        }
        return new ModulePathResolver([$root]);
    }

    /** @return array{TestableCronCommand, CommandTester} */
    private function makeTester(?DataSourceModules $modules = null): array
    {
        $cmd = new TestableCronCommand($this->dsDir, $this->runDir, $this->logPath);
        $cmd->modules = $modules;
        return [$cmd, new CommandTester($cmd)];
    }

    private function makeModules(): CountingDataSourceModules
    {
        return new CountingDataSourceModules($this->makeModuleTree());
    }

    /** @return array<string, mixed> */
    private function readHeartbeat(string $slot): array
    {
        $path = CronProvisioner::heartbeatPath($slot, $this->runDir);
        $this->assertFileExists($path);
        $data = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($data);
        return $data;
    }

    public function testTwoMinutesSlotRunsServerJobOncePerRun(): void
    {
        // Server-level job (hosting-sync) běží jednou za slot, ne per DS.
        $this->createDs('aaaa-bbbb-cccc-dddd');
        $this->createDs('eeee-ffff-0000-1111');
        [$cmd, $tester] = $this->makeTester();

        $exit = $tester->execute(['--slot' => 'two-minutes']);

        $this->assertSame(0, $exit);
        $this->assertSame([['ds' => '(server)', 'job' => 'hosting-sync']], $cmd->callLog);

        $hb = $this->readHeartbeat('two-minutes');
        $this->assertSame(1, $hb['jobsRun']);
        $this->assertSame(0, $hb['failedCount']);
    }

    public function testFailedServerJobIsReportedInHeartbeat(): void
    {
        [$cmd, $tester] = $this->makeTester();
        $cmd->setJobResults(['(server) hosting-sync' => ['exitCode' => 1, 'timedOut' => false, 'output' => 'boom']]);

        $exit = $tester->execute(['--slot' => 'two-minutes']);

        // Selhání jobu není infra chyba — exit SUCCESS, reportuje doctor.
        $this->assertSame(0, $exit);
        $hb = $this->readHeartbeat('two-minutes');
        $this->assertSame(1, $hb['failedCount']);
        $this->assertSame('(server)', $hb['failures'][0]['ds']);
        $this->assertSame('hosting-sync', $hb['failures'][0]['job']);
    }

    public function testMissingSlotFailsWithoutHeartbeat(): void
    {
        [, $tester] = $this->makeTester();

        $exit = $tester->execute([]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown or missing --slot', $tester->getDisplay());
        $this->assertSame([], glob($this->runDir . '/*.heartbeat') ?: []);
    }

    public function testUnknownSlotFailsAndListsValidSlots(): void
    {
        [, $tester] = $this->makeTester();

        $exit = $tester->execute(['--slot' => 'hourly']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('minute, two-minutes, five-minutes, daily, weekly', $tester->getDisplay());
    }

    public function testEmptyDsListSucceedsAndWritesHeartbeat(): void
    {
        [$cmd, $tester] = $this->makeTester();

        $exit = $tester->execute(['--slot' => 'minute']);

        $this->assertSame(0, $exit);
        $this->assertSame([], $cmd->callLog);
        $hb = $this->readHeartbeat('minute');
        $this->assertSame(0, $hb['dsCount']);
        $this->assertSame(0, $hb['failedCount']);
    }

    public function testSlotMapDispatchesExpectedJobsInSortedDsOrder(): void
    {
        $this->createDs('bbbb-bbbb-bbbb-bbbb');
        $this->createDs('aaaa-aaaa-aaaa-aaaa');

        [$cmd, $tester] = $this->makeTester();
        $exit = $tester->execute(['--slot' => 'minute']);

        // Per DS všechny joby slotu v deklarovaném pořadí, DS seřazené.
        $this->assertSame(0, $exit);
        $this->assertSame([
            ['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'mail-outbox-run'],
            ['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'mail-analysis-reap'],
            ['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'mail-preprocess --sweep'],
            ['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'mail-analyze --sweep'],
            ['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'sales-registration-run'],
            ['ds' => 'bbbb-bbbb-bbbb-bbbb', 'job' => 'mail-outbox-run'],
            ['ds' => 'bbbb-bbbb-bbbb-bbbb', 'job' => 'mail-analysis-reap'],
            ['ds' => 'bbbb-bbbb-bbbb-bbbb', 'job' => 'mail-preprocess --sweep'],
            ['ds' => 'bbbb-bbbb-bbbb-bbbb', 'job' => 'mail-analyze --sweep'],
            ['ds' => 'bbbb-bbbb-bbbb-bbbb', 'job' => 'sales-registration-run'],
        ], $cmd->callLog);
    }

    public function testFailedJobContinuesAndExitsSuccess(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');
        $this->createDs('bbbb-bbbb-bbbb-bbbb');

        [$cmd, $tester] = $this->makeTester();
        $cmd->setJobResults([
            'aaaa-aaaa-aaaa-aaaa mail-idempotency-prune' => ['exitCode' => 1, 'timedOut' => false, 'output' => 'boom'],
        ]);

        $exit = $tester->execute(['--slot' => 'daily']);

        $this->assertSame(0, $exit);
        // server job ds-state-check + 2 DS × (mail-idempotency-prune, vat-periods-ensure, work-orders-invoice-run)
        $this->assertCount(7, $cmd->callLog);

        $hb = $this->readHeartbeat('daily');
        $this->assertSame(1, $hb['failedCount']);
        $this->assertSame(7, $hb['jobsRun']);
        $this->assertSame('aaaa-aaaa-aaaa-aaaa', $hb['failures'][0]['ds']);
        $this->assertSame('mail-idempotency-prune', $hb['failures'][0]['job']);
        $this->assertSame(1, $hb['failures'][0]['exitCode']);

        $log = (string) file_get_contents($this->logPath);
        $this->assertStringContainsString('cron job failed', $log);
        $this->assertStringContainsString('boom', $log);
    }

    public function testLockHeldSkipsRunAndKeepsHeartbeat(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');

        $heartbeatPath = CronProvisioner::heartbeatPath('minute', $this->runDir);
        file_put_contents($heartbeatPath, '{"old":true}');

        $lock = fopen(CronProvisioner::lockPath('minute', $this->runDir), 'c');
        $this->assertNotFalse($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        [$cmd, $tester] = $this->makeTester();
        $exit = $tester->execute(['--slot' => 'minute']);

        flock($lock, LOCK_UN);
        fclose($lock);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Previous run still active', $tester->getDisplay());
        $this->assertSame([], $cmd->callLog);
        $this->assertSame('{"old":true}', file_get_contents($heartbeatPath));
    }

    public function testSkipsDirsWithoutConfigMainJson(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');
        mkdir($this->dsDir . '/lost+found', 0755, true);

        [$cmd, $tester] = $this->makeTester();
        $exit = $tester->execute(['--slot' => 'daily']);

        $this->assertSame(0, $exit);
        $this->assertSame([
            ['ds' => '(server)', 'job' => 'ds-state-check'],
            ['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'mail-idempotency-prune'],
            ['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'vat-periods-ensure'],
            ['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'work-orders-invoice-run'],
        ], $cmd->callLog);
        $this->assertSame(1, $this->readHeartbeat('daily')['dsCount']);
    }

    public function testMissingDataSourcesDirIsInfraFailure(): void
    {
        $cmd = new TestableCronCommand($this->tmpDir . '/nonexistent', $this->runDir, $this->logPath);
        $tester = new CommandTester($cmd);

        $exit = $tester->execute(['--slot' => 'minute']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Data sources directory not found', $tester->getDisplay());
    }

    public function testHeartbeatShape(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');

        [, $tester] = $this->makeTester();
        $tester->execute(['--slot' => 'five-minutes']);

        $hb = $this->readHeartbeat('five-minutes');
        $this->assertSame('five-minutes', $hb['slot']);
        $this->assertSame(CronProvisioner::TEMPLATE_VERSION, $hb['templateVersion']);
        $this->assertNotFalse(strtotime($hb['ts']));
        $this->assertIsString($hb['appVersion']);
        $this->assertIsInt($hb['durationMs']);
        $this->assertSame([], $hb['failures']);
        $this->assertSame(0, $hb['skippedDataSources']);
        $this->assertSame(0, $hb['cronDisabledDataSources']);
        $this->assertSame(0, $hb['corruptedStateFiles']);
    }

    // ── Gating podle config/state.json ──────────────────────────────────────

    public function testSuspendedDsGetsNoJobsAndIsCountedAsSkipped(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa', '{"version":1,"state":"suspended"}');
        $this->createDs('bbbb-bbbb-bbbb-bbbb');

        [$cmd, $tester] = $this->makeTester();
        $exit = $tester->execute(['--slot' => 'minute']);

        $this->assertSame(0, $exit);
        $this->assertSame([
            ['ds' => 'bbbb-bbbb-bbbb-bbbb', 'job' => 'mail-outbox-run'],
            ['ds' => 'bbbb-bbbb-bbbb-bbbb', 'job' => 'mail-analysis-reap'],
            ['ds' => 'bbbb-bbbb-bbbb-bbbb', 'job' => 'mail-preprocess --sweep'],
            ['ds' => 'bbbb-bbbb-bbbb-bbbb', 'job' => 'mail-analyze --sweep'],
            ['ds' => 'bbbb-bbbb-bbbb-bbbb', 'job' => 'sales-registration-run'],
        ], $cmd->callLog);
        $hb = $this->readHeartbeat('minute');
        $this->assertSame(2, $hb['dsCount']);
        $this->assertSame(1, $hb['skippedDataSources']);
        $this->assertSame(count(self::SLOT_JOBS['minute']), $hb['jobsRun']);
        $this->assertStringContainsString('1 skipped by state', $tester->getDisplay());
    }

    public function testMaintenanceOverActiveSkipsEverything(): void
    {
        $this->createDs(
            'aaaa-aaaa-aaaa-aaaa',
            '{"version":1,"state":"active","maintenance":{"reason":"import","since":"2026-09-01T10:00:00Z"}}',
        );

        foreach (['minute', 'five-minutes', 'daily', 'weekly'] as $slot) {
            [$cmd, $tester] = $this->makeTester();
            $tester->execute(['--slot' => $slot]);
            // Server-level joby (daily ds-state-check) stavem DS neřídí — právě
            // zavřené DS hlídají; per-DS joby musí být prázdné.
            $perDs = array_values(array_filter($cmd->callLog, static fn($c) => $c['ds'] !== '(server)'));
            $this->assertSame([], $perDs, $slot);
            $this->assertSame(1, $this->readHeartbeat($slot)['skippedDataSources'], $slot);
        }
    }

    public function testPendingDeletionSkipsEverything(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa', '{"version":1,"state":"pending_deletion","deleteAfter":"2026-10-01T00:00:00Z"}');

        [$cmd, $tester] = $this->makeTester();
        $tester->execute(['--slot' => 'weekly']);
        $this->assertSame([], $cmd->callLog);
    }

    public function testReadOnlyRunsOnlyPruneJobs(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa', '{"version":1,"state":"read_only"}');

        [$cmd, $tester] = $this->makeTester();
        $tester->execute(['--slot' => 'minute']);
        $this->assertSame([], $cmd->callLog, 'minute slot has no read_only jobs');
        $this->assertSame(1, $this->readHeartbeat('minute')['skippedDataSources']);

        [$cmd, $tester] = $this->makeTester();
        $tester->execute(['--slot' => 'five-minutes']);
        // Výzvy zařízení vznikají i v režimu jen pro čtení (#177 U20) —
        // jejich úklid je tu jediná úloha; alerts-run a ostatní jen active.
        $this->assertSame(
            [['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'device-challenges-prune']],
            $cmd->callLog,
        );

        [$cmd, $tester] = $this->makeTester();
        $tester->execute(['--slot' => 'daily']);
        $this->assertSame([
            ['ds' => '(server)', 'job' => 'ds-state-check'],
            ['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'mail-idempotency-prune'],
        ], $cmd->callLog);
        $this->assertSame(0, $this->readHeartbeat('daily')['skippedDataSources']);

        [$cmd, $tester] = $this->makeTester();
        $tester->execute(['--slot' => 'weekly']);
        $this->assertSame([['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'alerts-prune']], $cmd->callLog);
    }

    public function testCorruptedStateFileFailsClosedAndIsCounted(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa', '{broken');

        [$cmd, $tester] = $this->makeTester();
        $exit = $tester->execute(['--slot' => 'minute']);

        $this->assertSame(0, $exit);
        $this->assertSame([], $cmd->callLog);
        $hb = $this->readHeartbeat('minute');
        $this->assertSame(1, $hb['skippedDataSources']);
        $this->assertSame(1, $hb['corruptedStateFiles']);
        $this->assertStringContainsString('fail-closed', (string) file_get_contents($this->logPath));
    }

    public function testServerJobsIgnoreDsState(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa', '{"version":1,"state":"suspended"}');

        [$cmd, $tester] = $this->makeTester();
        $tester->execute(['--slot' => 'two-minutes']);
        $this->assertSame([['ds' => '(server)', 'job' => 'hosting-sync']], $cmd->callLog);
    }

    public function testJobsForStateFollowsDeclaredStates(): void
    {
        $activeOnly = new CronJob('x', 'core.mail', 'daily', 500, ['active']);
        $cleanup = new CronJob('y', 'core.mail', 'daily', 500, ['active', 'read_only']);

        $this->assertSame([$activeOnly, $cleanup], CronCommand::jobsForState([$activeOnly, $cleanup], 'active'));
        $this->assertSame([$cleanup], CronCommand::jobsForState([$activeOnly, $cleanup], 'read_only'));
        $this->assertSame([], CronCommand::jobsForState([$activeOnly, $cleanup], 'suspended'));
        $this->assertSame([], CronCommand::jobsForState([$activeOnly, $cleanup], 'pending_deletion'));
    }

    public function testUnloadableModuleDefinitionsAreAnInfraError(): void
    {
        // Bez definic modulů dispatcher neví, co spouštět — FAILURE, ne
        // tichý prázdný slot; serverové joby ani heartbeat.
        $this->createDs('aaaa-aaaa-aaaa-aaaa');
        $modules = new class extends DataSourceModules {
            protected function loadDefinitions(): array
            {
                throw new \RuntimeException('broken module.jsonc');
            }
        };

        foreach ([['--slot' => 'daily'], ['--slot' => 'daily', '--ds' => 'aaaa-aaaa-aaaa-aaaa']] as $input) {
            [$cmd, $tester] = $this->makeTester($modules);
            $exit = $tester->execute($input);

            $this->assertSame(1, $exit);
            $this->assertSame([], $cmd->callLog);
            $this->assertStringContainsString('Cannot load module definitions', $tester->getDisplay());
        }
        $this->assertFileDoesNotExist(CronProvisioner::heartbeatPath('daily', $this->runDir));
        $this->assertStringContainsString('cron: module definitions cannot be loaded', (string) file_get_contents($this->logPath));
    }

    // ── Přepínač periodických úloh (#115 D1) ────────────────────────────────

    public function testCronOffDsGetsNoJobsAndIsCountedSeparately(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa', '{"version":1,"state":"active","cron":false}');
        $this->createDs('bbbb-bbbb-bbbb-bbbb');

        foreach (['minute', 'daily', 'weekly'] as $slot) {
            [$cmd, $tester] = $this->makeTester();
            $exit = $tester->execute(['--slot' => $slot]);

            $this->assertSame(0, $exit, $slot);
            // Ani úklidové joby (daily/weekly) — DS je přeskočený celý.
            $forA = array_values(array_filter($cmd->callLog, static fn($c) => $c['ds'] === 'aaaa-aaaa-aaaa-aaaa'));
            $this->assertSame([], $forA, $slot);
            $forB = array_values(array_filter($cmd->callLog, static fn($c) => $c['ds'] === 'bbbb-bbbb-bbbb-bbbb'));
            $this->assertCount(count(self::SLOT_JOBS[$slot]), $forB, $slot);

            $hb = $this->readHeartbeat($slot);
            $this->assertSame(2, $hb['dsCount'], $slot);
            $this->assertSame(1, $hb['cronDisabledDataSources'], $slot);
            $this->assertSame(0, $hb['skippedDataSources'], $slot);
            $this->assertStringContainsString('1 cron off', $tester->getDisplay(), $slot);
        }
        $this->assertStringContainsString('"cronDisabledDataSources":1', (string) file_get_contents($this->logPath));
    }

    public function testCronOffWithMaintenanceCountsOnlyAsCronOff(): void
    {
        $this->createDs(
            'aaaa-aaaa-aaaa-aaaa',
            '{"version":1,"state":"active","cron":false,"maintenance":{"reason":"import","since":"2026-09-01T10:00:00Z"}}',
        );

        [$cmd, $tester] = $this->makeTester();
        $tester->execute(['--slot' => 'minute']);

        $this->assertSame([], $cmd->callLog);
        $hb = $this->readHeartbeat('minute');
        $this->assertSame(1, $hb['cronDisabledDataSources']);
        $this->assertSame(0, $hb['skippedDataSources']);
    }

    public function testCronOffDoesNotStopServerJobs(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa', '{"version":1,"state":"active","cron":false}');

        [$cmd, $tester] = $this->makeTester();
        $tester->execute(['--slot' => 'daily']);
        $this->assertSame([['ds' => '(server)', 'job' => 'ds-state-check']], $cmd->callLog);
    }

    // ── Aktivní moduly (#115 D15) ───────────────────────────────────────────

    public function testDsWithoutJobModuleSkipsJobSilently(): void
    {
        // Jako testovací DS hostingu: core.alerts ano, core.mail / vat / zakázky ne.
        $this->createDs('aaaa-aaaa-aaaa-aaaa', null, ['install.mailless']);

        [$cmd, $tester] = $this->makeTester($this->makeModules());
        $exit = $tester->execute(['--slot' => 'minute']);
        $this->assertSame(0, $exit);
        $this->assertSame([], $cmd->callLog);
        $hb = $this->readHeartbeat('minute');
        // Vyřazená úloha není běh, selhání ani skip stavem.
        $this->assertSame(0, $hb['jobsRun']);
        $this->assertSame(0, $hb['failedCount']);
        $this->assertSame([], $hb['failures']);
        $this->assertSame(0, $hb['skippedDataSources']);
        $this->assertSame(1, $hb['dsCount']);
        $this->assertStringContainsString('0 skipped by state, 0 cron off, 0 job(s) run, 0 failed', $tester->getDisplay());

        [$cmd, $tester] = $this->makeTester($this->makeModules());
        $tester->execute(['--slot' => 'five-minutes']);
        $this->assertSame([['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'alerts-run']], $cmd->callLog);

        [$cmd, $tester] = $this->makeTester($this->makeModules());
        $tester->execute(['--slot' => 'daily']);
        $this->assertSame([['ds' => '(server)', 'job' => 'ds-state-check']], $cmd->callLog);
        $this->assertSame(1, $this->readHeartbeat('daily')['jobsRun']);

        [$cmd, $tester] = $this->makeTester($this->makeModules());
        $tester->execute(['--slot' => 'weekly']);
        $this->assertSame([['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'alerts-prune']], $cmd->callLog);

        $this->assertStringNotContainsString('active modules unknown', (string) file_get_contents($this->logPath));
    }

    public function testDsWithAllJobModulesRunsEverything(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa', null, ['install.full']);

        foreach (['minute', 'five-minutes', 'daily', 'weekly'] as $slot) {
            [$cmd, $tester] = $this->makeTester($this->makeModules());
            $tester->execute(['--slot' => $slot]);
            $perDs = array_values(array_filter($cmd->callLog, static fn($c) => $c['ds'] !== '(server)'));
            $this->assertSame(
                array_map(static fn(string $job) => ['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => $job], self::SLOT_JOBS[$slot]),
                $perDs,
                $slot,
            );
        }
    }

    public function testUnknownModulesRunAllJobsAsBefore(): void
    {
        // Nečitelný main.json i main.json bez modulů: chyba se má ukázat jako
        // selhaná úloha, ne schovat tichým vynecháním.
        $this->createDs('aaaa-aaaa-aaaa-aaaa');                 // `{}` — bez povinných polí
        $broken = $this->createDs('bbbb-bbbb-bbbb-bbbb');
        file_put_contents($broken . '/config/main.json', '{broken');
        $this->createDs('cccc-cccc-cccc-cccc', null, []);       // modules: []

        [$cmd, $tester] = $this->makeTester($this->makeModules());
        $exit = $tester->execute(['--slot' => 'minute']);

        $this->assertSame(0, $exit);
        $this->assertCount(3 * count(self::SLOT_JOBS['minute']), $cmd->callLog);
        $this->assertSame(3 * count(self::SLOT_JOBS['minute']), $this->readHeartbeat('minute')['jobsRun']);
        $log = (string) file_get_contents($this->logPath);
        $this->assertSame(3, substr_count($log, 'active modules unknown'));
    }

    public function testModuleDefinitionsLoadOncePerRun(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa', null, ['install.mailless']);
        $this->createDs('bbbb-bbbb-bbbb-bbbb', null, ['install.full']);
        $this->createDs('cccc-cccc-cccc-cccc', null, ['install.full']);

        $modules = $this->makeModules();
        [$cmd, $tester] = $this->makeTester($modules);
        $tester->execute(['--slot' => 'minute']);

        $this->assertSame(1, $modules->definitionLoads);
        $this->assertSame(3, $modules->queries);
        $this->assertCount(2 * count(self::SLOT_JOBS['minute']), $cmd->callLog);
    }

    public function testCronOffOrClosedDsDoesNotQueryModules(): void
    {
        // Pořadí kontrol: přepínač → stav → moduly. Zavřený DS se na moduly neptá.
        $this->createDs('aaaa-aaaa-aaaa-aaaa', '{"version":1,"state":"active","cron":false}', ['install.full']);
        $this->createDs('bbbb-bbbb-bbbb-bbbb', '{"version":1,"state":"suspended"}', ['install.full']);
        $this->createDs(
            'cccc-cccc-cccc-cccc',
            '{"version":1,"state":"active","maintenance":{"reason":"import","since":"2026-09-01T10:00:00Z"}}',
            ['install.full'],
        );
        // read_only: minute slot nemá joby → ani dotaz na moduly.
        $this->createDs('dddd-dddd-dddd-dddd', '{"version":1,"state":"read_only"}', ['install.full']);

        $modules = $this->makeModules();
        [$cmd, $tester] = $this->makeTester($modules);
        $tester->execute(['--slot' => 'minute']);

        $this->assertSame([], $cmd->callLog);
        $this->assertSame(0, $modules->queries, 'aktivní moduly zdroje dat se nezjišťují');
        $this->assertSame(1, $modules->definitionLoads, 'definice jen jednou — pro úlohy slotu');
        $hb = $this->readHeartbeat('minute');
        $this->assertSame(1, $hb['cronDisabledDataSources']);
        $this->assertSame(3, $hb['skippedDataSources']);
    }

    public function testDsOptionListsJobsSkippedByModule(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa', null, ['install.mailless']);

        [$cmd, $tester] = $this->makeTester($this->makeModules());
        $exit = $tester->execute(['--slot' => 'minute', '--ds' => 'aaaa-aaaa-aaaa-aaaa']);

        $this->assertSame(0, $exit);
        $this->assertSame([], $cmd->callLog);
        $display = $tester->getDisplay();
        foreach (self::SLOT_JOBS['minute'] as $job) {
            $module = str_starts_with($job, 'sales-registration') ? 'economy.salesRegistration' : 'core.mail';
            $this->assertStringContainsString("  · {$job}: skipped (module {$module} not active)", $display);
        }
        $this->assertFileDoesNotExist(CronProvisioner::heartbeatPath('minute', $this->runDir));

        // Smíšený slot: vyřazená úloha ve výpisu, zbylá běží; selhání zbylé = FAILURE.
        [$cmd, $tester] = $this->makeTester($this->makeModules());
        $cmd->setJobResults([
            'aaaa-aaaa-aaaa-aaaa alerts-prune' => ['exitCode' => 1, 'timedOut' => false, 'output' => 'boom'],
        ]);
        $exit = $tester->execute(['--slot' => 'weekly', '--ds' => 'aaaa-aaaa-aaaa-aaaa']);
        $this->assertSame(1, $exit);
        $this->assertSame([['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'alerts-prune']], $cmd->callLog);
        $this->assertStringContainsString('alerts-prune: failed, exit 1', $tester->getDisplay());

        [$cmd, $tester] = $this->makeTester($this->makeModules());
        $this->assertSame(0, $tester->execute(['--slot' => 'daily', '--ds' => 'aaaa-aaaa-aaaa-aaaa']));
        $this->assertSame([], $cmd->callLog);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('· mail-idempotency-prune: skipped (module core.mail not active)', $display);
        $this->assertStringContainsString('· vat-periods-ensure: skipped (module economy.vat not active)', $display);
        $this->assertStringContainsString('· work-orders-invoice-run: skipped (module economy.workOrders not active)', $display);
    }

    public function testJobsForModulesKeepsJobsOfActiveModules(): void
    {
        $mail = new CronJob('mail-outbox-run', 'core.mail', 'minute', 500, ['active']);
        $alerts = new CronJob('alerts-run', 'core.alerts', 'five-minutes', 900, ['active']);
        $jobs = [$mail, $alerts];

        // null = moduly nelze určit → beze změny.
        $this->assertSame($jobs, CronCommand::jobsForModules($jobs, null));
        $this->assertSame([], CronCommand::jobsForModules($jobs, []));
        $this->assertSame([$alerts], CronCommand::jobsForModules($jobs, ['core.system', 'core.alerts']));
        $this->assertSame($jobs, CronCommand::jobsForModules($jobs, ['core.mail', 'core.alerts']));
    }

    // ── --ds=<id> (#115 D8) ──────────────────────────────────────────────────

    public function testDsOptionRunsOnlyThatDsAndIgnoresCronSwitch(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa', '{"version":1,"state":"active","cron":false}');
        $this->createDs('bbbb-bbbb-bbbb-bbbb');

        [$cmd, $tester] = $this->makeTester();
        $exit = $tester->execute(['--slot' => 'minute', '--ds' => 'aaaa-aaaa-aaaa-aaaa']);

        $this->assertSame(0, $exit);
        $this->assertSame([
            ['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'mail-outbox-run'],
            ['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'mail-analysis-reap'],
            ['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'mail-preprocess --sweep'],
            ['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'mail-analyze --sweep'],
            ['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'sales-registration-run'],
        ], $cmd->callLog);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('cron switch off (ignored by --ds)', $display);
        $this->assertStringContainsString('mail-outbox-run: ok (', $display);
        // Žádný heartbeat — ruční běh nesmí v doctoru zakrýt neběžící cron.
        $this->assertFileDoesNotExist(CronProvisioner::heartbeatPath('minute', $this->runDir));
        $this->assertStringContainsString('cron --slot=minute --ds=aaaa-aaaa-aaaa-aaaa', (string) file_get_contents($this->logPath));
    }

    public function testDsOptionRunsEvenWhenSlotLockIsHeld(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');
        $heartbeatPath = CronProvisioner::heartbeatPath('five-minutes', $this->runDir);
        file_put_contents($heartbeatPath, '{"old":true}');

        $lock = fopen(CronProvisioner::lockPath('five-minutes', $this->runDir), 'c');
        $this->assertNotFalse($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        [$cmd, $tester] = $this->makeTester();
        $exit = $tester->execute(['--slot' => 'five-minutes', '--ds' => 'aaaa-aaaa-aaaa-aaaa']);

        flock($lock, LOCK_UN);
        fclose($lock);

        $this->assertSame(0, $exit);
        $this->assertSame(
            array_map(static fn(string $job): array => ['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => $job], self::SLOT_JOBS['five-minutes']),
            $cmd->callLog,
        );
        $this->assertSame('{"old":true}', file_get_contents($heartbeatPath));
    }

    public function testDsOptionRespectsDataSourceState(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa', '{"version":1,"state":"suspended","cron":false}');
        [$cmd, $tester] = $this->makeTester();
        $exit = $tester->execute(['--slot' => 'minute', '--ds' => 'aaaa-aaaa-aaaa-aaaa']);
        $this->assertSame(0, $exit);
        $this->assertSame([], $cmd->callLog);
        $this->assertStringContainsString('state suspended', $tester->getDisplay());
        $this->assertStringContainsString('No jobs of this slot run in the current data source state', $tester->getDisplay());

        $this->createDs(
            'bbbb-bbbb-bbbb-bbbb',
            '{"version":1,"state":"active","maintenance":{"reason":"import","since":"2026-09-01T10:00:00Z"}}',
        );
        [$cmd, $tester] = $this->makeTester();
        $this->assertSame(0, $tester->execute(['--slot' => 'daily', '--ds' => 'bbbb-bbbb-bbbb-bbbb']));
        $this->assertSame([], $cmd->callLog);
        $this->assertStringContainsString('maintenance: import', $tester->getDisplay());

        // read_only: jen úklidové joby, server job ds-state-check neběží.
        $this->createDs('cccc-cccc-cccc-cccc', '{"version":1,"state":"read_only"}');
        [$cmd, $tester] = $this->makeTester();
        $this->assertSame(0, $tester->execute(['--slot' => 'daily', '--ds' => 'cccc-cccc-cccc-cccc']));
        $this->assertSame([['ds' => 'cccc-cccc-cccc-cccc', 'job' => 'mail-idempotency-prune']], $cmd->callLog);
    }

    public function testDsOptionSkipsServerJobs(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');
        [$cmd, $tester] = $this->makeTester();
        $tester->execute(['--slot' => 'daily', '--ds' => 'aaaa-aaaa-aaaa-aaaa']);
        $this->assertSame([
            ['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'mail-idempotency-prune'],
            ['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'vat-periods-ensure'],
            ['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'work-orders-invoice-run'],
        ], $cmd->callLog);

        [$cmd, $tester] = $this->makeTester();
        $tester->execute(['--slot' => 'two-minutes', '--ds' => 'aaaa-aaaa-aaaa-aaaa']);
        $this->assertSame([], $cmd->callLog);
        $this->assertFileDoesNotExist(CronProvisioner::heartbeatPath('two-minutes', $this->runDir));
    }

    public function testDsOptionFailsOnFailedJobAndShowsOutputTail(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');
        [$cmd, $tester] = $this->makeTester();
        $cmd->setJobResults([
            'aaaa-aaaa-aaaa-aaaa mail-analysis-reap' => ['exitCode' => 2, 'timedOut' => false, 'output' => "line one\nboom"],
            'aaaa-aaaa-aaaa-aaaa mail-analyze --sweep' => ['exitCode' => -1, 'timedOut' => true, 'output' => ''],
        ]);

        $exit = $tester->execute(['--slot' => 'minute', '--ds' => 'aaaa-aaaa-aaaa-aaaa']);

        $this->assertSame(1, $exit);
        // Selhání jedné úlohy nezastaví ostatní.
        $this->assertCount(count(self::SLOT_JOBS['minute']), $cmd->callLog);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('mail-analysis-reap: failed, exit 2', $display);
        $this->assertStringContainsString('    boom', $display);
        $this->assertStringContainsString('mail-analyze --sweep: timed out', $display);
        $log = (string) file_get_contents($this->logPath);
        $this->assertStringContainsString('cron job failed', $log);
        $this->assertStringContainsString('boom', $log);
        $this->assertFileDoesNotExist(CronProvisioner::heartbeatPath('minute', $this->runDir));
    }

    public function testDsOptionUnknownIdFails(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');
        [$cmd, $tester] = $this->makeTester();
        $exit = $tester->execute(['--slot' => 'minute', '--ds' => 'zzzz-zzzz-zzzz-zzzz']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown data source: zzzz-zzzz-zzzz-zzzz', $tester->getDisplay());
        $this->assertSame([], $cmd->callLog);
    }

    public function testDsOptionInvalidIdFormatFails(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');
        foreach (['abcd', '../aaaa-aaaa-aaaa-aaaa', 'AAAA-AAAA-AAAA-AAAA', 'aaaa-aaaa-aaaa-aaaa/'] as $bad) {
            [$cmd, $tester] = $this->makeTester();
            $exit = $tester->execute(['--slot' => 'minute', '--ds' => $bad]);
            $this->assertSame(1, $exit, $bad);
            $this->assertStringContainsString('Invalid --ds', $tester->getDisplay(), $bad);
            $this->assertSame([], $cmd->callLog, $bad);
        }
        $this->assertSame([], glob($this->runDir . '/*') ?: []);
    }

    public function testDsOptionEmptyValueFailsInsteadOfRunningWholeSlot(): void
    {
        // `--ds=` zadané s prázdnou hodnotou nesmí propadnout do běhu nad
        // všemi DS — na dev stroji by to zasáhlo kopie reálných dat.
        $this->createDs('aaaa-aaaa-aaaa-aaaa');
        $this->createDs('bbbb-bbbb-bbbb-bbbb');

        [$cmd, $tester] = $this->makeTester();
        $exit = $tester->execute(['--slot' => 'minute', '--ds' => '']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Invalid --ds: (empty)', $tester->getDisplay());
        $this->assertSame([], $cmd->callLog);
        $this->assertFileDoesNotExist(CronProvisioner::heartbeatPath('minute', $this->runDir));
        $this->assertSame([], glob($this->runDir . '/*') ?: []);
    }

    public function testDsOptionStillRequiresValidSlot(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa');
        [$cmd, $tester] = $this->makeTester();
        $this->assertSame(1, $tester->execute(['--slot' => 'hourly', '--ds' => 'aaaa-aaaa-aaaa-aaaa']));
        $this->assertSame([], $cmd->callLog);
    }

    public function testRealRunJobTimesOut(): void
    {
        $fixture = $this->tmpDir . '/slow-shpd-ds';
        file_put_contents($fixture, "#!/bin/sh\nsleep 5\n");
        chmod($fixture, 0755);

        $cmd = new TimeoutCronCommand($fixture);
        $started = microtime(true);
        $result = $cmd->runJobPublic($this->tmpDir, 'mail-outbox-run');
        $elapsed = microtime(true) - $started;

        $this->assertTrue($result['timedOut']);
        $this->assertLessThan(4.0, $elapsed);
    }

    public function testRealRunJobCapturesExitCodeAndOutput(): void
    {
        $fixture = $this->tmpDir . '/failing-shpd-ds';
        file_put_contents($fixture, "#!/bin/sh\necho 'some output'\nexit 3\n");
        chmod($fixture, 0755);

        $cmd = new TimeoutCronCommand($fixture);
        $result = $cmd->runJobPublic($this->tmpDir, 'alerts-run');

        $this->assertFalse($result['timedOut']);
        $this->assertSame(3, $result['exitCode']);
        $this->assertStringContainsString('some output', $result['output']);
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
