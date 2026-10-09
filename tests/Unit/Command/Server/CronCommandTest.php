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
