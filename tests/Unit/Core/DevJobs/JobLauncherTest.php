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

    private function updateRecord(string $id): JobRecord
    {
        return new JobRecord($id, JobRecord::TYPE_UPDATE, '', '', null, null, [], [new JobStep('dev-update', ['true'], '/repo')], JobStore::formatTime($this->now), '10.0.0.5', 60);
    }

    public function testUpdateExcludesEveryOtherJobAndViceVersa(): void
    {
        // Běžící reseed na jednom zdroji dat → update (celý server) nejde spustit.
        $this->launcher->launch($this->record('20261016-120000-aaaaaa'));
        try {
            $this->launcher->launch($this->updateRecord('20261016-120001-bbbbbb'));
            $this->fail('expected JobConflict');
        } catch (JobConflict $e) {
            $this->assertSame('20261016-120000-aaaaaa', $e->runningJobId);
            $this->assertSame('aaaa-bbbb-cccc-dddd', $e->dataSourceId, 'the running job is on a data source');
            $this->assertStringContainsString('aaaa-bbbb-cccc-dddd', $e->getMessage());
        }
        $this->assertCount(1, $this->spawns);

        // Reseed doběhl → update projde a stojí v cestě všemu ostatnímu, i na jiném zdroji dat.
        $this->alive = false;
        $done = JobState::pending(1);
        $done->status = JobStatus::FINISHED;
        $this->store->writeState('20261016-120000-aaaaaa', $done);
        $this->alive = true;

        $view = $this->launcher->launch($this->updateRecord('20261016-120002-cccccc'));
        $this->assertSame(JobStatus::STARTING, $view['status']);
        $this->assertNull($view['dataSource']);
        try {
            $this->launcher->launch($this->record('20261016-120003-dddddd', 'eeee-ffff-gggg-hhhh'));
            $this->fail('expected JobConflict');
        } catch (JobConflict $e) {
            $this->assertSame('20261016-120002-cccccc', $e->runningJobId);
            $this->assertSame('', $e->dataSourceId, 'the running job is server-wide');
            $this->assertSame(JobRecord::TYPE_UPDATE, $e->runningType);
            $this->assertStringContainsString('server-wide job 20261016-120002-cccccc (update)', $e->getMessage());
        }
        try {
            $this->launcher->launch($this->updateRecord('20261016-120004-eeeeee'));
            $this->fail('expected JobConflict');
        } catch (JobConflict $e) {
            $this->assertSame('20261016-120002-cccccc', $e->runningJobId, 'a second update is refused too');
        }
        $this->assertCount(2, $this->spawns);
    }

    private function roundRecord(string $id): JobRecord
    {
        return new JobRecord($id, JobRecord::TYPE_ROUND, '', '', null, null, ['layers' => ['code']], [new JobStep('test-round-run', ['true'], '/repo')], JobStore::formatTime($this->now), '10.0.0.5', 960);
    }

    private function childRecord(string $id, string $parent, string $ds = 'aaaa-bbbb-cccc-dddd'): JobRecord
    {
        return new JobRecord($id, JobRecord::TYPE_RESEED, $ds, 'DS', 'cz-vat-payer', null, [], [new JobStep('seed', ['true'], '/tmp')], JobStore::formatTime($this->now), null, 60, $parent);
    }

    private function markRunning(string $id, int $pid): void
    {
        $state = JobState::pending(1);
        $state->pid = $pid;
        $state->status = JobStatus::RUNNING;
        $state->steps[0]['status'] = JobState::STEP_RUNNING;
        $this->store->writeState($id, $state);
    }

    public function testRoundCoexistsWithDataSourceJobsButNotWithAnotherRoundOrUpdate(): void
    {
        // Běžící reseed na zdroji dat → kolo projde (D134: kolo nevylučuje ostatní úlohy).
        $this->launcher->launch($this->record('20261027-120000-aaaaaa'));
        $round = $this->launcher->launch($this->roundRecord('20261027-120001-bbbbbb'));
        $this->assertSame(JobStatus::STARTING, $round['status']);
        $this->assertNull($round['dataSource']);

        // Vedle běžícího kola projde úloha na volném zdroji dat (dílčí úloha kola i cizí), na obsazeném ne.
        $child = $this->launcher->launch($this->childRecord('20261027-120002-cccccc', '20261027-120001-bbbbbb', 'eeee-ffff-gggg-hhhh'));
        $this->assertSame(JobStatus::STARTING, $child['status']);
        $this->assertSame('20261027-120001-bbbbbb', $child['parent']);
        try {
            $this->launcher->launch($this->record('20261027-120003-dddddd'));
            $this->fail('expected JobConflict');
        } catch (JobConflict $e) {
            $this->assertSame('20261027-120000-aaaaaa', $e->runningJobId, 'the data source is busy, the round is not the reason');
        }

        // Druhé kolo ne.
        try {
            $this->launcher->launch($this->roundRecord('20261027-120004-eeeeee'));
            $this->fail('expected JobConflict');
        } catch (JobConflict $e) {
            $this->assertSame('20261027-120001-bbbbbb', $e->runningJobId);
            $this->assertSame('', $e->dataSourceId);
            $this->assertSame(JobRecord::TYPE_ROUND, $e->runningType);
            $this->assertStringContainsString('server-wide job 20261027-120001-bbbbbb (round)', $e->getMessage());
        }
        // Update vedle kola ne (U1).
        try {
            $this->launcher->launch($this->updateRecord('20261027-120005-ffffff'));
            $this->fail('expected JobConflict');
        } catch (JobConflict $e) {
            $this->assertContains($e->runningJobId, ['20261027-120000-aaaaaa', '20261027-120001-bbbbbb', '20261027-120002-cccccc']);
        }
        $this->assertCount(3, $this->spawns);
    }

    public function testUpdateBlocksARound(): void
    {
        $this->launcher->launch($this->updateRecord('20261027-120000-aaaaaa'));

        try {
            $this->launcher->launch($this->roundRecord('20261027-120001-bbbbbb'));
            $this->fail('expected JobConflict');
        } catch (JobConflict $e) {
            $this->assertSame('20261027-120000-aaaaaa', $e->runningJobId);
            $this->assertSame(JobRecord::TYPE_UPDATE, $e->runningType);
        }
        $this->assertCount(1, $this->spawns);
    }

    public function testCancelCascadesToActiveChildren(): void
    {
        $this->launcher->launch($this->roundRecord('20261027-120000-aaaaaa'));
        $this->markRunning('20261027-120000-aaaaaa', 4242);
        $this->launcher->launch($this->childRecord('20261027-120001-bbbbbb', '20261027-120000-aaaaaa'));
        $this->markRunning('20261027-120001-bbbbbb', 5151);
        // Hotová dílčí úloha se neruší.
        $this->launcher->launch($this->childRecord('20261027-120002-cccccc', '20261027-120000-aaaaaa', 'eeee-ffff-gggg-hhhh'));
        $done = JobState::pending(1);
        $done->pid = 6161;
        $done->status = JobStatus::FINISHED;
        $this->store->writeState('20261027-120002-cccccc', $done);

        $view = $this->launcher->cancel('20261027-120000-aaaaaa');

        $this->assertSame([[-4242, JobLauncher::SIGTERM], [-5151, JobLauncher::SIGTERM]], $this->kills, 'kolo i jeho běžící dílčí úloha — každá má vlastní session');
        $this->assertSame(JobStatus::CANCELLING, $view['status'] ?? null);
        $this->assertFileExists($this->dir . '/20261027-120001-bbbbbb/cancel.json');
        $this->assertFileDoesNotExist($this->dir . '/20261027-120002-cccccc/cancel.json');
        $this->assertSame(JobStatus::CANCELLING, $this->store->viewOf('20261027-120001-bbbbbb')['status']);
    }

    public function testForceCancelCascadesWithSigkill(): void
    {
        // Živost per úloha: SIGKILL skupině kola nesmí „zabít“ zámek dílčí úlohy (vlastní session).
        $dead = [];
        $pids = ['20261027-120000-aaaaaa' => 4242, '20261027-120001-bbbbbb' => 5151];
        $this->store = new JobStore($this->dir, static function (string $jobDir) use (&$dead): bool {
            return !in_array(basename($jobDir), $dead, true);
        }, fn (): \DateTimeImmutable => $this->now);
        $this->launcher = new JobLauncher(
            $this->store,
            function (array $argv, string $cwd, string $log): bool {
                $this->spawns[] = ['argv' => $argv, 'cwd' => $cwd, 'log' => $log];
                return true;
            },
            function (int $pid, int $signal) use (&$dead, $pids): bool {
                $this->kills[] = [$pid, $signal];
                if ($signal === JobLauncher::SIGKILL) {
                    $dead[] = (string) array_search(-$pid, $pids, true);
                }
                return true;
            },
            ['/usr/bin/php', '/repo/bin/shpd-server'],
        );
        $this->launcher->launch($this->roundRecord('20261027-120000-aaaaaa'));
        $this->markRunning('20261027-120000-aaaaaa', 4242);
        $this->launcher->launch($this->childRecord('20261027-120001-bbbbbb', '20261027-120000-aaaaaa'));
        $this->markRunning('20261027-120001-bbbbbb', 5151);

        $view = $this->launcher->cancel('20261027-120000-aaaaaa', true);

        $this->assertSame([[-4242, JobLauncher::SIGKILL], [-5151, JobLauncher::SIGKILL]], $this->kills);
        $this->assertSame(JobStatus::CANCELLED, $view['status'] ?? null);
        $child = $this->store->viewOf('20261027-120001-bbbbbb');
        $this->assertSame(JobStatus::CANCELLED, $child['status']);
        $this->assertTrue($child['forced']);
    }

    public function testLaunchLockIsNotInheritedBySpawnedProcesses(): void
    {
        if (!is_dir('/proc/self/fd')) {
            $this->markTestSkipped('needs /proc');
        }
        $listing = null;
        $launcher = new JobLauncher(
            $this->store,
            static function (array $argv, string $cwd, string $log) use (&$listing): bool {
                // Místo obalu spustíme výpis deskriptorů potomka — co zdědil, to by zdědil i obal.
                $proc = proc_open(['sh', '-c', 'ls -l /proc/$$/fd'], [1 => ['pipe', 'w']], $pipes);
                $listing = $proc === false ? '' : (string) stream_get_contents($pipes[1]);
                if ($proc !== false) {
                    fclose($pipes[1]);
                    proc_close($proc);
                }
                return true;
            },
            null,
            ['/usr/bin/php', '/repo/bin/shpd-server'],
        );

        $launcher->launch($this->record('20261027-120000-aaaaaa'));

        $this->assertIsString($listing);
        $this->assertStringContainsString(' -> ', $listing, 'the listing ran');
        $this->assertStringNotContainsString(JobStore::LAUNCH_LOCK, (string) $listing, 'the launch lock is opened close-on-exec');
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
