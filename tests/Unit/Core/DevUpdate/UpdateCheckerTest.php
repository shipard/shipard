<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\DevUpdate;

use PHPUnit\Framework\TestCase;
use Shipard\Core\DevUpdate\Git;
use Shipard\Core\DevUpdate\UpdateChecker;
use Shipard\Core\DevUpdate\UpdateStateStore;
use Shipard\Tests\Fixtures\Core\DevUpdate\GitFixture;

/**
 * Kontrola zastaralosti nad dočasnými repozitáři (#129 D16–D18): pozadu /
 * aktuální / napřed / obojí, cílová reference podle `origin`, limit commitů,
 * nejstarší chybějící změna, selhání fetche, zámek, značka požadavku.
 */
final class UpdateCheckerTest extends TestCase
{
    private GitFixture $fx;
    private UpdateStateStore $store;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        if (!GitFixture::available()) {
            $this->markTestSkipped('git is not available');
        }
        $this->fx = GitFixture::create();
        $this->now = new \DateTimeImmutable('2026-10-17T12:00:00Z');
        $this->store = new UpdateStateStore($this->fx->base . '/run/dev-update', fn (): \DateTimeImmutable => $this->now);
    }

    protected function tearDown(): void
    {
        if (isset($this->fx)) {
            $this->fx->cleanup();
        }
    }

    private function checker(?string $upstreamUrl = null): UpdateChecker
    {
        return new UpdateChecker(new Git($this->fx->checkout, $this->fx->env()), $this->store, $upstreamUrl ?? $this->fx->upstream);
    }

    public function testBehindReportsCommitsNewestFirstAndOldestDate(): void
    {
        $older = $this->fx->commitUpstream('older change', '2026-09-20T08:00:00+00:00');
        $newer = $this->fx->commitUpstream('newer change', '2026-10-15T08:00:00+00:00');

        $state = $this->checker()->check();

        $this->assertNotNull($state);
        $this->assertTrue($state['ok']);
        $this->assertSame('2026-10-17T12:00:00Z', $state['checkedAt']);
        $this->assertSame(2, $state['behind']);
        $this->assertSame(0, $state['ahead']);
        $this->assertSame('refs/shipard/upstream/stable', $state['upstream']['ref'], 'origin is a local path = fork');
        $this->assertSame($newer, $state['upstream']['hash']);
        $this->assertSame($this->fx->headOf($this->fx->checkout), $state['head']['hash']);
        $this->assertSame('stable', $state['head']['branch']);
        $this->assertSame(['newer change', 'older change'], array_column($state['commits'], 'subject'));
        $this->assertSame([$newer, $older], array_column($state['commits'], 'fullHash'));
        $this->assertSame(substr($newer, 0, 7), substr($state['commits'][0]['hash'], 0, 7));
        $this->assertSame('2026-09-20T08:00:00+00:00', $state['oldestMissingAt']);
        $this->assertSame('https://github.com/shipard/shipard/compare/' . $state['head']['hash'] . '...stable', $state['compareUrl']);
        $this->assertNull($state['errorCode']);
        $this->assertSame($newer, $this->fx->headOf($this->fx->checkout, 'refs/shipard/upstream/stable'), 'fetch filled the private ref');
        $this->assertSame($state, $this->store->read(), 'state.json written');
    }

    public function testUpToDateAndAheadAndBoth(): void
    {
        $state = $this->checker()->check();
        $this->assertSame([0, 0, [], null], [$state['behind'], $state['ahead'], $state['commits'], $state['oldestMissingAt']]);

        $this->fx->commitLocal('local work');
        $state = $this->checker()->check();
        $this->assertSame([0, 1], [$state['behind'], $state['ahead']]);

        $this->fx->commitUpstream('upstream work');
        $state = $this->checker()->check();
        $this->assertSame([1, 1], [$state['behind'], $state['ahead']]);
        $this->assertSame(['upstream work'], array_column($state['commits'], 'subject'));
    }

    public function testCanonicalOriginUpdatesOriginStable(): void
    {
        $this->fx->git($this->fx->checkout, ['remote', 'set-url', 'origin', 'https://github.com/shipard/shipard.git']);
        $hash = $this->fx->commitUpstream('on github');

        $state = $this->checker()->check();

        $this->assertTrue($state['ok']);
        $this->assertSame('refs/remotes/origin/stable', $state['upstream']['ref']);
        $this->assertSame($hash, $this->fx->headOf($this->fx->checkout, 'refs/remotes/origin/stable'));
        $this->assertSame(1, $state['behind']);
    }

    public function testCommitListIsCappedButCountIsNot(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->fx->commitUpstream("change {$i}");
        }

        $state = $this->checker()->check();

        $this->assertSame(12, $state['behind']);
        $this->assertCount(UpdateChecker::COMMIT_LIMIT, $state['commits']);
        $this->assertSame('change 12', $state['commits'][0]['subject']);
    }

    public function testFetchFailureIsRecordedNotThrown(): void
    {
        $state = $this->checker($this->fx->base . '/does-not-exist.git')->check();

        $this->assertNotNull($state);
        $this->assertFalse($state['ok']);
        $this->assertSame(UpdateChecker::ERROR_FETCH_FAILED, $state['errorCode']);
        $this->assertNotSame('', $state['error']);
        $this->assertNull($state['behind']);
        $this->assertSame([], $state['commits']);
        $this->assertSame('stable', $state['head']['branch'], 'local facts are still there');
        $this->assertSame($state, $this->store->read());
    }

    public function testNotARepository(): void
    {
        $dir = $this->fx->base . '/plain';
        mkdir($dir);
        $state = (new UpdateChecker(new Git($dir, $this->fx->env()), $this->store, $this->fx->upstream))->check();

        $this->assertFalse($state['ok']);
        $this->assertSame(UpdateChecker::ERROR_NOT_A_REPOSITORY, $state['errorCode']);
        $this->assertNull($state['head']['hash']);
    }

    public function testBusyLockReturnsNullAndLeavesStateAlone(): void
    {
        $this->store->write(['ok' => true, 'checkedAt' => 'x']);
        $handle = $this->store->tryLock();

        $this->assertNull($this->checker()->check());
        $this->assertSame(['ok' => true, 'checkedAt' => 'x'], $this->store->read());

        $this->store->unlock($handle);
        $this->assertNotNull($this->checker()->check());
    }

    public function testCheckClearsTheRequestMark(): void
    {
        $this->store->markRequested();
        $this->assertTrue($this->store->isChecking());

        $this->checker()->check();

        $this->assertFalse($this->store->isChecking());
    }
}
