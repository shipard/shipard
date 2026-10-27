<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Cli\BinPaths;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\DevJobs\JobLauncher;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Core\DevUpdate\Git;
use Shipard\Core\Server\DataSourceInventory;
use Shipard\Core\Utils\IdGenerator;
use Shipard\Module\Core\Exchange\Dataset\DatasetCatalog;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2ePreflight;
use Shipard\Module\Core\Exchange\Dataset\Jobs\DatasetJobRecipes;
use Shipard\Module\Core\Exchange\Dataset\Round\ChildJobGateway;
use Shipard\Module\Core\Exchange\Dataset\Round\CodeStep;
use Shipard\Module\Core\Exchange\Dataset\Round\CodeStepProcess;
use Shipard\Module\Core\Exchange\Dataset\Round\CodeSteps;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundException;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundPlan;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundPlanner;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundResult;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundRunner;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundSelection;
use Shipard\Module\Core\Exchange\Dataset\Round\TeeOutput;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoPreflight;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoScenarioCatalog;
use Shipard\Module\Core\Mail\Eval\Browse\EvalCatalog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `shpd-server test-round-run <adresář úlohy>` — **skrytý** orchestrátor
 * testovacího kola (tasks/testing-phase8a.md § 1, D130–D134): jediný krok
 * úlohy `round`, kterou založil `test-round` nebo dashboard
 * ({@see \Shipard\Module\Core\Exchange\Dataset\Round\RoundJobFactory}).
 * Výběr čte z `job.json` → `params`, rozvrh sestaví {@see RoundPlanner},
 * běh řídí {@see RoundRunner}: kroky kódu jako podprocesy, kroky na
 * zdrojích dat jako dílčí úlohy s `parent`. Souhrn píše průběžně do
 * `result.json` úlohy (N2), log na stderr (= `output.log`). Exit 0 jen při
 * samých `ok`, 1 jinak, 130 po zrušení (SIGTERM z obalu / kaskády API).
 *
 * Z terminálu se nepouští — bez obalu `dev-job-run` by nebyl `state.json`
 * ani živost a dashboard by kolo neviděl; k tomu je `test-round`.
 */
class TestRoundRunCommand extends Command implements SignalableCommandInterface
{
    private ?RoundRunner $runner = null;
    private bool $cancelRequested = false;

    protected function configure(): void
    {
        $this->setName('test-round-run')
            ->setHidden(true)
            ->setDescription('Internal: orchestrate one test round inside its dashboard job (started by test-round or the dashboard, not by hand)')
            ->addArgument('jobDir', InputArgument::REQUIRED, 'Job directory (<run dir>/dev-jobs/<id>)');
    }

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        return \defined('SIGTERM') && \defined('SIGINT') ? [\SIGTERM, \SIGINT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->requestCancel();
        return false;
    }

    /** Žádost o zrušení (cesta signálu i test seam). */
    public function requestCancel(): void
    {
        $this->cancelRequested = true;
        $this->runner?->requestCancel();
    }

    // ── Seamy ──────────────────────────────────────────────────────────────

    protected function store(string $jobsDir): JobStore
    {
        return new JobStore($jobsDir);
    }

    protected function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    protected function loadServerConfig(): ?ServerConfig
    {
        try {
            $config = new ServerConfig();
            $config->load();
            return $config;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Celé kolo nad hotovým záznamem; seam — test dodá výsledek bez
     * katalogů, procesů a dílčích úloh.
     */
    protected function runRound(JobRecord $record, RoundSelection $selection, JobStore $store, OutputInterface $log): RoundResult
    {
        $repoRoot = $this->repoRoot();
        $config = $this->loadServerConfig();
        $dataSourcesDir = $config?->getDataSourcesDir() ?? '/opt/shipard/data-sources';
        $gotenberg = $config?->getRender()?->url;
        $git = new Git($repoRoot);
        $gitInfo = ['commit' => $git->headHash(), 'branch' => $git->branch(), 'dirty' => $git->isDirty()];
        $writeResult = static function (array $snapshot) use ($store, $record): void {
            $store->writeResult($record->id, $snapshot);
        };

        $planner = new RoundPlanner(
            new DataSourceInventory($dataSourcesDir),
            new EvalCatalog($repoRoot),
            new DatasetCatalog($repoRoot),
            new VideoScenarioCatalog($repoRoot),
            new CodeSteps($repoRoot),
            static fn (): array => (new E2ePreflight($repoRoot))->toolProblems(),
            static function () use ($repoRoot, $gotenberg): array {
                $problems = (new VideoPreflight($repoRoot))->toolProblems();
                if (!is_string($gotenberg) || trim($gotenberg) === '') {
                    $problems[] = 'Gotenberg není nastavený (render.url v /etc/shipard/server.json) — PDF demo sady se bez něj nevyrobí';
                }
                return $problems;
            },
        );
        try {
            $plan = $planner->plan($selection);
        } catch (RoundException $e) {
            // Mezi spuštěním a během se změnil checkout (sada / scénář zmizely) — kolo nemá co dělat.
            $plan = new RoundPlan([], [], [], [], [], []);
            $result = new RoundResult($selection->toArray(), $gitInfo, JobStore::formatTime($this->now()), $record->id);
            $result->recordStep('code', 'plan', 'rozvrh kola', null, null, RoundResult::STEP_FAILED, JobStore::formatTime($this->now()), $e->getMessage());
            $result->finish(JobStore::formatTime($this->now()), false);
            $writeResult($result->toArray());
            $log->writeln('<error>' . $e->getMessage() . '</error>');
            return $result;
        }

        $variantPath = $selection->eval === null ? null : (new EvalCatalog($repoRoot))->variantStore()->pathFor($selection->eval->variant);
        $recipes = new DatasetJobRecipes(
            BinPaths::shpdServerCommand(),
            BinPaths::shpdDsCommand(),
            $repoRoot,
            $dataSourcesDir,
            $store,
            fn (): \DateTimeImmutable => $this->now(),
            $record->clientIp,
            $record->id,
        );
        $home = E2ePreflight::homeDir();
        $runCodeStep = static function (CodeStep $step, \Closure $shouldCancel) use ($log, $home): array {
            $tee = new TeeOutput($log);
            try {
                $run = (new CodeStepProcess($step->argv, $step->cwd, $shouldCancel))->run(CodeStepProcess::environment($home), $tee);
            } catch (\RuntimeException $e) {
                return ['exitCode' => null, 'termSignal' => null, 'cancelled' => false, 'durationMs' => 0, 'output' => $e->getMessage() . "\n"];
            }
            return $run + ['output' => $tee->captured()];
        };
        $this->runner = new RoundRunner(
            $plan,
            $selection,
            $recipes,
            ChildJobGateway::real($store, new JobLauncher($store)),
            $log,
            $writeResult,
            $runCodeStep,
            static fn (): string => (new IdGenerator())->generate($dataSourcesDir),
            fn (): \DateTimeImmutable => $this->now(),
            static function (float $seconds): void {
                usleep((int) round($seconds * 1_000_000));
            },
            $gitInfo,
            $record->id,
            $variantPath,
        );
        if ($this->cancelRequested) {
            $this->runner->requestCancel();
        }
        return $this->runner->run();
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    // ── Běh ────────────────────────────────────────────────────────────────

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $log = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $jobDir = rtrim((string) $input->getArgument('jobDir'), '/');
        $id = basename($jobDir);
        if (!JobRecord::isValidId($id) || !is_file($jobDir . '/' . JobStore::JOB_FILE)) {
            $log->writeln("<error>Not a job directory: {$jobDir}</error>");
            return Command::INVALID;
        }
        $store = $this->store(dirname($jobDir));
        $record = $store->read($id);
        if ($record === null || $record->type !== JobRecord::TYPE_ROUND) {
            $log->writeln("<error>Job {$id} is not a test round</error>");
            return Command::INVALID;
        }
        try {
            $selection = RoundSelection::fromArray($record->params);
        } catch (\InvalidArgumentException $e) {
            $log->writeln('<error>Invalid round selection in job.json: ' . $e->getMessage() . '</error>');
            return Command::INVALID;
        }
        $result = $this->runRound($record, $selection, $store, $log);
        return $result->exitCode();
    }
}
