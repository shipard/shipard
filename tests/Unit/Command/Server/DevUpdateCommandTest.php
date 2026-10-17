<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\DevUpdateCommand;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobState;
use Shipard\Core\DevJobs\JobStatus;
use Shipard\Core\DevJobs\JobStep;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Core\DevUpdate\UpdateStateStore;
use Shipard\Core\DevUpdate\UpdateStepRunner;
use Shipard\Tests\Fixtures\Core\DevUpdate\GitFixture;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDevUpdateCommand extends DevUpdateCommand
{
    /** @var list<array{argv: list<string>, cwd: string, env: array<string, string>}> */
    public array $steps = [];
    /** @var array<string, int> exit kód podle názvu kroku (`ds-upgrade-all`, `cron-install`, `dev-update.sh`, `dev-update-check`) */
    public array $exitCodes = [];
    public bool $terminal = false;

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

    protected function serverCommand(): array
    {
        return ['/usr/bin/php', '/repo/bin/shpd-server'];
    }

    protected function stdinIsTerminal(): bool
    {
        return $this->terminal;
    }

    /** git běží doopravdy (merge v klonu), ostatní kroky se jen zaznamenají. */
    protected function runStep(UpdateStepRunner $runner, array $argv, string $cwd, array $env): int
    {
        $this->steps[] = ['argv' => $argv, 'cwd' => $cwd, 'env' => $env];
        if (str_ends_with($argv[0], '/git')) {
            // Výstup gitu nepatří do výstupu testu.
            $quiet = [...$argv];
            $at = array_search('merge', $quiet, true);
            array_splice($quiet, $at === false ? count($quiet) : $at + 1, 0, ['--quiet']);
            return parent::runStep($runner, $quiet, $cwd, $env);
        }
        foreach ($this->exitCodes as $name => $code) {
            foreach ($argv as $arg) {
                if (str_ends_with($arg, $name)) {
                    return $code;
                }
            }
        }
        return 0;
    }
}

/**
 * `shpd-server dev-update` (#129 D21, D22): pojistky nic nemění, fast-forward
 * v klonu bez hooků, pořadí a argv kroků 3–5, cron jen varuje, selhání
 * kroku zastaví zbytek, `--job` = vlastní úloha, potvrzení bez `--yes`.
 */
final class DevUpdateCommandTest extends TestCase
{
    private GitFixture $fx;
    private UpdateStateStore $state;
    private JobStore $jobs;
    private TestableDevUpdateCommand $command;

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

    private function tester(string $mode = 'development'): CommandTester
    {
        $config = $this->createMock(ServerConfig::class);
        $config->method('getMode')->willReturn($mode);
        $this->command = new TestableDevUpdateCommand($config, $this->fx, $this->state, $this->jobs, $this->fx->upstream);
        $app = new Application();
        $app->add($this->command);
        return new CommandTester($this->command);
    }

    /** @return list<string> názvy kroků podle posledního prvku argv, který něco říká */
    private function stepNames(): array
    {
        $names = [];
        foreach ($this->command->steps as $step) {
            $argv = $step['argv'];
            $names[] = str_ends_with($argv[0], '/git') ? 'git ' . $argv[5] : basename(end($argv) ?: '');
        }
        return $names;
    }

    private function runningJob(string $id): void
    {
        $this->jobs->ensureDir();
        $this->jobs->create(new JobRecord($id, JobRecord::TYPE_RESEED, 'aaaa-bbbb-cccc-dddd', 'DS', null, null, [], [new JobStep('x', ['true'], '/tmp')], '2026-10-17T11:58:00Z', null, 60));
        $state = JobState::pending(1);
        $state->status = JobStatus::RUNNING;
        $state->pid = 4242;
        $this->jobs->writeState($id, $state);
    }

    public function testRefusedOutsideDevelopment(): void
    {
        $this->fx->commitUpstream('new');
        $tester = $this->tester('production');

        $this->assertSame(1, $tester->execute(['--yes' => true]));
        $this->assertStringContainsString('development servers only', $tester->getDisplay());
        $this->assertSame([], $this->command->steps);
    }

    public function testBlockedByDirtyTreeChangesNothing(): void
    {
        $upstream = $this->fx->commitUpstream('new');
        $before = $this->fx->headOf($this->fx->checkout);
        file_put_contents($this->fx->checkout . '/file-1.txt', 'edited');
        $tester = $this->tester();

        $this->assertSame(1, $tester->execute(['--yes' => true]));
        $this->assertStringContainsString('Update blocked', $tester->getDisplay());
        $this->assertStringContainsString('git stash', $tester->getDisplay());
        $this->assertSame([], $this->command->steps);
        $this->assertSame($before, $this->fx->headOf($this->fx->checkout));
        $this->assertNotSame($upstream, $before);
    }

    public function testUpToDateDoesNothing(): void
    {
        $tester = $this->tester();
        $this->assertSame(0, $tester->execute(['--yes' => true]));
        $this->assertStringContainsString('nothing to do', $tester->getDisplay());
        $this->assertSame([], $this->command->steps);
    }

    public function testFullRunFastForwardsWithoutHooksAndRunsEveryStep(): void
    {
        // post-merge hook v checkoutu: dev-update ho nesmí spustit (dev-update.sh by běžel dvakrát).
        $hooks = $this->fx->base . '/hooks';
        mkdir($hooks);
        file_put_contents($hooks . '/post-merge', "#!/bin/sh\ntouch " . escapeshellarg($this->fx->base . '/hook-ran') . "\n");
        chmod($hooks . '/post-merge', 0755);
        $this->fx->git($this->fx->checkout, ['config', 'core.hooksPath', $hooks]);

        $upstream = $this->fx->commitUpstream('new');
        $tester = $this->tester();
        $this->command->exitCodes = ['cron-install' => 1];

        $this->assertSame(0, $tester->execute(['--yes' => true]), $tester->getDisplay());
        $out = $tester->getDisplay();

        $this->assertSame($upstream, $this->fx->headOf($this->fx->checkout), 'fast-forwarded');
        $this->assertFileDoesNotExist($this->fx->base . '/hook-ran', 'hooks were disabled for the merge');
        $this->assertSame(['git merge', 'dev-update.sh', 'ds-upgrade-all', '--check', 'dev-update-check'], $this->stepNames());

        $merge = $this->command->steps[0];
        $this->assertSame([$this->fx->bin . '/git', '-C', $this->fx->checkout, '-c', 'core.hooksPath=/dev/null', 'merge', '--ff-only', 'refs/shipard/upstream/stable'], $merge['argv']);
        $this->assertSame($this->fx->checkout, $merge['cwd']);

        $script = $this->command->steps[1];
        $this->assertSame('/usr/bin/bash', $script['argv'][0]);
        $this->assertSame($this->fx->checkout . '/scripts/dev-update.sh', $script['argv'][1]);
        $this->assertSame('1', $script['env']['SHPD_DEV_UPDATE_NO_CRON_HINT'], 'cron hint comes from step 5, not from the script');
        $this->assertSame($this->fx->bin . ':/usr/bin:/bin', $script['env']['PATH']);

        $this->assertSame(['/usr/bin/php', '/repo/bin/shpd-server', 'ds-upgrade-all'], $this->command->steps[2]['argv']);
        $this->assertSame(['/usr/bin/php', '/repo/bin/shpd-server', 'cron-install', '--check'], $this->command->steps[3]['argv']);
        $this->assertSame(['/usr/bin/php', '/repo/bin/shpd-server', 'dev-update-check'], $this->command->steps[4]['argv']);
        foreach ($this->command->steps as $step) {
            $this->assertSame('0', $step['env']['GIT_TERMINAL_PROMPT']);
        }

        $this->assertStringContainsString('==> [1/5] Checking stable on GitHub', $out);
        $this->assertStringContainsString('==> [2/5] git merge --ff-only refs/shipard/upstream/stable', $out);
        $this->assertStringContainsString('==> [5/5] shpd-server cron-install --check', $out);
        $this->assertStringContainsString('sudo shpd-server cron-install', $out, 'cron outdated = warning only');
        $this->assertStringContainsString('Update finished', $out);
    }

    public function testFailingScriptStopsBeforeDsUpgrade(): void
    {
        $upstream = $this->fx->commitUpstream('new');
        $tester = $this->tester();
        $this->command->exitCodes = ['dev-update.sh' => 2];

        $this->assertSame(1, $tester->execute(['--yes' => true]));

        $this->assertSame(['git merge', 'dev-update.sh'], $this->stepNames());
        $this->assertSame($upstream, $this->fx->headOf($this->fx->checkout), 'the merge already happened');
        $this->assertStringContainsString('dev-update.sh failed (exit 2)', $tester->getDisplay());
    }

    public function testFailingDsUpgradeIsExitOneAndSkipsCronCheck(): void
    {
        $this->fx->commitUpstream('new');
        $tester = $this->tester();
        $this->command->exitCodes = ['ds-upgrade-all' => 1];

        $this->assertSame(1, $tester->execute(['--yes' => true]));
        $this->assertSame(['git merge', 'dev-update.sh', 'ds-upgrade-all'], $this->stepNames());
    }

    public function testOwnJobIsIgnoredButAnotherJobBlocks(): void
    {
        $this->fx->commitUpstream('new');
        $this->runningJob('20261017-115800-aaaaaa');

        $tester = $this->tester();
        $this->assertSame(1, $tester->execute(['--yes' => true]));
        $this->assertStringContainsString('Dashboard job 20261017-115800-aaaaaa (reseed) is running', $tester->getDisplay());
        $this->assertSame([], $this->command->steps);

        $tester = $this->tester();
        $this->assertSame(0, $tester->execute(['--yes' => true, '--job' => '20261017-115800-aaaaaa']));
        $this->assertSame('git merge', $this->stepNames()[0]);
    }

    public function testWithoutYesNeedsATerminal(): void
    {
        $this->fx->commitUpstream('new');
        $before = $this->fx->headOf($this->fx->checkout);
        $tester = $this->tester();

        $this->assertSame(1, $tester->execute([], ['interactive' => false]));
        $this->assertStringContainsString('pass --yes', $tester->getDisplay());
        $this->assertSame([], $this->command->steps);
        $this->assertSame($before, $this->fx->headOf($this->fx->checkout));

        // Terminál, odpověď „n“ → nic.
        $tester = $this->tester();
        $this->command->terminal = true;
        $tester->setInputs(['n']);
        $this->assertSame(1, $tester->execute([], ['interactive' => true]));
        $this->assertStringContainsString('Aborted', $tester->getDisplay());
        $this->assertSame([], $this->command->steps);

        // Terminál, odpověď „y“ → aktualizace.
        $tester = $this->tester();
        $this->command->terminal = true;
        $tester->setInputs(['y']);
        $this->assertSame(0, $tester->execute([], ['interactive' => true]));
        $this->assertSame('git merge', $this->stepNames()[0]);
    }
}
