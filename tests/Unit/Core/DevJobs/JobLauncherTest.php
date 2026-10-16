<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\DevJobs;

use PHPUnit\Framework\TestCase;
use Shipard\Core\DevJobs\JobConflict;
use Shipard\Core\DevJobs\JobLauncher;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobState;
use Shipard\Core\DevJobs\JobStatus;
use Shipard\Core\DevJobs\JobStep;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Spuštění a zrušení úlohy (D58): argv obalu, jedna aktivní úloha na zdroj
 * dat (409), mrtvá předchozí neblokuje, selhaný spawn = failed + varování
 * v logu, zrušení = signál skupině (−pid), force = SIGKILL a dopsaný stav.
 */
final class JobLauncherTest extends TestCase
{
    private string $dir;
    private string $logFile;
    private \DateTimeImmutable $now;
    private bool $alive = true;
    private bool $spawnResult = true;
    /** @var list<array{argv: list<string>, cwd: string, log: string}> */
    private array $spawns = [];
    /** @var list<array{0: int, 1: int}> */
    private array $kills = [];
    private JobStore $store;
    private JobLauncher $launcher;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/shpd_launch_' . bin2hex(random_bytes(6));
        $this->dir = $base . '/dev-jobs';
        $this->logFile = $base . '/test.log';
        mkdir($base, 0700, true);
        ErrorLogger::setLogPath($this->logFile);
        $this->now = new \DateTimeImmutable('2026-10-16T12:00:00Z');
        $this->store = new JobStore($this->dir, fn (): bool => $this->alive, fn (): \DateTimeImmutable => $this->now);
        $this->launcher = new JobLauncher(
            $this->store,
            function (array $argv, string $cwd, string $log): bool {
                $this->spawns[] = ['argv' => $argv, 'cwd' => $cwd, 'log' => $log];
                return $this->spawnResult;
            },
            function (int $pid, int $signal): bool {
                $this->kills[] = [$pid, $signal];
                if ($signal === JobLauncher::SIGKILL) {
                    $this->alive = false;
                }
                return true;
            },
            ['/usr/bin/php', '/repo/bin/shpd-server'],
        );
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        $this->rrmdir(dirname($this->dir));
    }

    private function record(string $id, string $ds = 'aaaa-bbbb-cccc-dddd'): JobRecord
    {
        return new JobRecord($id, JobRecord::TYPE_RESEED, $ds, 'DS', 'cz-vat-payer', null, [], [new JobStep('seed', ['true'], '/tmp')], JobStore::formatTime($this->now), '10.0.0.5', 60);
    }

    public function testLaunchWritesJobAndSpawnsWrapper(): void
    {
        $view = $this->launcher->launch($this->record('20261016-120000-aaaaaa'));
        $jobDir = $this->dir . '/20261016-120000-aaaaaa';

        $this->assertSame(JobStatus::STARTING, $view['status']);
        $this->assertTrue($view['active']);
        $this->assertFileExists($jobDir . '/job.json');
        $this->assertSame('10.0.0.5', json_decode((string) file_get_contents($jobDir . '/job.json'), true)['clientIp']);
        $this->assertFileExists($this->dir . '/.lock');
        $this->assertCount(1, $this->spawns);
        $this->assertSame(['/usr/bin/php', '/repo/bin/shpd-server', 'dev-job-run', $jobDir], $this->spawns[0]['argv']);
        $this->assertSame($jobDir, $this->spawns[0]['cwd']);
        $this->assertSame($jobDir . '/output.log', $this->spawns[0]['log']);
    }

    public function testSecondJobOnSameDataSourceConflicts(): void
    {
        $this->launcher->launch($this->record('20261016-120000-aaaaaa'));

        try {
            $this->launcher->launch($this->record('20261016-120001-bbbbbb'));
            $this->fail('expected JobConflict');
        } catch (JobConflict $e) {
            $this->assertSame('20261016-120000-aaaaaa', $e->runningJobId);
            $this->assertSame('aaaa-bbbb-cccc-dddd', $e->dataSourceId);
        }
        $this->assertDirectoryDoesNotExist($this->dir . '/20261016-120001-bbbbbb');
        $this->assertCount(1, $this->spawns);

        $other = $this->launcher->launch($this->record('20261016-120002-cccccc', 'eeee-ffff-gggg-hhhh'));
        $this->assertSame(JobStatus::STARTING, $other['status']);
    }

    public function testDeadPreviousJobDoesNotBlock(): void
    {
        $this->launcher->launch($this->record('20261016-120000-aaaaaa'));
        $state = JobState::pending(1);
        $state->pid = 1;
        $this->store->writeState('20261016-120000-aaaaaa', $state);
        $this->alive = false;

        $view = $this->launcher->launch($this->record('20261016-120001-bbbbbb'));

        $this->assertSame(JobStatus::STARTING, $view['status']);
        $this->assertCount(2, $this->spawns);
    }

    public function testFailedSpawnIsRecordedAsFailed(): void
    {
        $this->spawnResult = false;

        $view = $this->launcher->launch($this->record('20261016-120000-aaaaaa'));

        $this->assertSame(JobStatus::FAILED, $view['status']);
        $this->assertStringContainsString('spawn failed', (string) $view['error']);
        $this->assertSame(JobState::STEP_SKIPPED, $view['steps'][0]['status']);
        $this->assertFileExists($this->dir . '/20261016-120000-aaaaaa/state.json');
        $this->assertStringContainsString('cannot spawn the job wrapper', (string) file_get_contents($this->logFile));
        // Selhaná úloha neblokuje další spuštění na tomtéž zdroji dat.
        $this->spawnResult = true;
        $this->assertSame(JobStatus::STARTING, $this->launcher->launch($this->record('20261016-120001-bbbbbb'))['status']);
    }

    public function testCancelSignalsTheProcessGroup(): void
    {
        $this->launcher->launch($this->record('20261016-120000-aaaaaa'));
        $state = JobState::pending(1);
        $state->pid = 4242;
        $state->steps[0]['status'] = JobState::STEP_RUNNING;
        $this->store->writeState('20261016-120000-aaaaaa', $state);

        $view = $this->launcher->cancel('20261016-120000-aaaaaa');

        $this->assertSame([[-4242, JobLauncher::SIGTERM]], $this->kills);
        $this->assertSame(JobStatus::CANCELLING, $view['status'] ?? null);
        $this->assertFileExists($this->dir . '/20261016-120000-aaaaaa/cancel.json');
        // state.json patří obalu — API ho při měkkém zrušení nemění.
        $this->assertSame(JobStatus::RUNNING, $this->store->readState('20261016-120000-aaaaaa')?->status);
    }

    public function testForceCancelKillsAndClosesState(): void
    {
        $this->launcher->launch($this->record('20261016-120000-aaaaaa'));
        $state = JobState::pending(1);
        $state->pid = 4242;
        $state->steps[0]['status'] = JobState::STEP_RUNNING;
        $this->store->writeState('20261016-120000-aaaaaa', $state);

        $view = $this->launcher->cancel('20261016-120000-aaaaaa', true);

        $this->assertSame([[-4242, JobLauncher::SIGKILL]], $this->kills);
        $this->assertSame(JobStatus::CANCELLED, $view['status'] ?? null);
        $this->assertTrue($view['forced']);
        $this->assertSame(JobState::STEP_CANCELLED, $view['steps'][0]['status']);
        $this->assertSame('2026-10-16T12:00:00Z', $view['finishedAt']);
    }

    public function testCancelReturnsNullWhenNothingToCancel(): void
    {
        $this->assertNull($this->launcher->cancel('20261016-120000-zzzzzz'));

        $this->launcher->launch($this->record('20261016-120000-aaaaaa'));
        $this->assertNull($this->launcher->cancel('20261016-120000-aaaaaa'), 'no PID yet');

        $state = JobState::pending(1);
        $state->pid = 4242;
        $state->status = JobStatus::FINISHED;
        $this->store->writeState('20261016-120000-aaaaaa', $state);
        $this->assertNull($this->launcher->cancel('20261016-120000-aaaaaa'), 'finished');
        $this->assertSame([], $this->kills);
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
