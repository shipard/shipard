<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Cli\BinPaths;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\DevJobs\JobConflict;
use Shipard\Core\DevJobs\JobLauncher;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobStatus;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Core\Server\DataSourceInventory;
use Shipard\Module\Core\Exchange\Dataset\DatasetCatalog;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2eException;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2ePreflight;
use Shipard\Module\Core\Exchange\Dataset\E2e\LocalBaseUrl;
use Shipard\Module\Core\Exchange\Dataset\Round\CodeSteps;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundDataSources;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundEstimate;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundEvalOptions;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundException;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundJobFactory;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundPlan;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundPlanner;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundPreflight;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundReport;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundResult;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundSelection;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoPreflight;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoScenarioCatalog;
use Shipard\Module\Core\Mail\Eval\Browse\EvalCatalog;
use Shipard\Module\Core\Mail\Eval\EvalEstimateRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `shpd-server test-round` (tasks/testing-phase8a.md D130–D134) — celé
 * testovací kolo jedním příkazem: kód → veřejné sady → videa, volitelně
 * evaluace (D131, jen s `--eval` a stropem `--max-cost`).
 *
 * Kolo **vždy běží jako úloha dashboardu** (`round`, N1–N3): příkaz udělá
 * preflight (režim `development`, vlastník adresáře úloh, lokální
 * `--base-url`, známé sady a scénáře, odhad ceny evaluací), založí úlohu
 * přes {@see JobLauncher} (druhé kolo nebo `update` = odmítnutí) a bez
 * `--detach` ji **sleduje**: každou sekundu čte `result.json` kola, vypisuje
 * přechody kroků, po skončení tabulku ({@see RoundReport}) a vrátí exit
 * kola (0 jen při samých `ok`, 1 jinak, 130 po zrušení). První Ctrl+C
 * kolo zruší (kaskáda na dílčí úlohy) a sleduje dál, druhé odpojí
 * terminál — kolo běží dál, `--follow <id>` ho znovu připojí. `-v`
 * streamuje i `output.log`. Kolo z terminálu je vidět v dashboardu.
 *
 * Exit 2 = kolo nezačalo (preflight, limit ceny, běžící kolo).
 */
class TestRoundCommand extends Command implements SignalableCommandInterface
{
    public const int EXIT_PROBLEMS = 1;
    public const int EXIT_REFUSED = 2;
    public const int EXIT_DETACHED = 130;
    public const float POLL_SECONDS = 1.0;

    private int $interrupts = 0;
    private ?string $followingJobId = null;

    protected function configure(): void
    {
        $this->setName('test-round')
            ->setDescription('Run the whole test round as a dashboard job and follow it: code tests, public datasets (verify + walkthrough), video scenarios; evaluation only with --eval and --max-cost (development only)')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Layers to run, comma-separated: code, datasets, videos (default all)')
            ->addOption('dataset', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only this public dataset (name from tests/datasets, or a directory with manifest.jsonc); repeatable')
            ->addOption('scenario', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only this video scenario id (demo/scenarios); repeatable')
            ->addOption('eval', null, InputOption::VALUE_REQUIRED, 'Also run the AI evaluation with this variant (tests/eval/variants) — costs money, requires --max-cost')
            ->addOption('repeat', null, InputOption::VALUE_REQUIRED, 'Evaluation repeats per dataset (1–' . RoundEvalOptions::MAX_REPEAT . ')', '1')
            ->addOption('max-cost', null, InputOption::VALUE_REQUIRED, 'Spend cap in USD for all evaluations of the round; the round does not start when the estimate exceeds it')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Local origin of this server for the walkthrough and videos', RoundSelection::DEFAULT_BASE_URL)
            ->addOption('detach', null, InputOption::VALUE_NONE, 'Start the round and print its job id instead of following it')
            ->addOption('follow', null, InputOption::VALUE_REQUIRED, 'Follow an already running round by job id (no new round)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the final result.json instead of the table');
    }

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        return \defined('SIGINT') && \defined('SIGTERM') ? [\SIGINT, \SIGTERM] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->interrupts++;
        if ($this->interrupts >= 2 && $this->followingJobId !== null) {
            return self::EXIT_DETACHED;
        }
        return false;
    }

    /** Test seam místo signálu. */
    public function interrupt(): void
    {
        $this->interrupts++;
    }

    // ── Seamy ──────────────────────────────────────────────────────────────

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

    protected function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    protected function jobStore(): JobStore
    {
        return new JobStore(JobStore::defaultDir());
    }

    protected function jobLauncher(JobStore $store): JobLauncher
    {
        return new JobLauncher($store);
    }

    /** @return list<string> */
    protected function serverCommand(): array
    {
        return BinPaths::shpdServerCommand();
    }

    protected function createLocalBaseUrl(): LocalBaseUrl
    {
        return new LocalBaseUrl();
    }

    protected function jobsDirProblem(JobStore $store): ?string
    {
        return RoundPreflight::jobsDirProblem($store, function_exists('posix_geteuid') ? posix_geteuid() : 0);
    }

    /** Rozvrh jen kvůli ověření výběru a výpisu, co kolo udělá (neznámá sada / scénář = RoundException). */
    protected function plan(RoundSelection $selection, string $dataSourcesDir, ?string $gotenbergUrl): RoundPlan
    {
        $repoRoot = $this->repoRoot();
        return (new RoundPlanner(
            new DataSourceInventory($dataSourcesDir),
            new EvalCatalog($repoRoot),
            new DatasetCatalog($repoRoot),
            new VideoScenarioCatalog($repoRoot),
            new CodeSteps($repoRoot),
            static fn (): array => (new E2ePreflight($repoRoot))->toolProblems(),
            static function () use ($repoRoot, $gotenbergUrl): array {
                $problems = (new VideoPreflight($repoRoot))->toolProblems();
                if (!is_string($gotenbergUrl) || trim($gotenbergUrl) === '') {
                    $problems[] = 'Gotenberg není nastavený (render.url v /etc/shipard/server.json)';
                }
                return $problems;
            },
        ))->plan($selection);
    }

    /**
     * Odhad ceny evaluací per sada (D131); null = odhad nejde spočítat
     * (věta v `problems`). Doplní fáze evaluace v kole.
     *
     * @return array{total: float, rows: list<array{dataset: string, total: ?float, calls: ?int}>, problems: list<string>}
     */
    protected function estimate(RoundPlan $plan, RoundEvalOptions $eval, string $dataSourcesDir): array
    {
        $variantPath = (new EvalCatalog($this->repoRoot()))->variantStore()->pathFor($eval->variant);
        return (new RoundEstimate(new EvalEstimateRunner(BinPaths::shpdDsCommand()), $dataSourcesDir))->forPlan($plan, $eval, $variantPath);
    }

    protected function sleep(float $seconds): void
    {
        usleep((int) round($seconds * 1_000_000));
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    // ── Běh ────────────────────────────────────────────────────────────────

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $json = (bool) $input->getOption('json');
        $config = $this->loadServerConfig();
        $problem = RoundPreflight::modeProblem($config?->getMode());
        if ($problem !== null) {
            return $this->refuse($err, $problem);
        }
        $store = $this->jobStore();
        $problem = $this->jobsDirProblem($store);
        if ($problem !== null) {
            return $this->refuse($err, $problem);
        }
        $launcher = $this->jobLauncher($store);

        $follow = $input->getOption('follow');
        if (is_string($follow) && $follow !== '') {
            $record = JobRecord::isValidId($follow) ? $store->read($follow) : null;
            if ($record === null || $record->type !== JobRecord::TYPE_ROUND) {
                return $this->refuse($err, "Úloha '{$follow}' není testovací kolo");
            }
            return $this->follow($record->id, $store, $launcher, $output, $err, $json, $output->isVerbose());
        }

        try {
            $selection = $this->selectionFromInput($input);
        } catch (\InvalidArgumentException $e) {
            return $this->refuse($err, $e->getMessage());
        }
        try {
            $this->createLocalBaseUrl()->verify($selection->baseUrl);
        } catch (E2eException $e) {
            return $this->refuse($err, $e->getMessage());
        }
        $dataSourcesDir = $config?->getDataSourcesDir() ?? '/opt/shipard/data-sources';
        try {
            $plan = $this->plan($selection, $dataSourcesDir, $config?->getRender()?->url);
        } catch (RoundException $e) {
            return $this->refuse($err, $e->getMessage());
        }
        $this->describePlan($plan, $selection, $err);

        if ($selection->eval !== null) {
            $estimate = $this->estimate($plan, $selection->eval, $dataSourcesDir);
            foreach ($estimate['rows'] as $row) {
                $err->writeln(sprintf('  odhad %s: %s USD%s', $row['dataset'], $row['total'] === null ? '—' : number_format($row['total'], 4), $row['calls'] !== null ? " ({$row['calls']} volání)" : ''));
            }
            if ($estimate['problems'] !== []) {
                return $this->refuse($err, 'Odhad ceny evaluací nejde spočítat: ' . implode(' ', $estimate['problems']));
            }
            $err->writeln(sprintf('  odhad celkem %s USD, strop %s USD', number_format($estimate['total'], 4), number_format($selection->eval->maxCostUsd, 4)));
            if ($estimate['total'] > $selection->eval->maxCostUsd) {
                return $this->refuse($err, sprintf('Odhad %s USD je nad stropem %s USD — kolo nezačne (zvyš --max-cost, nebo zúž --dataset / --repeat)', number_format($estimate['total'], 4), number_format($selection->eval->maxCostUsd, 4)));
            }
        }

        $record = RoundJobFactory::record($selection, $this->serverCommand(), $this->repoRoot(), $store, $this->now(), null, RoundJobFactory::ORIGIN_CLI);
        try {
            $view = $launcher->launch($record);
        } catch (JobConflict $e) {
            return $this->refuse($err, 'Kolo nejde spustit: ' . $e->getMessage() . ' (sleduj: shpd-server test-round --follow ' . $e->runningJobId . ')');
        } catch (\RuntimeException $e) {
            return $this->refuse($err, 'Kolo nejde spustit: ' . $e->getMessage());
        }
        if (($view['status'] ?? null) === JobStatus::FAILED) {
            return $this->refuse($err, 'Obal úlohy se nespustil: ' . (string) ($view['error'] ?? 'setsid / proc_open'));
        }
        $err->writeln("<info>Kolo {$record->id} spuštěno</info> (úloha dashboardu; log {$store->jobDir($record->id)}/output.log)");
        if ((bool) $input->getOption('detach')) {
            $output->writeln($record->id);
            return Command::SUCCESS;
        }
        return $this->follow($record->id, $store, $launcher, $output, $err, $json, $output->isVerbose());
    }

    /** @throws \InvalidArgumentException */
    private function selectionFromInput(InputInterface $input): RoundSelection
    {
        $only = $input->getOption('only');
        $layers = is_string($only) && trim($only) !== '' ? RoundSelection::parseLayers($only) : RoundSelection::LAYERS;
        if ($layers === []) {
            throw new \InvalidArgumentException('--only musí jmenovat aspoň jednu vrstvu: ' . implode(', ', RoundSelection::LAYERS));
        }
        $eval = null;
        $variant = $input->getOption('eval');
        if (is_string($variant) && $variant !== '') {
            $maxCost = $input->getOption('max-cost');
            if (!is_numeric($maxCost)) {
                throw new \InvalidArgumentException('--eval vyžaduje --max-cost <USD> — strop útraty za evaluace celého kola (D131)');
            }
            $eval = RoundEvalOptions::fromArray(['variant' => $variant, 'repeat' => $input->getOption('repeat'), 'maxCostUsd' => $maxCost]);
        } elseif (is_numeric($input->getOption('max-cost'))) {
            throw new \InvalidArgumentException('--max-cost má smysl jen s --eval <varianta>');
        }
        $datasets = array_values(array_map(static fn ($d): string => (string) $d, (array) $input->getOption('dataset')));
        $scenarios = array_values(array_map(static fn ($s): string => (string) $s, (array) $input->getOption('scenario')));
        return new RoundSelection($layers, $datasets, $scenarios, $eval, (string) $input->getOption('base-url'));
    }

    private function describePlan(RoundPlan $plan, RoundSelection $selection, OutputInterface $err): void
    {
        $err->writeln('<comment>Testovací kolo</comment>: vrstvy ' . implode(', ', $selection->layers));
        if ($plan->codeSteps !== []) {
            $err->writeln(sprintf('  kód: %d kroků', count($plan->codeSteps)));
        }
        foreach ($plan->datasets as $dataset) {
            $err->writeln(sprintf('  sada %s → zdroj dat %s', $dataset->name, $dataset->needsCreate() ? 'založí kolo (' . RoundDataSources::nameFor($dataset->title) . ')' : $dataset->dataSourceId . ' (' . $dataset->dataSourceName . ')'));
        }
        foreach ($plan->videos as $video) {
            $err->writeln(sprintf('  videa %s (%d scénářů) → zdroj dat %s', $video->dataset, count($video->scenarios), $video->needsCreate() ? 'založí kolo' : $video->dataSourceId));
        }
        foreach ($plan->invalidScenarios as $scenario) {
            $err->writeln("  scénář {$scenario->id}: <error>{$scenario->problem}</error>");
        }
        foreach ([...$plan->e2eProblems, ...$plan->videoProblems] as $problem) {
            $err->writeln("  <comment>{$problem}</comment>");
        }
    }

    private function follow(string $id, JobStore $store, JobLauncher $launcher, OutputInterface $output, OutputInterface $err, bool $json, bool $stream): int
    {
        $this->followingJobId = $id;
        $printed = [];
        $cancelSent = false;
        $logOffset = 0;
        $logPath = $store->path($id, JobStore::OUTPUT_FILE);
        $result = null;
        while (true) {
            if ($this->interrupts >= 2) {
                $err->writeln("<comment>Odpojeno — kolo {$id} běží dál; znovu připojit: shpd-server test-round --follow {$id}</comment>");
                return self::EXIT_DETACHED;
            }
            if ($this->interrupts >= 1 && !$cancelSent) {
                $cancelSent = true;
                $launcher->cancel($id);
                $err->writeln('<comment>Ruším kolo (běžící dílčí úloha dostane signál, zbytek se přeskočí); druhé Ctrl+C odpojí terminál.</comment>');
            }
            $view = $store->viewOf($id);
            $result = $store->result($id);
            if ($stream) {
                $logOffset = $this->streamLog($logPath, $logOffset, $err);
            }
            foreach ((array) ($result['steps'] ?? []) as $i => $step) {
                if (!is_array($step)) {
                    continue;
                }
                $key = $i . ':' . ($step['status'] ?? '');
                if (isset($printed[$key])) {
                    continue;
                }
                $printed[$key] = true;
                if (!$stream) {
                    $err->writeln(RoundReport::stepLine($step));
                }
            }
            if ($view === null) {
                return $this->refuse($err, "Úloha {$id} zmizela z úložiště");
            }
            if (($view['active'] ?? false) !== true) {
                break;
            }
            $this->sleep(self::POLL_SECONDS);
        }
        if (!is_array($result) || ($result['status'] ?? RoundResult::STATUS_RUNNING) === RoundResult::STATUS_RUNNING) {
            $err->writeln(sprintf('<error>Kolo %s skončilo bez souhrnu (stav úlohy %s%s)</error>', $id, (string) ($view['status'] ?? '?'), isset($view['statusReason']) ? ', ' . $view['statusReason'] : ''));
            return ($view['status'] ?? null) === JobStatus::CANCELLED ? RoundResult::EXIT_CANCELLED : self::EXIT_PROBLEMS;
        }
        if ($json) {
            $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            RoundReport::writeText($result, $output);
        }
        return is_int($result['exitCode'] ?? null) ? (int) $result['exitCode'] : self::EXIT_PROBLEMS;
    }

    /** Dopíše nové bajty `output.log` od offsetu (jen celé řádky). */
    private function streamLog(string $path, int $offset, OutputInterface $err): int
    {
        if (!is_file($path)) {
            return $offset;
        }
        $size = (int) filesize($path);
        if ($size <= $offset) {
            return $offset;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return $offset;
        }
        try {
            fseek($handle, $offset);
            $chunk = (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
        $lastNl = strrpos($chunk, "\n");
        if ($lastNl === false) {
            return $offset;
        }
        $err->write(substr($chunk, 0, $lastNl + 1), false, OutputInterface::OUTPUT_RAW);
        return $offset + $lastNl + 1;
    }

    private function refuse(OutputInterface $err, string $message): int
    {
        $err->writeln('<error>' . $message . '</error>');
        return self::EXIT_REFUSED;
    }
}
