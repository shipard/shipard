<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\DevUpdate;

use PHPUnit\Framework\TestCase;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobState;
use Shipard\Core\DevJobs\JobStatus;
use Shipard\Core\DevJobs\JobStep;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Core\DevUpdate\Git;
use Shipard\Core\DevUpdate\UpdateChecker;
use Shipard\Core\DevUpdate\UpdateGuards;
use Shipard\Core\DevUpdate\UpdateStateStore;
use Shipard\Core\DevUpdate\UpdateStatus;
use Shipard\Tests\Fixtures\Core\DevUpdate\GitFixture;

/**
 * Pojistky aktualizace (#129 D20, U4–U6): každá zvlášť nad skutečným
 * klonem, vlastní úloha se nepočítá, nesledovaný soubor neblokuje, rady.
 */
final class UpdateGuardsTest extends TestCase
{
    private GitFixture $fx;
    private UpdateStateStore $store;
    private JobStore $jobs;
    private Git $git;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        if (!GitFixture::available()) {
            $this->markTestSkipped('git is not available');
        }
        $this->fx = GitFixture::create();
        $this->now = new \DateTimeImmutable('2026-10-17T12:00:00Z');
        $this->store = new UpdateStateStore($this->fx->base . '/run/dev-update', fn (): \DateTimeImmutable => $this->now);
        $this->jobs = new JobStore($this->fx->base . '/run/dev-jobs', static fn (): bool => true, fn (): \DateTimeImmutable => $this->now);
        $this->git = new Git($this->fx->checkout, $this->fx->env());
    }

    protected function tearDown(): void
    {
        if (isset($this->fx)) {
            $this->fx->cleanup();
        }
    }

    private function guards(?string $toolPath = null): UpdateGuards
    {
        return new UpdateGuards($this->git, $this->jobs, $toolPath ?? $this->fx->bin . ':/usr/bin:/bin');
    }

    /** @return array<string, mixed> */
    private function freshState(int $ahead = 0): array
    {
        return ['ok' => true, 'checkedAt' => '2026-10-17T11:59:00Z', 'head' => ['hash' => $this->fx->headOf($this->fx->checkout), 'branch' => 'stable'], 'behind' => 1, 'ahead' => $ahead];
    }

    /** @return list<string> */
    private function codes(array $blockers): array
    {
        return array_column($blockers, 'code');
    }

    private function runningJob(string $id, string $type = JobRecord::TYPE_RESEED): void
    {
        $this->jobs->ensureDir();
        $this->jobs->create(new JobRecord($id, $type, 'aaaa-bbbb-cccc-dddd', 'DS', null, null, [], [new JobStep('x', ['true'], '/tmp')], '2026-10-17T11:58:00Z', null, 60));
        $state = JobState::pending(1);
        $state->status = JobStatus::RUNNING;
        $state->pid = 4242;
        $this->jobs->writeState($id, $state);
    }

    public function testCleanCheckoutHasNoBlockers(): void
    {
        $this->assertSame([], $this->guards()->evaluate($this->freshState(), false));
    }

    public function testOtherBranchAndDetachedHead(): void
    {
        $this->fx->git($this->fx->checkout, ['checkout', '--quiet', '-b', 'feature']);
        $blockers = $this->guards()->evaluate($this->freshState(), false);
        $this->assertSame([['code' => 'notStable', 'branch' => 'feature']], $blockers);

        $this->fx->git($this->fx->checkout, ['checkout', '--quiet', '--detach']);
        $this->assertSame([['code' => 'notStable', 'branch' => null]], $this->guards()->evaluate($this->freshState(), false));
    }

    public function testDirtyTrackedFileBlocksButUntrackedDoesNot(): void
    {
        file_put_contents($this->fx->checkout . '/notes.txt', 'untracked');
        $this->assertSame([], $this->guards()->evaluate($this->freshState(), false), 'U6: untracked files never block');

        file_put_contents($this->fx->checkout . '/file-1.txt', 'changed');
        $this->assertSame(['dirty'], $this->codes($this->guards()->evaluate($this->freshState(), false)));
    }

    public function testLocalCommitsAreNotFastForwardOnlyWithAFreshState(): void
    {
        $this->assertSame([['code' => 'notFastForward', 'ahead' => 2]], $this->guards()->evaluate($this->freshState(2), false));
        $this->assertSame(
            [['code' => 'notChecked', 'reason' => 'outdated']],
            $this->guards()->evaluate($this->freshState(2), true),
            'a stale state cannot vouch for ahead — only notChecked',
        );
    }

    public function testRunningJobBlocksUnlessItIsOurOwn(): void
    {
        $this->runningJob('20261017-115800-aaaaaa');
        $this->assertSame(
            [['code' => 'jobRunning', 'jobId' => '20261017-115800-aaaaaa', 'type' => 'reseed']],
            $this->guards()->evaluate($this->freshState(), false),
        );
        $this->assertSame([], $this->guards()->evaluate($this->freshState(), false, '20261017-115800-aaaaaa'), '--job = own job');

        $this->runningJob('20261017-115900-bbbbbb', JobRecord::TYPE_VERIFY);
        $this->assertSame(['jobRunning'], $this->codes($this->guards()->evaluate($this->freshState(), false, '20261017-115800-aaaaaa')), 'another job still blocks');
    }

    public function testMissingToolsAreListed(): void
    {
        $bin = $this->fx->base . '/onlygit';
        mkdir($bin);
        symlink($this->fx->bin . '/git', $bin . '/git');
        $this->assertSame([['code' => 'missingTool', 'tools' => ['composer', 'npm']]], $this->guards($bin)->evaluate($this->freshState(), false));
    }

    public function testNotCheckedReasons(): void
    {
        $this->assertSame([['code' => 'notChecked', 'reason' => 'missing']], $this->guards()->evaluate(null, true));
        $this->assertSame([['code' => 'notChecked', 'reason' => 'failed']], $this->guards()->evaluate(['ok' => false], true));
    }

    public function testAdviceMentionsTheFacts(): void
    {
        $this->assertStringContainsString("'feature'", UpdateGuards::advice(['code' => 'notStable', 'branch' => 'feature']));
        $this->assertStringContainsString('git stash', UpdateGuards::advice(['code' => 'dirty']));
        $this->assertStringContainsString('3 local commit', UpdateGuards::advice(['code' => 'notFastForward', 'ahead' => 3]));
        $this->assertStringContainsString('20261017-115800-aaaaaa (reseed)', UpdateGuards::advice(['code' => 'jobRunning', 'jobId' => '20261017-115800-aaaaaa', 'type' => 'reseed']));
        $this->assertStringContainsString('composer, npm', UpdateGuards::advice(['code' => 'missingTool', 'tools' => ['composer', 'npm']]));
        $this->assertStringContainsString('dev-update-check', UpdateGuards::advice(['code' => 'notChecked', 'reason' => 'failed']));
    }

    public function testSnapshotCombinesCacheGuardsAndHead(): void
    {
        $status = new UpdateStatus($this->git, $this->store, $this->jobs, $this->fx->bin . ':/usr/bin:/bin');

        $snapshot = $status->snapshot();
        $this->assertNull($snapshot['state']);
        $this->assertTrue($snapshot['stale']);
        $this->assertFalse($snapshot['checking']);
        $this->assertSame('stable', $snapshot['head']['branch']);
        $this->assertSame(['notChecked'], $this->codes($snapshot['blockers']));
        $this->assertFalse($snapshot['canUpdate']);

        $this->fx->commitUpstream('new');
        (new UpdateChecker($this->git, $this->store, $this->fx->upstream))->check();
        $snapshot = $status->snapshot();
        $this->assertSame(1, $snapshot['state']['behind']);
        $this->assertFalse($snapshot['stale']);
        $this->assertSame([], $snapshot['blockers']);
        $this->assertTrue($snapshot['canUpdate']);

        $this->fx->commitLocal('moved HEAD');
        $snapshot = $status->snapshot();
        $this->assertTrue($snapshot['stale'], 'HEAD changed since the check');
        $this->assertSame(['notChecked'], $this->codes($snapshot['blockers']));
    }
}
