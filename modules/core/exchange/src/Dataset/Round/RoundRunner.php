<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Round;

use Shipard\Core\DevJobs\JobConflict;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobStatus;
use Shipard\Module\Core\Exchange\Dataset\Jobs\DatasetJobRecipes;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoDataSources;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoReport;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Orchestrace testovacího kola (tasks/testing-phase8a.md § 1, D130–D134)
 * uvnitř jediného kroku úlohy `round`:
 *
 *   kód (podprocesy, {@see CodeStepProcess}) → pro každou sadu `create` nebo
 *   `reseed` → `verify` → `e2e` → [`reseed` → `eval` s rozpočtem] → pro každou
 *   demo sadu `create` zdroje videí → `video-run --verb check` per scénář.
 *
 * Kroky na zdrojích dat jsou **dílčí úlohy** ({@see ChildJobGateway},
 * recepty {@see DatasetJobRecipes} s `parent`), kolo na ně čeká pollingem;
 * obsazený zdroj dat ({@see JobConflict}) = krok `skipped` s důvodem, nečeká
 * se (D134). Selhání nezastaví kolo (D133); závislosti: po selhaném
 * `reseed` je `verify` téže sady `skipped`, `e2e` seeduje sám, `eval` má
 * vlastní `reseed`; čerstvě založený zdroj dat (`create` včetně seedu) první
 * `reseed` nahrazuje. Zrušení ({@see requestCancel()}): běžící krok kódu
 * dostane SIGTERM, běžící dílčí úloha `cancel` (kaskáda z API ji zasáhne
 * také — volání je idempotentní), zbytek `skipped`, stav `cancelled`.
 * Po každé změně se zapíše `result.json` ({@see RoundResult}, N2).
 */
final class RoundRunner
{
    public const float POLL_SECONDS = 1.0;
    /** Po žádosti o zrušení dítěte se čeká nejvýš jeho grace + tolik; pak se krok uzavře jako `cancelled` a kolo jde dál. */
    public const int CANCEL_EXTRA_WAIT_SECONDS = 30;
    public const string LAYER_CODE = RoundSelection::LAYER_CODE;
    public const string LAYER_DATASETS = RoundSelection::LAYER_DATASETS;
    public const string LAYER_VIDEOS = RoundSelection::LAYER_VIDEOS;
    public const string REASON_CANCELLED = 'kolo zrušeno';

    private bool $cancelRequested = false;
    private readonly RoundResult $result;

    /**
     * @param \Closure(array<string, mixed>): void                     $writeResult   `JobStore::writeResult` (nebo ticho)
     * @param \Closure(CodeStep, \Closure(): bool): array<string, mixed> $runCodeStep  `{exitCode, termSignal, cancelled, durationMs, output}`
     * @param \Closure(): string                                      $newDataSourceId
     * @param \Closure(): \DateTimeImmutable                          $now
     * @param \Closure(float): void                                   $sleep
     * @param array{commit: ?string, branch: ?string, dirty: ?bool}   $git
     */
    public function __construct(
        private readonly RoundPlan $plan,
        private readonly RoundSelection $selection,
        private readonly DatasetJobRecipes $recipes,
        private readonly ChildJobGateway $children,
        private readonly OutputInterface $log,
        private readonly \Closure $writeResult,
        private readonly \Closure $runCodeStep,
        private readonly \Closure $newDataSourceId,
        private readonly \Closure $now,
        private readonly \Closure $sleep,
        array $git,
        ?string $jobId,
        private readonly ?string $variantPath = null,
    ) {
        $this->result = new RoundResult($selection->toArray(), $git, $this->timestamp(), $jobId);
    }

    public function requestCancel(): void
    {
        $this->cancelRequested = true;
    }

    public function result(): RoundResult
    {
        return $this->result;
    }

    public function run(): RoundResult
    {
        $this->write();
        $this->codeLayer();
        $this->datasetsLayer();
        $this->videosLayer();
        $this->result->finish($this->timestamp(), $this->cancelRequested);
        $this->write();
        $this->log->writeln(sprintf('==> kolo %s (ok %d, selhalo %d, přeskočeno %d, zrušeno %d)', $this->result->status(), ...array_values(array_slice($this->result->summary(), 0, 4))));
        return $this->result;
    }

    // ── Vrstva kódu ────────────────────────────────────────────────────────

    private function codeLayer(): void
    {
        foreach ($this->plan->codeSteps as $step) {
            if ($this->cancelRequested) {
                $this->record(self::LAYER_CODE, $step->id, $step->label, null, null, RoundResult::STEP_SKIPPED, self::REASON_CANCELLED);
                continue;
            }
            if ($step->problem !== null) {
                $this->record(self::LAYER_CODE, $step->id, $step->label, null, null, RoundResult::STEP_FAILED, $step->problem);
                continue;
            }
            $index = $this->start(self::LAYER_CODE, $step->id, $step->label, null, null);
            $this->log->writeln("==> [kód] {$step->label}: " . implode(' ', $step->argv) . " (v {$step->cwd})");
            $run = ($this->runCodeStep)($step, fn (): bool => $this->cancelRequested);
            $parsed = CodeStepParser::parse($step->parser, (string) ($run['output'] ?? ''), $run['exitCode'] ?? null);
            $status = ($run['cancelled'] ?? false) || ($this->cancelRequested && ($run['termSignal'] ?? null) !== null)
                ? RoundResult::STEP_CANCELLED
                : (($run['exitCode'] ?? null) === 0 ? RoundResult::STEP_OK : RoundResult::STEP_FAILED);
            $reason = match ($status) {
                RoundResult::STEP_OK => null,
                RoundResult::STEP_CANCELLED => self::REASON_CANCELLED,
                default => $parsed['firstFailure'] ?? (isset($run['termSignal']) ? "ukončeno signálem {$run['termSignal']}" : 'exit ' . var_export($run['exitCode'] ?? null, true)),
            };
            $this->finish($index, $status, [
                'durationMs' => $run['durationMs'] ?? null,
                'exitCode' => $run['exitCode'] ?? null,
                'reason' => $reason,
                'counts' => $parsed['counts'],
                'firstFailure' => $parsed['firstFailure'],
            ]);
        }
    }

    // ── Vrstva sad ─────────────────────────────────────────────────────────

    private function datasetsLayer(): void
    {
        foreach ($this->plan->datasets as $dataset) {
            $name = $dataset->name;
            $dsId = $dataset->dataSourceId;
            $dsName = $dataset->dataSourceName;
            $seeded = false;
            if ($dataset->needsCreate()) {
                $dsId = ($this->newDataSourceId)();
                $dsName = RoundDataSources::nameFor($dataset->title);
                $outcome = $this->child(self::LAYER_DATASETS, 'create', 'ds-create + dataset-seed', $name, null, fn (): JobRecord => $this->recipes->createSeeded(
                    $dsId,
                    $dsName,
                    RoundDataSources::purposeFor($name),
                    $name,
                    $dataset->dir,
                    $dataset->country,
                    ['round' => true],
                ));
                $this->result->addDataSource('dataset', $name, $dsId, $dsName, true, $outcome['jobId']);
                $seeded = $outcome['status'] === RoundResult::STEP_OK;
                if (!$seeded) {
                    // Bez zdroje dat nemá smysl nic dalšího — zbytek sady přeskočit s důvodem.
                    foreach ($this->datasetStepsAfterSeed() as [$step, $label]) {
                        $this->record(self::LAYER_DATASETS, $step, $label, $name, null, RoundResult::STEP_SKIPPED, $this->cancelRequested ? self::REASON_CANCELLED : 'zdroj dat kola se nepodařilo založit');
                    }
                    continue;
                }
            } else {
                $this->result->addDataSource('dataset', $name, (string) $dsId, (string) $dsName, false);
                $outcome = $this->child(self::LAYER_DATASETS, 'reseed', 'dataset-seed', $name, null, fn (): JobRecord => $this->recipes->reseed((string) $dsId, (string) $dsName, $name, $dataset->dir));
                $seeded = $outcome['status'] === RoundResult::STEP_OK;
            }
            $dsId = (string) $dsId;
            $dsName = (string) $dsName;

            if (!$seeded) {
                $this->record(self::LAYER_DATASETS, 'verify', 'dataset-verify', $name, null, RoundResult::STEP_SKIPPED, $this->cancelRequested ? self::REASON_CANCELLED : 'přeseedování neproběhlo');
            } else {
                $this->child(self::LAYER_DATASETS, 'verify', 'dataset-verify', $name, null, fn (): JobRecord => $this->recipes->verify($dsId, $dsName, $name, $dataset->dir, true));
            }

            if ($this->plan->e2eProblems !== []) {
                $this->record(self::LAYER_DATASETS, 'e2e', 'dataset-e2e', $name, null, RoundResult::STEP_FAILED, implode(' ', $this->plan->e2eProblems));
            } else {
                $this->child(self::LAYER_DATASETS, 'e2e', 'dataset-e2e', $name, null, fn (): JobRecord => $this->recipes->e2e($dsId, $dsName, $name, $dataset->dir, $this->selection->baseUrl));
            }

            if ($this->selection->eval !== null) {
                $this->evalSteps($dataset, $dsId, $dsName);
            }
        }
    }

    /** @return list<array{0: string, 1: string}> */
    private function datasetStepsAfterSeed(): array
    {
        $steps = [['verify', 'dataset-verify'], ['e2e', 'dataset-e2e']];
        if ($this->selection->eval !== null) {
            $steps[] = ['reseed-eval', 'dataset-seed'];
            $steps[] = ['eval', 'ai-eval'];
        }
        return $steps;
    }

    private function evalSteps(RoundDatasetPlan $dataset, string $dsId, string $dsName): void
    {
        $eval = $this->selection->eval;
        if ($eval === null) {
            return;
        }
        $name = $dataset->name;
        $remaining = $eval->maxCostUsd - $this->result->evalSpentUsd();
        if ($remaining <= 0.000001) {
            $this->record(self::LAYER_DATASETS, 'reseed-eval', 'dataset-seed', $name, null, RoundResult::STEP_SKIPPED, 'strop útraty vyčerpán');
            $this->record(self::LAYER_DATASETS, 'eval', 'ai-eval', $name, null, RoundResult::STEP_SKIPPED, 'strop útraty vyčerpán');
            return;
        }
        if ($this->variantPath === null) {
            $this->record(self::LAYER_DATASETS, 'reseed-eval', 'dataset-seed', $name, null, RoundResult::STEP_SKIPPED, 'varianta evaluace nenalezena');
            $this->record(self::LAYER_DATASETS, 'eval', 'ai-eval', $name, null, RoundResult::STEP_FAILED, "varianta '{$eval->variant}' nenalezena v tests/eval/variants");
            return;
        }
        $outcome = $this->child(self::LAYER_DATASETS, 'reseed-eval', 'dataset-seed', $name, null, fn (): JobRecord => $this->recipes->reseed($dsId, $dsName, $name, $dataset->dir, ['beforeEval' => true]));
        if ($outcome['status'] !== RoundResult::STEP_OK) {
            $this->record(self::LAYER_DATASETS, 'eval', 'ai-eval', $name, null, RoundResult::STEP_SKIPPED, $this->cancelRequested ? self::REASON_CANCELLED : 'přeseedování před evaluací neproběhlo');
            return;
        }
        $variantPath = $this->variantPath;
        $this->child(self::LAYER_DATASETS, 'eval', 'ai-eval ' . $eval->variant, $name, null, fn (): JobRecord => $this->recipes->eval($dsId, $dsName, $name, $dataset->dir, $variantPath, $eval->repeat, [], $remaining));
    }

    // ── Vrstva videí ───────────────────────────────────────────────────────

    private function videosLayer(): void
    {
        foreach ($this->plan->invalidScenarios as $scenario) {
            $this->record(self::LAYER_VIDEOS, 'video', 'video-run check', $scenario->dataset, $scenario->id, RoundResult::STEP_FAILED, $scenario->problem);
        }
        foreach ($this->plan->videos as $video) {
            $name = $video->dataset;
            $dsId = $video->dataSourceId;
            $dsName = $video->dataSourceName;
            $ready = true;
            if ($this->plan->videoProblems !== []) {
                foreach ($video->scenarios as $scenario) {
                    $this->record(self::LAYER_VIDEOS, 'video', 'video-run check', $name, $scenario->id, RoundResult::STEP_FAILED, implode(' ', $this->plan->videoProblems));
                }
                continue;
            }
            if ($video->needsCreate()) {
                $dsId = ($this->newDataSourceId)();
                $dsName = DatasetJobRecipes::videoDataSourceName($video->title);
                $outcome = $this->child(self::LAYER_VIDEOS, 'create', 'ds-create + ds-upgrade', $name, null, fn (): JobRecord => $this->recipes->createVideo($dsId, $dsName, VideoDataSources::purposeFor($name), $name, $video->country));
                $this->result->addDataSource('videos', $name, $dsId, $dsName, true, $outcome['jobId']);
                $ready = $outcome['status'] === RoundResult::STEP_OK;
            } else {
                $this->result->addDataSource('videos', $name, (string) $dsId, (string) $dsName, false);
            }
            foreach ($video->scenarios as $scenario) {
                if (!$ready) {
                    $this->record(self::LAYER_VIDEOS, 'video', 'video-run check', $name, $scenario->id, RoundResult::STEP_SKIPPED, $this->cancelRequested ? self::REASON_CANCELLED : 'zdroj dat pro videa se nepodařilo založit');
                    continue;
                }
                $id = (string) $dsId;
                $dsn = (string) $dsName;
                $this->child(self::LAYER_VIDEOS, 'video', 'video-run check', $name, $scenario->id, fn (): JobRecord => $this->recipes->video($id, $dsn, $name, $scenario->id, $scenario->path, VideoReport::VERB_CHECK, $this->selection->baseUrl));
            }
        }
    }

    // ── Dílčí úloha ────────────────────────────────────────────────────────

    /**
     * Spustí dílčí úlohu a počká na konec. Obsazený zdroj dat = `skipped`
     * bez čekání (D134); zrušení kola = `cancel` dítěte a čekání nejvýš
     * jeho grace + {@see CANCEL_EXTRA_WAIT_SECONDS}.
     *
     * @param \Closure(): JobRecord $recipe
     * @return array{status: string, jobId: ?string, result: array<string, mixed>|null}
     */
    private function child(string $layer, string $step, string $label, ?string $dataset, ?string $scenario, \Closure $recipe): array
    {
        if ($this->cancelRequested) {
            $this->record($layer, $step, $label, $dataset, $scenario, RoundResult::STEP_SKIPPED, self::REASON_CANCELLED);
            return ['status' => RoundResult::STEP_SKIPPED, 'jobId' => null, 'result' => null];
        }
        $index = $this->start($layer, $step, $label, $dataset, $scenario);
        $record = $recipe();
        try {
            $view = $this->children->launch($record);
        } catch (JobConflict $e) {
            $reason = $e->dataSourceId === ''
                ? "běží úloha celého serveru {$e->runningJobId}"
                : "zdroj dat {$e->dataSourceId} má běžící úlohu {$e->runningJobId}";
            $this->finish($index, RoundResult::STEP_SKIPPED, ['durationMs' => 0, 'reason' => $reason]);
            return ['status' => RoundResult::STEP_SKIPPED, 'jobId' => null, 'result' => null];
        } catch (\RuntimeException $e) {
            $this->finish($index, RoundResult::STEP_FAILED, ['durationMs' => 0, 'reason' => 'úlohu nejde spustit: ' . $e->getMessage()]);
            return ['status' => RoundResult::STEP_FAILED, 'jobId' => null, 'result' => null];
        }
        $jobId = (string) $view['id'];
        $this->log->writeln("==> [{$layer}] {$label}" . ($dataset !== null ? " · {$dataset}" : '') . ($scenario !== null ? " · {$scenario}" : '') . " → úloha {$jobId}");
        $started = microtime(true);
        $final = $this->waitForChild($jobId, $record->cancelGraceSeconds);
        $result = $this->children->result($jobId);
        [$status, $reason, $extra] = $this->childOutcome($record->type, $final, $result);
        $this->finish($index, $status, [
            'durationMs' => (int) round((microtime(true) - $started) * 1000),
            'jobId' => $jobId,
            'exitCode' => $final['exitCode'] ?? null,
            'reason' => $reason,
        ] + $extra);
        return ['status' => $status, 'jobId' => $jobId, 'result' => $result];
    }

    /** @return array<string, mixed> poslední pohled na úlohu (status už neaktivní, nebo `cancelled` po vypršení čekání) */
    private function waitForChild(string $id, int $graceSeconds): array
    {
        $cancelSent = false;
        $waitedAfterCancel = 0.0;
        $last = [];
        while (true) {
            ($this->sleep)(self::POLL_SECONDS);
            $view = $this->children->view($id);
            if ($view === null) {
                return $last + ['status' => JobStatus::FAILED, 'error' => 'úloha zmizela z úložiště'];
            }
            $last = $view;
            if (($view['active'] ?? false) !== true) {
                return $view;
            }
            if ($this->cancelRequested && !$cancelSent) {
                $this->children->cancel($id);
                $cancelSent = true;
            }
            if ($cancelSent) {
                $waitedAfterCancel += self::POLL_SECONDS;
                if ($waitedAfterCancel > $graceSeconds + self::CANCEL_EXTRA_WAIT_SECONDS) {
                    return ['status' => JobStatus::CANCELLED, 'timedOutWaiting' => true] + $view;
                }
            }
        }
    }

    /**
     * @param array<string, mixed>      $view
     * @param array<string, mixed>|null $result
     * @return array{0: string, 1: ?string, 2: array<string, mixed>}
     */
    private function childOutcome(string $type, array $view, ?array $result): array
    {
        $status = (string) ($view['status'] ?? JobStatus::FAILED);
        if ($status === JobStatus::CANCELLED) {
            return [RoundResult::STEP_CANCELLED, self::REASON_CANCELLED, []];
        }
        $extra = [];
        if ($type === JobRecord::TYPE_EVAL && is_array($result)) {
            $metrics = is_array($result['metrics'] ?? null) ? $result['metrics'] : [];
            $extra['eval'] = [
                'file' => is_string($result['file'] ?? null) ? basename($result['file']) : null,
                'fieldsHit' => is_numeric($metrics['fields.all.hit'] ?? null) ? (float) $metrics['fields.all.hit'] : null,
                'costUsd' => is_numeric($metrics['cost.totalUsd'] ?? null) ? (float) $metrics['cost.totalUsd'] : null,
                'stoppedBy' => $result['stoppedBy'] ?? null,
            ];
        }
        if ($status === JobStatus::FINISHED) {
            if ($type === JobRecord::TYPE_EVAL && ($result['stoppedBy'] ?? null) !== null) {
                return [RoundResult::STEP_FAILED, 'evaluace zastavena: ' . (string) $result['stoppedBy'], $extra];
            }
            return [RoundResult::STEP_OK, null, $extra];
        }
        $reason = null;
        if (is_array($result)) {
            $problems = $result['problems'] ?? null;
            if (is_array($problems) && $problems !== []) {
                $reason = (string) reset($problems);
            } elseif ($type === JobRecord::TYPE_VERIFY && is_array($result['summary'] ?? null)) {
                $s = $result['summary'];
                $reason = sprintf('kontroly: %d ok, %d s rozdílem, %d přeskočeno', (int) ($s['ok'] ?? 0), (int) ($s['diff'] ?? 0), (int) ($s['skipped'] ?? 0));
            }
        }
        $reason ??= is_string($view['error'] ?? null) && $view['error'] !== ''
            ? $view['error']
            : (($view['statusReason'] ?? null) !== null ? 'obal úlohy: ' . $view['statusReason'] : 'exit ' . var_export($view['exitCode'] ?? null, true));
        return [RoundResult::STEP_FAILED, $reason, $extra];
    }

    // ── Pomocné ────────────────────────────────────────────────────────────

    private function start(string $layer, string $step, string $label, ?string $dataset, ?string $scenario): int
    {
        $index = $this->result->startStep($layer, $step, $label, $dataset, $scenario, $this->timestamp());
        $this->write();
        return $index;
    }

    /** @param array<string, mixed> $extra */
    private function finish(int $index, string $status, array $extra): void
    {
        $this->result->finishStep($index, $status, $this->timestamp(), $extra);
        $this->write();
        $step = $this->result->step($index) ?? [];
        $this->log->writeln(sprintf('    %s%s%s', $status, isset($extra['durationMs']) && $extra['durationMs'] !== null ? sprintf(' · %.1f s', $extra['durationMs'] / 1000) : '', ($extra['reason'] ?? null) !== null ? ' · ' . $extra['reason'] : ''));
        unset($step);
    }

    private function record(string $layer, string $step, string $label, ?string $dataset, ?string $scenario, string $status, ?string $reason): void
    {
        $this->result->recordStep($layer, $step, $label, $dataset, $scenario, $status, $this->timestamp(), $reason);
        $this->write();
        $this->log->writeln(sprintf('==> [%s] %s%s%s: %s%s', $layer, $label, $dataset !== null ? " · {$dataset}" : '', $scenario !== null ? " · {$scenario}" : '', $status, $reason !== null ? ' · ' . $reason : ''));
    }

    private function write(): void
    {
        ($this->writeResult)($this->result->toArray());
    }

    private function timestamp(): string
    {
        return ($this->now)()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
