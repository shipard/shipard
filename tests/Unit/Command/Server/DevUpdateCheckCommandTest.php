<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\DevUpdateCheckCommand;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Core\DevUpdate\UpdateStateStore;
use Shipard\Tests\Fixtures\Core\DevUpdate\GitFixture;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDevUpdateCheckCommand extends DevUpdateCheckCommand
{
    public function __construct(
        ServerConfig $config,
        private readonly GitFixture $fx,
        private readonly UpdateStateStore $state,
        private readonly JobStore $jobs,
        private readonly string $upstream,
    ) {
        parent::__construct($config);
    }

    protected function getRepoRoot(): string
    {
        return $this->fx->checkout;
    }

    protected function environment(): array
    {
        return $this->fx->env();
    }

    protected function jobStore(): JobStore
    {
        return $this->jobs;
    }

    protected function stateStore(): UpdateStateStore
    {
        return $this->state;
    }

    protected function upstreamUrl(): string
    {
        return $this->upstream;
    }
}

/** `shpd-server dev-update-check` (#129 D22): jen development, výpis, `--json`, exit podle kontroly. */
final class DevUpdateCheckCommandTest extends TestCase
{
    private GitFixture $fx;
    private UpdateStateStore $state;
    private JobStore $jobs;

    protected function setUp(): void
    {
        if (!GitFixture::available()) {
            $this->markTestSkipped('git is not available');
        }
        $this->fx = GitFixture::create();
        $now = new \DateTimeImmutable('2026-10-17T12:00:00Z');
        $this->state = new UpdateStateStore($this->fx->base . '/run/dev-update', static fn (): \DateTimeImmutable => $now);
        $this->jobs = new JobStore($this->fx->base . '/run/dev-jobs', static fn (): bool => true, static fn (): \DateTimeImmutable => $now);
    }

    protected function tearDown(): void
    {
        if (isset($this->fx)) {
            $this->fx->cleanup();
        }
    }

    private function tester(string $mode = 'development', ?string $upstream = null): CommandTester
    {
        $config = $this->createMock(ServerConfig::class);
        $config->method('getMode')->willReturn($mode);
        return new CommandTester(new TestableDevUpdateCheckCommand($config, $this->fx, $this->state, $this->jobs, $upstream ?? $this->fx->upstream));
    }

    public function testRefusedOutsideDevelopment(): void
    {
        $tester = $this->tester('production');
        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('development servers only', $tester->getDisplay());
        $this->assertNull($this->state->read(), 'no fetch, no state');
    }

    public function testBehindPrintsCommitsAndAllowsTheUpdate(): void
    {
        $this->fx->commitUpstream('first new', '2026-10-01T08:00:00+00:00');
        $this->fx->commitUpstream('second new', '2026-10-02T08:00:00+00:00');

        $tester = $this->tester();
        $this->assertSame(0, $tester->execute([]));
        $out = $tester->getDisplay();

        $this->assertStringContainsString('HEAD: stable @', $out);
        $this->assertStringContainsString('(refs/shipard/upstream/stable)', $out);
        $this->assertStringContainsString('Behind: 2   Ahead: 0', $out);
        $this->assertStringContainsString('Oldest missing change: 2026-10-01T08:00:00+00:00', $out);
        $this->assertStringContainsString('second new', $out);
        $this->assertStringContainsString('first new', $out);
        $this->assertStringContainsString('Update: allowed', $out);
        $this->assertSame(2, $this->state->read()['behind']);
    }

    public function testUpToDateAndBlockedByLocalCommit(): void
    {
        $tester = $this->tester();
        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('Up to date with stable.', $tester->getDisplay());

        $this->fx->commitLocal('mine');
        $tester = $this->tester();
        $this->assertSame(0, $tester->execute([]), 'behind or ahead is not a failure');
        $this->assertStringContainsString('Update: blocked', $tester->getDisplay());
        $this->assertStringContainsString('1 local commit(s)', $tester->getDisplay());
    }

    public function testJsonOutputCarriesStateAndBlockers(): void
    {
        $this->fx->commitUpstream('new');
        $tester = $this->tester();

        $this->assertSame(0, $tester->execute(['--json' => true]));
        $data = json_decode($tester->getDisplay(), true);

        $this->assertIsArray($data);
        $this->assertSame(1, $data['state']['behind']);
        $this->assertFalse($data['stale']);
        $this->assertFalse($data['checking']);
        $this->assertSame('stable', $data['head']['branch']);
        $this->assertSame([], $data['blockers']);
        $this->assertTrue($data['canUpdate']);
        $this->assertFalse($data['busy']);
    }

    public function testFetchFailureIsExitOneWithTheReason(): void
    {
        $tester = $this->tester(upstream: $this->fx->base . '/nope.git');
        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('Check failed (fetchFailed)', $tester->getDisplay());
        $this->assertStringContainsString('dev-update-check again', $tester->getDisplay(), 'notChecked advice');
        $this->assertFalse($this->state->read()['ok']);
    }

    public function testBusyLockIsNotAnError(): void
    {
        $handle = $this->state->tryLock();
        $tester = $this->tester();
        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('Another check is already running', $tester->getDisplay());
        $this->state->unlock($handle);
    }
}
