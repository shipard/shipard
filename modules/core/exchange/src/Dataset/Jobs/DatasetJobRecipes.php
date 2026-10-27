<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Jobs;

use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobStep;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Core\Server\MainConfigPatcher;

/**
 * Recepty úloh nad zdroji dat sad — jediné místo, kde se skládá argv
 * `reseed` / `create` / `verify` / `e2e` / `video` / `eval` (tasks/testing-phase8a.md
 * § 1: kolo spouští tytéž dílčí úlohy jako dashboard). Volají je
 * `DevEvalController`, `DevVideosController` i orchestrátor kola; jejich
 * testy s přesným argv jsou strážcem receptů.
 *
 * Hodnoty do argv přicházejí už ověřené proti výpisům (sada z katalogu,
 * varianta z `variants/`, zdroj dat podle ID, origin přes `LocalBaseUrl`);
 * recept z nich nic nevaliduje, jen skládá. `parentJobId` nese dílčí úloha
 * kola (D134 — kaskáda zrušení, odkaz v dashboardu).
 */
final class DatasetJobRecipes
{
    public const int DEFAULT_CANCEL_GRACE_SECONDS = 60;
    /** Zrušení proklikání: Playwright po SIGINT zavře prohlížeč a dopíše reportery, příkaz uklidí session. */
    public const int E2E_CANCEL_GRACE_SECONDS = 120;
    /** Zrušení videa: runner po SIGINT zavře Chromium, příkaz uklidí session; seed je nepřerušitelný (desítky sekund). */
    public const int VIDEO_CANCEL_GRACE_SECONDS = 180;
    /** Zrušení evaluace čeká na dobíhající volání modelu (AnalysisComputation::CALL_TIMEOUT_SECONDS 840 + rezerva). */
    public const int EVAL_CANCEL_GRACE_SECONDS = 900;
    /** Podadresář úlohy s výstupy proklikání (`--output-dir`). */
    public const string E2E_DIR = 'e2e';

    public const string EVAL_PURPOSE_PREFIX = 'evaluace AI — ';
    public const string EVAL_NAME_PREFIX = 'Evaluace — ';
    public const string VIDEO_NAME_PREFIX = 'Videa — ';

    /**
     * @param list<string>                   $serverCommand argv prefix `shpd-server`
     * @param list<string>                   $dsCommand     argv prefix `shpd-ds`
     * @param \Closure(): \DateTimeImmutable $now
     */
    public function __construct(
        private readonly array $serverCommand,
        private readonly array $dsCommand,
        private readonly string $repoRoot,
        private readonly string $dataSourcesDir,
        private readonly JobStore $jobs,
        private readonly \Closure $now,
        private readonly ?string $clientIp,
        private readonly ?string $parentJobId = null,
    ) {}

    // ── Názvy a účely ──────────────────────────────────────────────────────

    public static function evalPurpose(string $dataset): string
    {
        try {
            return MainConfigPatcher::normalizePurpose(self::EVAL_PURPOSE_PREFIX . $dataset);
        } catch (\InvalidArgumentException) {
            return 'evaluace AI';
        }
    }

    public static function evalDataSourceName(string $datasetTitle): string
    {
        return self::EVAL_NAME_PREFIX . $datasetTitle;
    }

    public static function videoDataSourceName(string $datasetTitle): string
    {
        return self::VIDEO_NAME_PREFIX . $datasetTitle;
    }

    // ── Recepty ────────────────────────────────────────────────────────────

    /** `dataset-seed <sada> --yes` na existujícím zdroji dat. @param array<string, mixed> $params */
    public function reseed(string $dsId, string $dsName, string $dataset, string $datasetDir, array $params = []): JobRecord
    {
        return $this->record(JobRecord::TYPE_RESEED, $dsId, $dsName, $dataset, null, $params, [$this->seedStep($dsId, $dataset, $datasetDir)], self::DEFAULT_CANCEL_GRACE_SECONDS);
    }

    /**
     * Nový zdroj dat bez periodických úloh a bez uživatele, hned naseedovaný
     * (evaluační zdroj dat D55, zdroj dat kola D132).
     *
     * @param array<string, mixed> $params
     */
    public function createSeeded(string $newId, string $dsName, string $purpose, string $dataset, string $datasetDir, ?string $country, array $params = []): JobRecord
    {
        $steps = [
            $this->createStep($newId, $dsName, $purpose, $country, cron: false),
            $this->seedStep($newId, $dataset, $datasetDir),
        ];
        return $this->record(JobRecord::TYPE_CREATE, $newId, $dsName, $dataset, null, $params, $steps, self::DEFAULT_CANCEL_GRACE_SECONDS);
    }

    /**
     * Zdroj dat pro videa (D126, F7b N1–N2): s cronem — pruh „Periodické
     * úlohy vypnuté“ by byl na každém videu — a `ds-upgrade` místo seedu
     * (čerstvý zdroj dat nemá tabulky, API by odpovídalo 500; sadu s kotvou
     * seeduje až `video-run`).
     */
    public function createVideo(string $newId, string $dsName, string $purpose, string $dataset, ?string $country): JobRecord
    {
        $steps = [
            $this->createStep($newId, $dsName, $purpose, $country, cron: true),
            new JobStep('ds-upgrade ' . $newId, [...$this->dsCommand, 'ds-upgrade'], $this->dsDir($newId)),
        ];
        return $this->record(JobRecord::TYPE_CREATE, $newId, $dsName, $dataset, null, ['purpose' => $purpose, 'videos' => true], $steps, self::DEFAULT_CANCEL_GRACE_SECONDS);
    }

    /** `dataset-verify <sada> --json [--no-apply]`, stdout → `result.json`. */
    public function verify(string $dsId, string $dsName, string $dataset, string $datasetDir, bool $apply): JobRecord
    {
        $argv = [...$this->dsCommand, 'dataset-verify', $datasetDir, '--json'];
        if (!$apply) {
            $argv[] = '--no-apply';
        }
        $step = new JobStep('dataset-verify ' . $dataset, $argv, $this->dsDir($dsId), captureStdout: true);
        return $this->record(JobRecord::TYPE_VERIFY, $dsId, $dsName, $dataset, null, ['apply' => $apply], [$step], self::DEFAULT_CANCEL_GRACE_SECONDS);
    }

    /**
     * Proklikání (F7a D88, D91): `dataset-e2e <sada> --json --progress-file
     * <úloha>/progress.jsonl --output-dir <úloha>/e2e --base-url <origin>`;
     * id úlohy vzniká předem, aby cesty mířily do jejího adresáře.
     */
    public function e2e(string $dsId, string $dsName, string $dataset, string $datasetDir, string $origin): JobRecord
    {
        $id = $this->newId();
        $argv = [
            ...$this->dsCommand, 'dataset-e2e', $datasetDir, '--json',
            '--progress-file', $this->jobs->path($id, JobStore::PROGRESS_FILE),
            '--output-dir', $this->jobs->jobDir($id) . '/' . self::E2E_DIR,
            '--base-url', $origin,
        ];
        $step = new JobStep('dataset-e2e ' . $dataset, $argv, $this->dsDir($dsId), captureStdout: true);
        return $this->record(JobRecord::TYPE_E2E, $dsId, $dsName, $dataset, null, ['baseUrl' => $origin], [$step], self::E2E_CANCEL_GRACE_SECONDS, $id);
    }

    /** Video (F7b D125): `video-run <scénář> --verb … --json --progress-file --base-url <origin>`. */
    public function video(string $dsId, string $dsName, string $dataset, string $scenarioId, string $scenarioPath, string $verb, string $origin): JobRecord
    {
        $id = $this->newId();
        $argv = [
            ...$this->dsCommand, 'video-run', $scenarioPath,
            '--verb', $verb,
            '--json',
            '--progress-file', $this->jobs->path($id, JobStore::PROGRESS_FILE),
            '--base-url', $origin,
        ];
        $step = new JobStep("video-run {$scenarioId} ({$verb})", $argv, $this->dsDir($dsId), captureStdout: true);
        return $this->record(JobRecord::TYPE_VIDEO, $dsId, $dsName, $dataset, null, ['scenario' => $scenarioId, 'verb' => $verb, 'baseUrl' => $origin], [$step], self::VIDEO_CANCEL_GRACE_SECONDS, $id);
    }

    /**
     * Evaluace (D54, D61): `ai-eval … --yes --json --max-cost <USD> --progress-file <úloha>/progress.jsonl`.
     *
     * @param list<string> $only
     */
    public function eval(string $dsId, string $dsName, string $dataset, string $datasetDir, string $variantPath, int $repeat, array $only, float $maxCostUsd): JobRecord
    {
        $id = $this->newId();
        $argv = self::aiEvalArgv($this->dsCommand, $datasetDir, $variantPath, $repeat, $only);
        array_push($argv, '--yes', '--json', '--max-cost', self::formatUsd($maxCostUsd), '--progress-file', $this->jobs->path($id, JobStore::PROGRESS_FILE));
        $variantName = basename($variantPath, '.jsonc');
        $step = new JobStep('ai-eval ' . $dataset . ' / ' . $variantName, $argv, $this->dsDir($dsId), captureStdout: true);
        $params = ['repeat' => $repeat, 'only' => $only, 'maxCostUsd' => $maxCostUsd];
        return $this->record(JobRecord::TYPE_EVAL, $dsId, $dsName, $dataset, $variantName, $params, [$step], self::EVAL_CANCEL_GRACE_SECONDS, $id);
    }

    /**
     * Společný začátek argv `ai-eval` (běh i odhad `--estimate`).
     *
     * @param list<string> $dsCommand
     * @param list<string> $only
     * @return list<string>
     */
    public static function aiEvalArgv(array $dsCommand, string $datasetDir, string $variantPath, int $repeat, array $only): array
    {
        $argv = [...$dsCommand, 'ai-eval', $datasetDir, '--variant', $variantPath, '--repeat', (string) $repeat];
        if ($only !== []) {
            $argv[] = '--only';
            $argv[] = implode(',', $only);
        }
        return $argv;
    }

    /** USD do argv bez exponentu a bez koncových nul (`0.5`, `1.275`). */
    public static function formatUsd(float $value): string
    {
        $s = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        return $s === '' ? '0' : $s;
    }

    // ── Pomocné ────────────────────────────────────────────────────────────

    private function createStep(string $newId, string $dsName, string $purpose, ?string $country, bool $cron): JobStep
    {
        $argv = [
            ...$this->serverCommand, 'ds-create',
            '--name', $dsName,
            '--language', 'cs',
            '--country', $country ?? 'cz',
            '--module', 'install.base',
        ];
        if (!$cron) {
            $argv[] = '--no-cron';
        }
        array_push($argv, '--ds-id', $newId, '--purpose', $purpose);
        return new JobStep('ds-create ' . $newId, $argv, $this->repoRoot);
    }

    private function seedStep(string $dsId, string $dataset, string $datasetDir): JobStep
    {
        return new JobStep('dataset-seed ' . $dataset, [...$this->dsCommand, 'dataset-seed', $datasetDir, '--yes'], $this->dsDir($dsId));
    }

    /**
     * @param array<string, mixed> $params
     * @param list<JobStep>        $steps
     */
    private function record(string $type, string $dsId, string $dsName, string $dataset, ?string $variant, array $params, array $steps, int $grace, ?string $id = null): JobRecord
    {
        return new JobRecord(
            $id ?? $this->newId(),
            $type,
            $dsId,
            $dsName,
            $dataset,
            $variant,
            $params,
            $steps,
            JobStore::formatTime(($this->now)()),
            $this->clientIp,
            $grace,
            $this->parentJobId,
        );
    }

    private function newId(): string
    {
        return JobRecord::newId(($this->now)());
    }

    private function dsDir(string $id): string
    {
        return rtrim($this->dataSourcesDir, '/') . '/' . $id;
    }
}
