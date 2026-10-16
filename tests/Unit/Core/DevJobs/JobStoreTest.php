<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\DevJobs;

use PHPUnit\Framework\TestCase;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobState;
use Shipard\Core\DevJobs\JobStatus;
use Shipard\Core\DevJobs\JobStep;
use Shipard\Core\DevJobs\JobStore;

/**
 * Úložiště úloh (D58): zápis a čtení, odvozený stav (starting → failed po
 * grace, running podle živosti obalu, cancelling podle značky), reálná
 * sonda `flock`, seznam s filtry, úklid, průběh po celých řádcích, výsledek.
 */
final class JobStoreTest extends TestCase
{
    private string $dir;
    private \DateTimeImmutable $now;
    private bool $alive = true;
    private JobStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shpd_jobs_' . bin2hex(random_bytes(6));
        $this->now = new \DateTimeImmutable('2026-10-16T12:00:00Z');
        $this->store = new JobStore($this->dir, fn (string $jobDir): bool => $this->alive, fn (): \DateTimeImmutable => $this->now);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    private function record(string $id, string $ds = 'aaaa-bbbb-cccc-dddd', ?string $dataset = 'cz-vat-payer', ?string $createdAt = null): JobRecord
    {
        return new JobRecord(
            $id,
            JobRecord::TYPE_VERIFY,
            $ds,
            'Test DS',
            $dataset,
            null,
            [],
            [new JobStep('verify', ['true'], $this->dir), new JobStep('second', ['true'], $this->dir)],
            $createdAt ?? JobStore::formatTime($this->now),
            '10.0.0.5',
            60,
        );
    }

    private function runningState(int $pid = 4242): JobState
    {
        $state = JobState::pending(2);
        $state->pid = $pid;
        $state->status = JobStatus::RUNNING;
        $state->startedAt = JobStore::formatTime($this->now);
        $state->steps[0]['status'] = JobState::STEP_RUNNING;
        return $state;
    }

    public function testCreateAndReadRoundTrip(): void
    {
        $record = $this->record('20261016-120000-aaaaaa');
        $this->store->create($record);

        $this->assertFileExists($this->dir . '/20261016-120000-aaaaaa/job.json');
        $this->assertFileDoesNotExist($this->dir . '/20261016-120000-aaaaaa/job.json.tmp');
        $this->assertSame($record->toArray(), $this->store->read('20261016-120000-aaaaaa')?->toArray());
        $this->assertNull($this->store->readState('20261016-120000-aaaaaa'));
    }

    public function testCreateRefusesExistingDirectory(): void
    {
        $this->store->create($this->record('20261016-120000-aaaaaa'));

        $this->expectException(\RuntimeException::class);
        $this->store->create($this->record('20261016-120000-aaaaaa'));
    }

    public function testStartingWithinGraceThenFailedWithoutState(): void
    {
        $record = $this->record('20261016-120000-aaaaaa');
        $this->store->create($record);

        $this->now = $this->now->modify('+30 seconds');
        $this->assertSame([JobStatus::STARTING, null], $this->store->status($record, null));

        $this->now = $this->now->modify('+31 seconds');
        $this->assertSame([JobStatus::FAILED, JobStore::REASON_NOT_STARTED], $this->store->status($record, null));
    }

    public function testRunningStateDependsOnWrapperLiveness(): void
    {
        $record = $this->record('20261016-120000-aaaaaa');
        $this->store->create($record);
        $state = $this->runningState();
        $this->store->writeState($record->id, $state);

        $this->alive = true;
        $this->assertSame([JobStatus::RUNNING, null], $this->store->status($record, $state));

        $this->store->requestCancel($record->id, false);
        $this->assertSame([JobStatus::CANCELLING, null], $this->store->status($record, $state));
        $this->assertSame('2026-10-16T12:00:00Z', $this->store->view($record, $state)['cancelRequestedAt']);

        $this->alive = false;
        $this->assertSame([JobStatus::FAILED, JobStore::REASON_DIED], $this->store->status($record, $state));
    }

    public function testFinalStatesComeFromStateFile(): void
    {
        $record = $this->record('20261016-120000-aaaaaa');
        $this->store->create($record);
        $state = $this->runningState();
        $state->status = JobStatus::FINISHED;
        $this->alive = false;

        $this->assertSame([JobStatus::FINISHED, null], $this->store->status($record, $state));
        $this->assertFalse($this->store->view($record, $state)['active']);
    }

    public function testDefaultLivenessProbeUsesFlock(): void
    {
        $store = new JobStore($this->dir, null, fn (): \DateTimeImmutable => $this->now);
        $record = $this->record('20261016-120000-aaaaaa');
        $store->create($record);
        $store->writeState($record->id, $this->runningState());

        $this->assertFalse($store->isWrapperAlive($record->id), 'no lock file = wrapper never started');

        $handle = fopen($store->path($record->id, JobStore::LOCK_FILE), 'c');
        $this->assertIsResource($handle);
        $this->assertTrue(flock($handle, LOCK_EX));
        $this->assertTrue($store->isWrapperAlive($record->id));
        $this->assertSame(JobStatus::RUNNING, $store->view($record, $store->readState($record->id))['status']);

        flock($handle, LOCK_UN);
        fclose($handle);
        $this->assertFalse($store->isWrapperAlive($record->id));
        $view = $store->view($record, $store->readState($record->id));
        $this->assertSame(JobStatus::FAILED, $view['status']);
        $this->assertSame(JobStore::REASON_DIED, $view['statusReason']);
    }

    public function testListFiltersAndSortsNewestFirst(): void
    {
        $this->store->create($this->record('20260101-000000-aaaaaa', 'aaaa-bbbb-cccc-dddd', 'set-x', '2026-01-01T00:00:00Z'));
        $this->store->create($this->record('20260601-000000-bbbbbb', 'eeee-ffff-gggg-hhhh', 'set-y', '2026-06-01T00:00:00Z'));
        $this->store->create($this->record('20261016-110000-cccccc', 'aaaa-bbbb-cccc-dddd', 'set-y', '2026-10-16T11:00:00Z'));

        $ids = static fn (array $views): array => array_column($views, 'id');

        $this->assertSame(['20261016-110000-cccccc', '20260601-000000-bbbbbb', '20260101-000000-aaaaaa'], $ids($this->store->list()));
        $this->assertSame(['20261016-110000-cccccc', '20260601-000000-bbbbbb'], $ids($this->store->list('set-y')));
        $this->assertSame(['20261016-110000-cccccc', '20260101-000000-aaaaaa'], $ids($this->store->list(null, 'aaaa-bbbb-cccc-dddd')));
        $this->assertSame(['20261016-110000-cccccc'], $ids($this->store->list(null, null, 30)));
    }

    public function testActiveJobForFindsOnlyActiveJobs(): void
    {
        $this->store->create($this->record('20260101-000000-aaaaaa', 'aaaa-bbbb-cccc-dddd', 'set-x', '2026-01-01T00:00:00Z'));
        $this->store->create($this->record('20261016-115959-cccccc', 'aaaa-bbbb-cccc-dddd'));
        $this->store->writeState('20261016-115959-cccccc', $this->runningState());

        $this->alive = true;
        $this->assertSame('20261016-115959-cccccc', $this->store->activeJobFor('aaaa-bbbb-cccc-dddd')['id'] ?? null);
        $this->assertNull($this->store->activeJobFor('eeee-ffff-gggg-hhhh'));

        $this->alive = false;
        $this->assertNull($this->store->activeJobFor('aaaa-bbbb-cccc-dddd'), 'dead wrapper = failed = not active');
    }

    public function testPruneRemovesOnlyInactiveOldJobs(): void
    {
        $this->store->create($this->record('20260101-000000-aaaaaa', 'a1', 'x', '2026-01-01T00:00:00Z')); // old, never started → failed
        $this->store->create($this->record('20260601-000000-bbbbbb', 'b1', 'x', '2026-06-01T00:00:00Z')); // old, running, wrapper alive
        $this->store->writeState('20260601-000000-bbbbbb', $this->runningState());
        $this->store->create($this->record('20261010-000000-cccccc', 'c1', 'x', '2026-10-10T00:00:00Z')); // recent, finished
        $finished = $this->runningState();
        $finished->status = JobStatus::FINISHED;
        $this->store->writeState('20261010-000000-cccccc', $finished);
        mkdir($this->dir . '/20250101-000000-dddddd'); // broken: no job.json, old by name

        $this->alive = true;
        $removed = $this->store->prune(14);

        $this->assertSame(2, $removed);
        $this->assertDirectoryDoesNotExist($this->dir . '/20260101-000000-aaaaaa');
        $this->assertDirectoryExists($this->dir . '/20260601-000000-bbbbbb');
        $this->assertDirectoryExists($this->dir . '/20261010-000000-cccccc');
        $this->assertDirectoryDoesNotExist($this->dir . '/20250101-000000-dddddd');
    }

    public function testProgressReturnsWholeLinesOnly(): void
    {
        $record = $this->record('20261016-120000-aaaaaa');
        $this->store->create($record);
        $path = $this->store->path($record->id, JobStore::PROGRESS_FILE);

        $this->assertSame(['events' => [], 'nextOffset' => 0], $this->store->progress($record->id, 0));

        $line1 = json_encode(['event' => 'started', 'calls' => 4]) . "\n";
        $line2 = json_encode(['event' => 'call', 'messageId' => 'M1']) . "\n";
        file_put_contents($path, $line1 . $line2 . '{"event":"call","mess');

        $first = $this->store->progress($record->id, 0);
        $this->assertSame(['started', 'call'], array_column($first['events'], 'event'));
        $this->assertSame(strlen($line1 . $line2), $first['nextOffset']);

        file_put_contents($path, 'ageId":"M2"}' . "\n", FILE_APPEND);
        $second = $this->store->progress($record->id, $first['nextOffset']);
        $this->assertSame([['event' => 'call', 'messageId' => 'M2']], $second['events']);
        $this->assertSame((int) filesize($path), $second['nextOffset']);

        $this->assertSame([], $this->store->progress($record->id, $second['nextOffset'])['events']);
        $this->assertCount(3, $this->store->progress($record->id, 999_999)['events'], 'offset past EOF restarts from 0');
    }

    public function testResultIsNullWhenEmptyOrInvalid(): void
    {
        $record = $this->record('20261016-120000-aaaaaa');
        $this->store->create($record);
        $path = $this->store->path($record->id, JobStore::RESULT_FILE);

        $this->assertNull($this->store->result($record->id));
        file_put_contents($path, '');
        $this->assertNull($this->store->result($record->id));
        $this->assertFalse($this->store->view($record, null)['hasResult']);
        file_put_contents($path, 'not json');
        $this->assertNull($this->store->result($record->id));
        file_put_contents($path, json_encode(['file' => '/x/y.json']) . "\n");
        $this->assertSame(['file' => '/x/y.json'], $this->store->result($record->id));
        $this->assertTrue($this->store->view($record, null)['hasResult']);
    }

    public function testViewMergesStepsAndOutputTail(): void
    {
        $record = $this->record('20261016-120000-aaaaaa');
        $this->store->create($record);
        $state = $this->runningState();
        $state->steps[0] = ['status' => JobState::STEP_FINISHED, 'exitCode' => 0, 'termSignal' => null, 'startedAt' => 'a', 'finishedAt' => 'b'];
        $state->steps[1]['status'] = JobState::STEP_RUNNING;
        $this->store->writeState($record->id, $state);
        file_put_contents($this->store->path($record->id, JobStore::OUTPUT_FILE), "line1\nline2\nline3\n");

        $view = $this->store->view($record, $this->store->readState($record->id));

        $this->assertSame('verify', $view['steps'][0]['label']);
        $this->assertSame(['true'], $view['steps'][0]['argv']);
        $this->assertSame(JobState::STEP_FINISHED, $view['steps'][0]['status']);
        $this->assertSame(0, $view['steps'][0]['exitCode']);
        $this->assertSame(JobState::STEP_RUNNING, $view['steps'][1]['status']);
        $this->assertSame(4242, $view['pid']);
        $this->assertSame(0, $view['exitCode']);
        $this->assertSame(['id' => 'aaaa-bbbb-cccc-dddd', 'name' => 'Test DS'], $view['dataSource']);
        $this->assertSame("line2\nline3", $this->store->outputTail($record->id, 2));
    }

    public function testRejectsInvalidIds(): void
    {
        $this->assertNull($this->store->read('../etc'));
        $this->assertNull($this->store->read('20261016-120000-zzzzzz'));
        $this->assertNull($this->store->viewOf('20261016-120000-zzzzzz'));

        $this->expectException(\InvalidArgumentException::class);
        $this->store->jobDir('../etc');
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
