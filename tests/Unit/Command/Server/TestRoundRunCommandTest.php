<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\TestRoundRunCommand;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobStep;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundResult;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundSelection;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class TestableTestRoundRunCommand extends TestRoundRunCommand
{
    public ?RoundSelection $selection = null;
    public ?JobRecord $record = null;
    public string $outcome = RoundResult::STEP_OK;

    public function __construct(private readonly JobStore $jobs)
    {
        parent::__construct();
    }

    protected function store(string $jobsDir): JobStore
    {
        return $this->jobs;
    }

    protected function runRound(JobRecord $record, RoundSelection $selection, JobStore $store, OutputInterface $log): RoundResult
    {
        $this->record = $record;
        $this->selection = $selection;
        $result = new RoundResult($selection->toArray(), ['commit' => 'abc', 'branch' => 'stable', 'dirty' => false], '2026-10-27T12:00:00Z', $record->id);
        $result->recordStep('code', 'phpunit', 'PHPUnit', null, null, $this->outcome, '2026-10-27T12:01:00Z', $this->outcome === RoundResult::STEP_OK ? null : 'x');
        $result->finish('2026-10-27T12:01:00Z', $this->outcome === RoundResult::STEP_CANCELLED);
        $store->writeResult($record->id, $result->toArray());
        $log->writeln('==> kolo ' . $result->status());
        return $result;
    }
}

/** Skrytý orchestrátor kola: čte `job.json` úlohy `round`, odmítá cizí adresáře a typy, vrací exit kola. */
final class TestRoundRunCommandTest extends TestCase
{
    private string $jobsDir;
    private JobStore $store;

    protected function setUp(): void
    {
        $this->jobsDir = sys_get_temp_dir() . '/shpd_round_run_' . bin2hex(random_bytes(6)) . '/dev-jobs';
        mkdir($this->jobsDir, 0700, true);
        $this->store = new JobStore($this->jobsDir, static fn (): bool => true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir(dirname($this->jobsDir));
    }

    private function runCommand(TestableTestRoundRunCommand $cmd, string $jobDir): array
    {
        $app = new Application();
        $app->add($cmd);
        $tester = new CommandTester($app->find('test-round-run'));
        $exit = $tester->execute(['jobDir' => $jobDir], ['capture_stderr_separately' => true, 'interactive' => false]);
        return [$exit, $tester->getDisplay() . $tester->getErrorOutput()];
    }

    public function testRunsTheRoundFromJobJsonAndReturnsItsExitCode(): void
    {
        $record = new JobRecord('20261027-120000-aaaaaa', JobRecord::TYPE_ROUND, '', '', null, 'baseline', ['layers' => ['code'], 'eval' => ['variant' => 'baseline', 'repeat' => 1, 'maxCostUsd' => 1.0], 'baseUrl' => 'http://127.0.0.1', 'origin' => 'cli'], [new JobStep('test-round-run', ['true'], '/repo')], '2026-10-27T12:00:00Z', null, 960);
        $this->store->create($record);
        $cmd = new TestableTestRoundRunCommand($this->store);

        [$exit, $out] = $this->runCommand($cmd, $this->jobsDir . '/20261027-120000-aaaaaa');

        $this->assertSame(0, $exit);
        $this->assertSame(['code'], $cmd->selection?->layers);
        $this->assertSame('baseline', $cmd->selection?->eval?->variant);
        $this->assertSame('20261027-120000-aaaaaa', $cmd->record?->id);
        $this->assertSame('ok', $this->store->result('20261027-120000-aaaaaa')['status'] ?? null);
        $this->assertStringContainsString('==> kolo ok', $out);

        $cmd->outcome = RoundResult::STEP_FAILED;
        [$exit] = $this->runCommand($cmd, $this->jobsDir . '/20261027-120000-aaaaaa');
        $this->assertSame(1, $exit);
        $cmd->outcome = RoundResult::STEP_CANCELLED;
        [$exit] = $this->runCommand($cmd, $this->jobsDir . '/20261027-120000-aaaaaa');
        $this->assertSame(130, $exit);
    }

    public function testRefusesForeignDirectoriesAndOtherJobTypes(): void
    {
        $cmd = new TestableTestRoundRunCommand($this->store);
        [$exit, $out] = $this->runCommand($cmd, $this->jobsDir . '/nope');
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('Not a job directory', $out);

        $this->store->create(new JobRecord('20261027-120000-bbbbbb', JobRecord::TYPE_VERIFY, 'aaaa-bbbb-cccc-dddd', 'DS', 'cz-vat-payer', null, [], [new JobStep('v', ['true'], '/x')], '2026-10-27T12:00:00Z', null, 60));
        [$exit, $out] = $this->runCommand($cmd, $this->jobsDir . '/20261027-120000-bbbbbb');
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('is not a test round', $out);
        $this->assertNull($cmd->record);
    }

    public function testHiddenAndSignalContract(): void
    {
        $cmd = new TestableTestRoundRunCommand($this->store);
        $this->assertTrue($cmd->isHidden());
        $this->assertSame(\defined('SIGTERM') ? [\SIGTERM, \SIGINT] : [], $cmd->getSubscribedSignals());
        $this->assertFalse($cmd->handleSignal(15));
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
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
