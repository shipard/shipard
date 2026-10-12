<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\CronCommand;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Server\CronProvisioner;
use Symfony\Component\Console\Tester\CommandTester;

class TestableCronCommand extends CronCommand
{
    /** @var array<string, array{exitCode: int, timedOut: bool, output: string}> klíč "dsId job" */
    private array $jobResults = [];

    /** @var list<array{ds: string, job: string}> */
    public array $callLog = [];

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

    private function createDs(string $id, ?string $stateJson = null): string
    {
        $dir = $this->dsDir . '/' . $id;
        mkdir($dir . '/config', 0755, true);
        file_put_contents($dir . '/config/main.json', '{}');
        if ($stateJson !== null) {
            file_put_contents($dir . '/config/state.json', $stateJson);
        }
        return $dir;
    }

    /** @return array{TestableCronCommand, CommandTester} */
    private function makeTester(): array
    {
        $cmd = new TestableCronCommand($this->dsDir, $this->runDir, $this->logPath);
        return [$cmd, new CommandTester($cmd)];
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
            ['ds' => 'bbbb-bbbb-bbbb-bbbb', 'job' => 'mail-outbox-run'],
            ['ds' => 'bbbb-bbbb-bbbb-bbbb', 'job' => 'mail-analysis-reap'],
            ['ds' => 'bbbb-bbbb-bbbb-bbbb', 'job' => 'mail-preprocess --sweep'],
            ['ds' => 'bbbb-bbbb-bbbb-bbbb', 'job' => 'mail-analyze --sweep'],
        ], $cmd->callLog);
    }

    public function testSlotJobsMapping(): void
    {
        $this->assertSame(['mail-outbox-run', 'mail-analysis-reap', 'mail-preprocess --sweep', 'mail-analyze --sweep'], CronCommand::SLOT_JOBS['minute']);
        $this->assertSame(['alerts-run'], CronCommand::SLOT_JOBS['five-minutes']);
        $this->assertSame(['mail-idempotency-prune', 'vat-periods-ensure', 'work-orders-invoice-run'], CronCommand::SLOT_JOBS['daily']);
        $this->assertSame(['alerts-prune'], CronCommand::SLOT_JOBS['weekly']);
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
        ], $cmd->callLog);
        $hb = $this->readHeartbeat('minute');
        $this->assertSame(2, $hb['dsCount']);
        $this->assertSame(1, $hb['skippedDataSources']);
        $this->assertSame(4, $hb['jobsRun']);
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
        $this->assertSame([], $cmd->callLog, 'alerts-run is active-only');

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

    public function testJobAllowedStatesCoverAllSlotJobs(): void
    {
        foreach (CronCommand::SLOT_JOBS as $slot => $jobs) {
            foreach ($jobs as $job) {
                $this->assertArrayHasKey($job, CronCommand::JOB_ALLOWED_STATES, "{$slot}: {$job}");
            }
        }
        // Neznámý job = jen active (fail-closed).
        $this->assertSame(['x'], CronCommand::jobsForState(['x'], 'active'));
        $this->assertSame([], CronCommand::jobsForState(['x'], 'read_only'));
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
            $this->assertCount(count(CronCommand::SLOT_JOBS[$slot]), $forB, $slot);

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
        $this->assertSame([['ds' => 'aaaa-aaaa-aaaa-aaaa', 'job' => 'alerts-run']], $cmd->callLog);
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
        $this->assertCount(4, $cmd->callLog);
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
