<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\DevJobRunCommand;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobState;
use Shipard\Core\DevJobs\JobStatus;
use Shipard\Core\DevJobs\JobStep;
use Shipard\Core\DevJobs\JobStore;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDevJobRunCommand extends DevJobRunCommand
{
    public int $ticks = 0;
    public ?int $cancelOnTick = null;
    public float $grace = 2.0;

    public function __construct(private readonly string $jobsDir)
    {
        parent::__construct();
    }

    protected function store(string $jobsDir): JobStore
    {
        return new JobStore($this->jobsDir);
    }

    protected function tick(): void
    {
        $this->ticks++;
        if ($this->cancelOnTick !== null && $this->ticks === $this->cancelOnTick) {
            // CommandTester signály neregistruje — simulace SIGTERM přímo.
            $this->handleSignal(15);
        }
    }

    protected function graceSeconds(JobRecord $record): float
    {
        return $this->grace;
    }

    protected function pollMicroseconds(): int
    {
        return 20_000;
    }
}

/**
 * Obal úlohy: exit kódy kroků → stav, první selhání přeskočí zbytek,
 * `captureStdout` → result.json, zrušení SIGTERM a po grace SIGKILL,
 * PID a zámek živosti.
 */
final class DevJobRunCommandTest extends TestCase
{
    private string $jobsDir;
    private JobStore $store;

    protected function setUp(): void
    {
        if (trim((string) shell_exec('command -v sh 2>/dev/null')) === '') {
            $this->markTestSkipped('sh is not available');
        }
        $this->jobsDir = sys_get_temp_dir() . '/shpd_wrapper_' . bin2hex(random_bytes(6));
        mkdir($this->jobsDir, 0700, true);
        $this->store = new JobStore($this->jobsDir);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->jobsDir);
    }

    /** @param list<JobStep> $steps */
    private function createJob(array $steps, string $id = '20261016-120000-aaaaaa'): string
    {
        $record = new JobRecord($id, JobRecord::TYPE_VERIFY, 'aaaa-bbbb-cccc-dddd', 'DS', 'cz-vat-payer', null, [], $steps, '2026-10-16T12:00:00Z', null, 60);
        $this->store->create($record);
        return $this->store->jobDir($id);
    }

    private function runJob(TestableDevJobRunCommand $cmd, string $jobDir): int
    {
        return (new CommandTester($cmd))->execute(['jobDir' => $jobDir]);
    }

    public function testFinishedWhenAllStepsExitZero(): void
    {
        $jobDir = $this->createJob([
            new JobStep('first', ['true'], $this->jobsDir),
            new JobStep('second', ['sh', '-c', 'echo hi; echo err >&2'], $this->jobsDir),
        ]);
        $cmd = new TestableDevJobRunCommand($this->jobsDir);

        $exit = $this->runJob($cmd, $jobDir);

        $this->assertSame(0, $exit);
        $state = $this->store->readState('20261016-120000-aaaaaa');
        $this->assertSame(JobStatus::FINISHED, $state?->status);
        $this->assertSame(getmypid(), $state->pid);
        $this->assertSame([JobState::STEP_FINISHED, JobState::STEP_FINISHED], array_column($state->steps, 'status'));
        $this->assertSame([0, 0], array_column($state->steps, 'exitCode'));
        $this->assertNotNull($state->finishedAt);
        $log = (string) file_get_contents($jobDir . '/output.log');
        $this->assertStringContainsString('[STEP]', $log);
        $this->assertStringContainsString('first', $log);
        $this->assertStringContainsString("hi\n", $log);
        $this->assertStringContainsString("err\n", $log);
        $this->assertStringContainsString('[END]', $log);
        $this->assertFalse($this->store->isWrapperAlive('20261016-120000-aaaaaa'), 'lock released after the run');
        $this->assertSame(JobStatus::FINISHED, $this->store->viewOf('20261016-120000-aaaaaa')['status']);
    }

    public function testFailedStepSkipsTheRest(): void
    {
        $jobDir = $this->createJob([
            new JobStep('fails', ['false'], $this->jobsDir),
            new JobStep('never', ['true'], $this->jobsDir),
        ]);

        $exit = $this->runJob(new TestableDevJobRunCommand($this->jobsDir), $jobDir);

        $this->assertSame(1, $exit);
        $state = $this->store->readState('20261016-120000-aaaaaa');
        $this->assertSame(JobStatus::FAILED, $state?->status);
        $this->assertSame([JobState::STEP_FAILED, JobState::STEP_SKIPPED], array_column($state->steps, 'status'));
        $this->assertSame(1, $state->steps[0]['exitCode']);
        $this->assertNull($state->steps[1]['exitCode']);
        $this->assertStringContainsString('[ERROR] step "fails" failed (exit 1)', (string) file_get_contents($jobDir . '/output.log'));
    }

    public function testCaptureStdoutGoesToResultJson(): void
    {
        $jobDir = $this->createJob([
            new JobStep('json', ['sh', '-c', 'echo \'{"ok":true,"n":2}\'; echo warn >&2'], $this->jobsDir, true),
        ]);

        $exit = $this->runJob(new TestableDevJobRunCommand($this->jobsDir), $jobDir);

        $this->assertSame(0, $exit);
        $this->assertSame(['ok' => true, 'n' => 2], $this->store->result('20261016-120000-aaaaaa'));
        $log = (string) file_get_contents($jobDir . '/output.log');
        $this->assertStringContainsString("warn\n", $log);
        $this->assertStringNotContainsString('"ok"', $log);
    }

    public function testCancelTerminatesTheStepAndSkipsTheRest(): void
    {
        $jobDir = $this->createJob([
            new JobStep('long', ['sleep', '5'], $this->jobsDir),
            new JobStep('never', ['true'], $this->jobsDir),
        ]);
        $cmd = new TestableDevJobRunCommand($this->jobsDir);
        $cmd->cancelOnTick = 3;

        $started = microtime(true);
        $exit = $this->runJob($cmd, $jobDir);
        $elapsed = microtime(true) - $started;

        $this->assertSame(DevJobRunCommand::EXIT_CANCELLED, $exit);
        $this->assertLessThan(3.0, $elapsed);
        $state = $this->store->readState('20261016-120000-aaaaaa');
        $this->assertSame(JobStatus::CANCELLED, $state?->status);
        $this->assertSame([JobState::STEP_CANCELLED, JobState::STEP_SKIPPED], array_column($state->steps, 'status'));
        $this->assertSame(15, $state->steps[0]['termSignal']);
        $this->assertNull($state->steps[0]['exitCode']);
        $this->assertNotNull($state->cancelRequestedAt);
        $this->assertSame(JobStatus::CANCELLED, $this->store->viewOf('20261016-120000-aaaaaa')['status']);
    }

    public function testKillAfterGraceWhenStepIgnoresSigterm(): void
    {
        $jobDir = $this->createJob([
            new JobStep('stubborn', ['sh', '-c', 'trap "" TERM; sleep 5'], $this->jobsDir),
        ]);
        $cmd = new TestableDevJobRunCommand($this->jobsDir);
        $cmd->cancelOnTick = 2;
        $cmd->grace = 0.3;

        $started = microtime(true);
        $exit = $this->runJob($cmd, $jobDir);
        $elapsed = microtime(true) - $started;

        $this->assertSame(DevJobRunCommand::EXIT_CANCELLED, $exit);
        $this->assertLessThan(3.0, $elapsed);
        $state = $this->store->readState('20261016-120000-aaaaaa');
        $this->assertSame(9, $state?->steps[0]['termSignal']);
        $this->assertSame(JobState::STEP_CANCELLED, $state->steps[0]['status']);
    }

    public function testInvalidJobDirIsRefused(): void
    {
        $cmd = new TestableDevJobRunCommand($this->jobsDir);

        $this->assertSame(2, (new CommandTester($cmd))->execute(['jobDir' => $this->jobsDir . '/not-an-id']));
        mkdir($this->jobsDir . '/20261016-120000-bbbbbb');
        $this->assertSame(2, (new CommandTester($cmd))->execute(['jobDir' => $this->jobsDir . '/20261016-120000-bbbbbb']), 'no job.json');
    }

    public function testHiddenAndSignalContract(): void
    {
        $cmd = new DevJobRunCommand();

        $this->assertTrue($cmd->isHidden());
        $this->assertSame(\defined('SIGTERM') ? [\SIGTERM, \SIGINT] : [], $cmd->getSubscribedSignals());
        $this->assertFalse($cmd->handleSignal(15), 'false = Symfony does not exit; the wrapper finishes the state file');
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
