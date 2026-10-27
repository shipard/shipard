<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\TestRoundCommand;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\DevJobs\JobLauncher;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobState;
use Shipard\Core\DevJobs\JobStatus;
use Shipard\Core\DevJobs\JobStep;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Module\Core\Exchange\Dataset\Round\CodeStep;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundDatasetPlan;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundEvalOptions;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundException;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundPlan;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundResult;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundSelection;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class TestableTestRoundCommand extends TestRoundCommand
{
    public ?string $dirProblem = null;
    public ?\Closure $planner = null;
    /** @var array{total: float, rows: list<array{dataset: string, total: ?float, calls: ?int}>, problems: list<string>} */
    public array $estimateResult = ['total' => 0.0, 'rows' => [], 'problems' => []];
    public ?RoundEvalOptions $estimatedWith = null;
    public ?\Closure $onSleep = null;
    public int $sleeps = 0;
    /** @var list<array{argv: list<string>, cwd: string}> */
    public array $spawns = [];
    /** @var list<array{0: int, 1: int}> */
    public array $kills = [];
    public ?RoundSelection $lastSelection = null;

    public ?ServerConfig $config = null;

    public function __construct(private readonly JobStore $jobs, private readonly string $repo)
    {
        parent::__construct();
    }

    protected function loadServerConfig(): ?ServerConfig
    {
        return $this->config;
    }

    protected function repoRoot(): string
    {
        return $this->repo;
    }

    protected function jobStore(): JobStore
    {
        return $this->jobs;
    }

    protected function jobLauncher(JobStore $store): JobLauncher
    {
        return new JobLauncher(
            $store,
            function (array $argv, string $cwd, string $log): bool {
                $this->spawns[] = ['argv' => $argv, 'cwd' => $cwd];
                return true;
            },
            function (int $pid, int $signal): bool {
                $this->kills[] = [$pid, $signal];
                return true;
            },
            ['/usr/bin/php', '/repo/bin/shpd-server'],
        );
    }

    protected function serverCommand(): array
    {
        return ['/usr/bin/php', '/repo/bin/shpd-server'];
    }

    protected function jobsDirProblem(JobStore $store): ?string
    {
        return $this->dirProblem;
    }

    protected function plan(RoundSelection $selection, string $dataSourcesDir, ?string $gotenbergUrl): RoundPlan
    {
        $this->lastSelection = $selection;
        if ($this->planner !== null) {
            return ($this->planner)($selection);
        }
        return new RoundPlan([new CodeStep('phpunit', 'PHPUnit', ['php', 'phpunit'], $this->repo, CodeStep::PARSER_PHPUNIT)], [new RoundDatasetPlan('cz-non-payer', 'Neplátce', 'cz', '/d', null, null)], [], [], [], []);
    }

    protected function estimate(RoundPlan $plan, RoundEvalOptions $eval, string $dataSourcesDir): array
    {
        $this->estimatedWith = $eval;
        return $this->estimateResult;
    }

    protected function sleep(float $seconds): void
    {
        $this->sleeps++;
        if ($this->onSleep !== null) {
            ($this->onSleep)($this->sleeps);
        }
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-27T12:00:00Z');
    }
}

/**
 * `shpd-server test-round` (D130–D134): preflight (režim, adresář úloh,
 * výběr, `--eval` jen se stropem, odhad vs. strop), založení úlohy `round`
 * přes launcher, `--detach`, sledování s výpisem a tabulkou, Ctrl+C
 * = zrušení, podruhé odpojení, `--follow`, běžící kolo = odmítnutí.
 */
final class TestRoundCommandTest extends TestCase
{
    private string $base;
    private JobStore $store;
    private TestableTestRoundCommand $cmd;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/shpd_test_round_' . bin2hex(random_bytes(6));
        mkdir($this->base . '/dev-jobs', 0700, true);
        $this->store = new JobStore($this->base . '/dev-jobs', static fn (): bool => true, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-27T12:00:00Z'));
        $this->cmd = $this->command('development');
    }

    private function command(string $mode): TestableTestRoundCommand
    {
        $cmd = new TestableTestRoundCommand($this->store, '/repo');
        $config = $this->createMock(ServerConfig::class);
        $config->method('getMode')->willReturn($mode);
        $config->method('getDataSourcesDir')->willReturn('/opt/ds');
        $cmd->config = $config;
        return $cmd;
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->base);
    }

    /** @param array<string, mixed> $args @return array{0: int, 1: string, 2: string} exit, stdout, stderr */
    private function runCommand(array $args, bool $verbose = false): array
    {
        $app = new Application();
        $app->add($this->cmd);
        $tester = new CommandTester($app->find('test-round'));
        $exit = $tester->execute($args, ['capture_stderr_separately' => true, 'interactive' => false, 'verbosity' => $verbose ? 64 : 32]);
        return [$exit, $tester->getDisplay(), $tester->getErrorOutput()];
    }

    /** @return list<string> ids úloh v úložišti */
    private function jobIds(): array
    {
        return array_column($this->store->list(), 'id');
    }

    private function finishJob(string $id, string $status, string $roundStatus): void
    {
        $result = new RoundResult(['layers' => ['code']], ['commit' => 'abc12345', 'branch' => 'stable', 'dirty' => true], '2026-10-27T12:00:00Z', $id);
        $result->recordStep('code', 'phpunit', 'PHPUnit', null, null, $roundStatus === RoundResult::STATUS_OK ? RoundResult::STEP_OK : ($roundStatus === RoundResult::STATUS_CANCELLED ? RoundResult::STEP_CANCELLED : RoundResult::STEP_FAILED), '2026-10-27T12:00:01Z', $roundStatus === RoundResult::STATUS_OK ? null : 'něco');
        $result->finish('2026-10-27T12:00:02Z', $roundStatus === RoundResult::STATUS_CANCELLED);
        $this->store->writeResult($id, $result->toArray());
        $state = JobState::pending(1);
        $state->pid = 4242;
        $state->status = $status;
        $this->store->writeState($id, $state);
    }

    public function testRefusesOutsideDevelopmentAndWithBadJobsDir(): void
    {
        $this->cmd = $this->command('production');
        [$exit, , $err] = $this->runCommand([]);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('jen na serveru v režimu development', $err);

        $this->cmd = $this->command('development');
        $this->cmd->dirProblem = 'Adresář úloh patří jinému uživateli';
        [$exit, , $err] = $this->runCommand([]);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('jinému uživateli', $err);
        $this->assertSame([], $this->jobIds());
    }

    public function testEvalRequiresCapAndUnknownSelectionIsRefused(): void
    {
        [$exit, , $err] = $this->runCommand(['--eval' => 'baseline']);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('--eval vyžaduje --max-cost', $err);

        [$exit, , $err] = $this->runCommand(['--max-cost' => '1']);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('jen s --eval', $err);

        [$exit, , $err] = $this->runCommand(['--only' => 'code,tests']);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString("Unknown layer 'tests'", $err);

        $this->cmd->planner = static fn (): RoundPlan => throw new RoundException("Sada 'nope' není veřejná testovací sada");
        [$exit, , $err] = $this->runCommand(['--dataset' => ['nope']]);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString("Sada 'nope'", $err);
        $this->assertSame([], $this->jobIds(), 'nic se nespustilo');
    }

    public function testEstimateAboveCapRefusesAndProblemsRefuse(): void
    {
        $this->cmd->estimateResult = ['total' => 1.5, 'rows' => [['dataset' => 'cz-non-payer', 'total' => 1.5, 'calls' => 16]], 'problems' => []];
        [$exit, , $err] = $this->runCommand(['--only' => 'datasets', '--eval' => 'baseline', '--max-cost' => '1', '--repeat' => '2']);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('odhad cz-non-payer: 1.5000 USD (16 volání)', $err);
        $this->assertStringContainsString('nad stropem 1.0000 USD', $err);
        $this->assertSame(2, $this->cmd->estimatedWith?->repeat);
        $this->assertSame([], $this->jobIds());

        $this->cmd->estimateResult = ['total' => 0.0, 'rows' => [], 'problems' => ['zdroj dat sady neexistuje']];
        [$exit, , $err] = $this->runCommand(['--only' => 'datasets', '--eval' => 'baseline', '--max-cost' => '1']);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('Odhad ceny evaluací nejde spočítat: zdroj dat sady neexistuje', $err);
    }

    public function testDetachLaunchesTheRoundJobAndPrintsItsId(): void
    {
        [$exit, $out, $err] = $this->runCommand(['--detach' => true, '--only' => 'code,datasets', '--dataset' => ['cz-non-payer'], '--base-url' => 'http://127.0.0.1']);

        $this->assertSame(0, $exit);
        $ids = $this->jobIds();
        $this->assertCount(1, $ids);
        $this->assertSame($ids[0] . "\n", $out, 'jen id na stdout');
        $this->assertStringContainsString('spuštěno', $err);
        $this->assertStringContainsString('sada cz-non-payer → zdroj dat založí kolo (Testovací kolo — Neplátce)', $err);
        $record = $this->store->read($ids[0]);
        $this->assertSame(JobRecord::TYPE_ROUND, $record?->type);
        $this->assertSame('', $record?->dataSourceId);
        $this->assertSame(['code', 'datasets'], $record?->params['layers']);
        $this->assertSame(['cz-non-payer'], $record?->params['datasets']);
        $this->assertNull($record?->params['eval']);
        $this->assertSame('http://127.0.0.1', $record?->params['baseUrl']);
        $this->assertSame('cli', $record?->params['origin']);
        $this->assertSame(['/usr/bin/php', '/repo/bin/shpd-server', 'test-round-run', $this->base . '/dev-jobs/' . $ids[0]], $record?->steps[0]->argv);
        $this->assertSame('/repo', $record?->steps[0]->cwd);
        $this->assertSame(960, $record?->cancelGraceSeconds);
        $this->assertCount(1, $this->cmd->spawns);
        $this->assertSame('dev-job-run', $this->cmd->spawns[0]['argv'][2]);
        $this->assertSame(0, $this->cmd->sleeps, 'bez sledování');
    }

    public function testFollowPrintsStepsAndTableAndReturnsTheRoundExit(): void
    {
        $this->cmd->onSleep = function (int $n): void {
            $id = $this->jobIds()[0];
            if ($n === 1) {
                $result = new RoundResult(['layers' => ['code']], ['commit' => 'abc12345', 'branch' => 'stable', 'dirty' => false], '2026-10-27T12:00:00Z', $id);
                $result->startStep('code', 'phpunit', 'PHPUnit', null, null, '2026-10-27T12:00:01Z');
                $this->store->writeResult($id, $result->toArray());
                $state = JobState::pending(1);
                $state->pid = 4242;
                $this->store->writeState($id, $state);
            }
            if ($n === 2) {
                $this->finishJob($id, JobStatus::FAILED, RoundResult::STATUS_FAILED);
            }
        };

        [$exit, $out, $err] = $this->runCommand(['--only' => 'code']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('[kód · PHPUnit] : running', $err);
        $this->assertStringContainsString('[kód · PHPUnit] : failed', $err);
        $this->assertStringContainsString('Testovací kolo ' . $this->jobIds()[0], $out);
        $this->assertStringContainsString('necommitnuté změny', $out);
        $this->assertStringContainsString('PHPUnit', $out);
        $this->assertStringContainsString('Celkem: 0 ok, 1 selhalo, 0 přeskočeno, 0 zrušeno → exit 1', $out);
        $this->assertSame(2, $this->cmd->sleeps);
    }

    public function testFollowWithJsonPrintsTheResultAndFollowOptionAttaches(): void
    {
        $this->cmd->onSleep = function (int $n): void {
            if ($n === 1) {
                $this->finishJob($this->jobIds()[0], JobStatus::FINISHED, RoundResult::STATUS_OK);
            }
        };
        [$exit, $out] = $this->runCommand(['--only' => 'code', '--json' => true]);
        $this->assertSame(0, $exit);
        $decoded = json_decode($out, true);
        $this->assertSame(RoundResult::FORMAT, $decoded['format'] ?? null);
        $this->assertSame('ok', $decoded['status'] ?? null);

        // --follow na hotové kolo: tabulka hned, exit kola.
        $id = $this->jobIds()[0];
        $this->cmd->sleeps = 0;
        [$exit, $out] = $this->runCommand(['--follow' => $id]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Testovací kolo ' . $id, $out);
        $this->assertSame(0, $this->cmd->sleeps);

        [$exit, , $err] = $this->runCommand(['--follow' => '20261027-000000-nope00']);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('není testovací kolo', $err);
    }

    public function testRunningRoundRefusesASecondOneWithFollowHint(): void
    {
        $this->runCommand(['--detach' => true]);
        $id = $this->jobIds()[0];
        $state = JobState::pending(1);
        $state->pid = 4242;
        $this->store->writeState($id, $state);

        [$exit, , $err] = $this->runCommand(['--detach' => true]);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString("server-wide job {$id} (round) is running", $err);
        $this->assertStringContainsString("--follow {$id}", $err);
        $this->assertCount(1, $this->jobIds());
    }

    public function testInterruptCancelsTheRoundAndSecondInterruptDetaches(): void
    {
        $this->cmd->onSleep = function (int $n): void {
            $id = $this->jobIds()[0];
            if ($n === 1) {
                $state = JobState::pending(1);
                $state->pid = 4242;
                $this->store->writeState($id, $state);
                $this->cmd->interrupt();
            }
            if ($n === 3) {
                $this->finishJob($id, JobStatus::CANCELLED, RoundResult::STATUS_CANCELLED);
            }
        };

        [$exit, , $err] = $this->runCommand(['--only' => 'code']);

        $this->assertSame(130, $exit);
        $this->assertSame([[-4242, JobLauncher::SIGTERM]], $this->cmd->kills, 'první Ctrl+C = cancel kola (kaskáda na děti dělá launcher)');
        $this->assertStringContainsString('Ruším kolo', $err);
        $this->assertFileExists($this->base . '/dev-jobs/' . $this->jobIds()[0] . '/cancel.json');

        // Druhé přerušení odpojí terminál, kolo běží dál.
        $this->cmd = $this->command('development');
        $this->cmd->onSleep = function (int $n): void {
            if ($n === 1) {
                $state = JobState::pending(1);
                $state->pid = 4243;
                $this->store->writeState($this->jobIds()[0], $state);
                $this->cmd->interrupt();
                $this->cmd->interrupt();
            }
        };
        $this->store->prune(0);
        [$exit, , $err] = $this->runCommand(['--only' => 'code']);
        $this->assertSame(130, $exit);
        $this->assertStringContainsString('Odpojeno', $err);
        $this->assertStringContainsString('--follow', $err);
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
