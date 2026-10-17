<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\DevUpdate;

use PHPUnit\Framework\TestCase;
use Shipard\Core\DevUpdate\UpdateStateStore;
use Shipard\Tests\Fixtures\Core\DevUpdate\GitFixture;

/** Cache kontroly (#129 D18): atomický zápis, neplatnost, zámek a značka požadavku. */
final class UpdateStateStoreTest extends TestCase
{
    private string $dir;
    private \DateTimeImmutable $now;
    private UpdateStateStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shpd_upd_' . bin2hex(random_bytes(6)) . '/dev-update';
        $this->now = new \DateTimeImmutable('2026-10-17T12:00:00Z');
        $this->store = new UpdateStateStore($this->dir, fn (): \DateTimeImmutable => $this->now);
    }

    protected function tearDown(): void
    {
        GitFixture::rrmdir(dirname($this->dir));
    }

    /** @return array<string, mixed> */
    private function freshState(string $head = 'a1', string $checkedAt = '2026-10-17T11:30:00Z', bool $ok = true): array
    {
        return ['ok' => $ok, 'checkedAt' => $checkedAt, 'head' => ['hash' => $head, 'branch' => 'stable'], 'behind' => 2, 'ahead' => 0];
    }

    public function testWriteCreatesDirAndReadsBackWithoutTempLeftovers(): void
    {
        $this->assertNull($this->store->read());

        $this->store->write($this->freshState());

        $this->assertSame($this->freshState(), $this->store->read());
        $this->assertSame(['state.json'], array_values(array_diff(scandir($this->dir) ?: [], ['.', '..'])));
        $this->assertSame('0640', substr(sprintf('%o', fileperms($this->dir . '/state.json')), -4));
    }

    public function testUnreadableJsonReadsAsNull(): void
    {
        mkdir($this->dir, 0700, true);
        file_put_contents($this->dir . '/state.json', '{not json');
        $this->assertNull($this->store->read());
    }

    public function testIsStaleForMissingFailedOldOrOtherHead(): void
    {
        $this->assertTrue($this->store->isStale(null, 'a1'), 'missing');
        $this->assertTrue($this->store->isStale($this->freshState(ok: false), 'a1'), 'failed');
        $this->assertTrue($this->store->isStale($this->freshState(checkedAt: '2026-10-17T10:59:59Z'), 'a1'), 'older than an hour');
        $this->assertTrue($this->store->isStale($this->freshState(head: 'b2'), 'a1'), 'HEAD moved since the check');
        $this->assertTrue($this->store->isStale($this->freshState(), null), 'HEAD unknown');
        $this->assertFalse($this->store->isStale($this->freshState(checkedAt: '2026-10-17T11:00:00Z'), 'a1'), 'exactly an hour is still fresh');
        $this->assertFalse($this->store->isStale($this->freshState(), 'a1'));
    }

    public function testAutoCheckBacksOffAfterAFailedCheck(): void
    {
        $this->assertTrue($this->store->isAutoCheckDue(null, 'a1'), 'missing state');
        $this->assertFalse($this->store->isAutoCheckDue($this->freshState(), 'a1'), 'fresh state');
        $this->assertTrue($this->store->isAutoCheckDue($this->freshState(checkedAt: '2026-10-17T10:59:59Z'), 'a1'), 'old successful state');
        $this->assertTrue($this->store->isAutoCheckDue($this->freshState(head: 'b2'), 'a1'), 'HEAD moved');

        $recentFailure = $this->freshState(checkedAt: '2026-10-17T11:55:00Z', ok: false);
        $this->assertTrue($this->store->isStale($recentFailure, 'a1'), 'a failed state never counts as valid');
        $this->assertFalse($this->store->isAutoCheckDue($recentFailure, 'a1'), 'failed 5 minutes ago — no automatic retry yet');
        $this->assertFalse($this->store->isAutoCheckDue($this->freshState(checkedAt: '2026-10-17T11:50:01Z', ok: false), 'a1'), 'just under 10 minutes');
        $this->assertTrue($this->store->isAutoCheckDue($this->freshState(checkedAt: '2026-10-17T11:50:00Z', ok: false), 'a1'), 'exactly 10 minutes');
        $this->assertTrue($this->store->isAutoCheckDue(['ok' => false], 'a1'), 'failed state without a time');
    }

    public function testLockIsExclusiveAndVisibleToTheProbe(): void
    {
        $this->assertFalse($this->store->isLocked(), 'no lock file yet');

        $handle = $this->store->tryLock();
        $this->assertIsResource($handle);
        $this->assertTrue($this->store->isLocked());
        $this->assertTrue($this->store->isChecking());
        $this->assertNull($this->store->tryLock(), 'second lock in the same process must fail (flock is per open file description)');

        $this->store->unlock($handle);
        $this->assertFalse($this->store->isLocked());
        $this->assertFalse($this->store->isChecking());
    }

    public function testRequestMarkCountsAsCheckingForAMinute(): void
    {
        $this->store->markRequested();
        $this->assertTrue($this->store->isChecking());

        $later = new UpdateStateStore($this->dir, fn (): \DateTimeImmutable => $this->now->modify('+61 seconds'));
        $this->assertFalse($later->isChecking(), 'stale mark no longer counts');

        $this->store->clearRequested();
        $this->assertFalse($this->store->isChecking());
        $this->assertFileDoesNotExist($this->dir . '/check.requested');
    }
}
