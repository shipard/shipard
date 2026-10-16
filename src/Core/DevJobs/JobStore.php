<?php

declare(strict_types=1);

namespace Shipard\Core\DevJobs;

use Shipard\Core\Config\DataSourceState;
use Shipard\Core\Logging\LogTail;
use Shipard\Core\Server\CronProvisioner;

/**
 * Úložiště úloh vývojářského dashboardu (tasks/testing-phase6b.md § 1, D58):
 * adresář úlohy `<run>/dev-jobs/<id>/` mimo repo s těmito soubory
 *
 *   - `job.json`       zadání ({@see JobRecord}) — píše API jednou před spawnem;
 *   - `state.json`     průběh ({@see JobState}) — píše obal `dev-job-run`;
 *   - `wrapper.lock`   `flock` držený obalem po celou dobu běhu = živost
 *                      (jádro ho uvolní i při pádu; žádné hádání podle PID);
 *   - `cancel.json`    značka žádosti o zrušení — píše API;
 *   - `progress.jsonl` události příkazu (`ai-eval --progress-file`);
 *   - `output.log`     stdout + stderr kroků a `[STEP]` hlavičky obalu;
 *   - `result.json`    stdout kroku s `captureStdout`.
 *
 * Stav se nikdy neukládá jako celek — {@see status()} ho odvozuje při každém
 * čtení: bez `state.json` je úloha `starting` nejvýš STARTING_GRACE_SECONDS,
 * pak `failed` (obal se nespustil); `running` s volným `wrapper.lock` je
 * `failed` (obal zemřel); `running` se značkou zrušení je `cancelling`.
 * Úlohy starší než RETENTION_DAYS uklízí {@see prune()}. `/opt/shipard/run`
 * hlídá `PermissionSpec` rekurzivně, nový adresář nepotřebuje záznam.
 */
final class JobStore
{
    public const string JOB_FILE = 'job.json';
    public const string STATE_FILE = 'state.json';
    public const string CANCEL_FILE = 'cancel.json';
    public const string LOCK_FILE = 'wrapper.lock';
    public const string PROGRESS_FILE = 'progress.jsonl';
    public const string OUTPUT_FILE = 'output.log';
    public const string RESULT_FILE = 'result.json';
    /** Zámek kritické sekce spuštění (kontrola běžící úlohy + zápis + spawn). */
    public const string LAUNCH_LOCK = '.lock';

    public const int STARTING_GRACE_SECONDS = 60;
    public const int RETENTION_DAYS = 14;
    public const int OUTPUT_TAIL_LINES = 200;

    public const string REASON_NOT_STARTED = 'wrapper_not_started';
    public const string REASON_DIED = 'wrapper_died';

    /**
     * @param \Closure(string $jobDir): bool|null $isWrapperAlive test seam; výchozí sonda `flock` na `wrapper.lock`
     * @param \Closure(): \DateTimeImmutable|null $now
     */
    public function __construct(
        private readonly string $jobsDir,
        private readonly ?\Closure $isWrapperAlive = null,
        private readonly ?\Closure $now = null,
    ) {}

    public static function defaultDir(): string
    {
        return CronProvisioner::RUN_DIR . '/dev-jobs';
    }

    public function jobsDir(): string
    {
        return $this->jobsDir;
    }

    public function ensureDir(): bool
    {
        if (is_dir($this->jobsDir)) {
            return true;
        }
        return @mkdir($this->jobsDir, 0750, true) || is_dir($this->jobsDir);
    }

    public static function isValidId(string $id): bool
    {
        return JobRecord::isValidId($id);
    }

    /** @throws \InvalidArgumentException id mimo {@see JobRecord::ID_REGEX} — nikdy se z něj neskládá cesta */
    public function jobDir(string $id): string
    {
        if (!self::isValidId($id)) {
            throw new \InvalidArgumentException("Invalid job id '{$id}'");
        }
        return $this->jobsDir . '/' . $id;
    }

    public function path(string $id, string $file): string
    {
        return $this->jobDir($id) . '/' . $file;
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now !== null ? ($this->now)() : new \DateTimeImmutable();
    }

    public static function formatTime(\DateTimeImmutable $dt): string
    {
        return DataSourceState::formatUtc($dt);
    }

    // ── Zápis a čtení souborů úlohy ─────────────────────────────────────────

    /** @throws \RuntimeException adresář existuje nebo nejde založit / zapsat */
    public function create(JobRecord $record): void
    {
        $dir = $this->jobDir($record->id);
        if (is_dir($dir)) {
            throw new \RuntimeException("Job directory already exists: {$dir}");
        }
        if (!@mkdir($dir, 0750, true)) {
            throw new \RuntimeException("Cannot create job directory {$dir}");
        }
        $this->writeJson($dir . '/' . self::JOB_FILE, $record->toArray());
    }

    public function read(string $id): ?JobRecord
    {
        if (!self::isValidId($id)) {
            return null;
        }
        $raw = $this->readJson($this->path($id, self::JOB_FILE));
        if ($raw === null) {
            return null;
        }
        try {
            return JobRecord::fromArray($raw);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function readState(string $id): ?JobState
    {
        $raw = $this->readJson($this->path($id, self::STATE_FILE));
        return $raw === null ? null : JobState::fromArray($raw);
    }

    /** @throws \RuntimeException */
    public function writeState(string $id, JobState $state): void
    {
        $this->writeJson($this->path($id, self::STATE_FILE), $state->toArray());
    }

    /** Značka žádosti o zrušení (API) — mimo `state.json`, který patří obalu. */
    public function requestCancel(string $id, bool $force): void
    {
        $existing = $this->cancelRequest($id);
        $this->writeJson($this->path($id, self::CANCEL_FILE), [
            'requestedAt' => $existing['requestedAt'] ?? self::formatTime($this->now()),
            'force' => $force || (bool) ($existing['force'] ?? false),
        ]);
    }

    /** @return array{requestedAt: string, force: bool}|null */
    public function cancelRequest(string $id): ?array
    {
        $raw = $this->readJson($this->path($id, self::CANCEL_FILE));
        if ($raw === null) {
            return null;
        }
        return ['requestedAt' => (string) ($raw['requestedAt'] ?? ''), 'force' => (bool) ($raw['force'] ?? false)];
    }

    /**
     * Obal žije = drží `flock` na `wrapper.lock`. Sonda `LOCK_SH | LOCK_NB`
     * uspěje jen bez držitele; chybějící soubor = obal nikdy nezačal.
     */
    public function isWrapperAlive(string $id): bool
    {
        if ($this->isWrapperAlive !== null) {
            return (bool) ($this->isWrapperAlive)($this->jobDir($id));
        }
        $handle = @fopen($this->path($id, self::LOCK_FILE), 'r');
        if ($handle === false) {
            return false;
        }
        try {
            if (flock($handle, LOCK_SH | LOCK_NB)) {
                flock($handle, LOCK_UN);
                return false;
            }
            return true;
        } finally {
            fclose($handle);
        }
    }

    // ── Odvozený stav a pohled ──────────────────────────────────────────────

    /** @return array{0: string, 1: ?string} [stav, důvod u odvozeného selhání] */
    public function status(JobRecord $record, ?JobState $state): array
    {
        if ($state === null) {
            $age = $this->now()->getTimestamp() - self::timestamp($record->createdAt);
            return $age <= self::STARTING_GRACE_SECONDS
                ? [JobStatus::STARTING, null]
                : [JobStatus::FAILED, self::REASON_NOT_STARTED];
        }
        if ($state->status === JobStatus::RUNNING || $state->status === JobStatus::CANCELLING) {
            if (!$this->isWrapperAlive($record->id)) {
                return [JobStatus::FAILED, self::REASON_DIED];
            }
            return [$this->cancelRequest($record->id) !== null ? JobStatus::CANCELLING : JobStatus::RUNNING, null];
        }
        return [$state->status, null];
    }

    /**
     * Pohled na úlohu pro API: zadání + průběh + odvozený stav.
     *
     * @return array<string, mixed>
     */
    public function view(JobRecord $record, ?JobState $state): array
    {
        [$status, $reason] = $this->status($record, $state);
        $cancel = $this->cancelRequest($record->id);
        $steps = [];
        foreach ($record->steps as $i => $step) {
            $steps[] = $step->toArray() + ($state?->steps[$i] ?? JobState::emptyStep());
        }
        $resultPath = $this->path($record->id, self::RESULT_FILE);
        return array_merge($record->toArray(), [
            'status' => $status,
            'statusReason' => $reason,
            'active' => JobStatus::isActive($status),
            'pid' => $state?->pid,
            'startedAt' => $state?->startedAt,
            'finishedAt' => $state?->finishedAt,
            'cancelRequestedAt' => $state?->cancelRequestedAt ?? $cancel['requestedAt'] ?? null,
            'forced' => $state?->forced ?? false,
            'error' => $state?->error,
            'exitCode' => $state?->exitCode(),
            'steps' => $steps,
            'hasResult' => is_file($resultPath) && (int) filesize($resultPath) > 0,
            'hasProgress' => is_file($this->path($record->id, self::PROGRESS_FILE)),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function viewOf(string $id): ?array
    {
        $record = $this->read($id);
        return $record === null ? null : $this->view($record, $this->readState($id));
    }

    /**
     * Úlohy nejnovější první; filtr podle sady, zdroje dat a stáří ve dnech.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?string $dataset = null, ?string $dataSourceId = null, ?int $days = null): array
    {
        $cutoff = $days !== null ? $this->now()->getTimestamp() - $days * 86400 : null;
        $views = [];
        foreach ($this->ids() as $id) {
            $record = $this->read($id);
            if ($record === null) {
                continue;
            }
            if ($dataset !== null && $record->dataset !== $dataset) {
                continue;
            }
            if ($dataSourceId !== null && $record->dataSourceId !== $dataSourceId) {
                continue;
            }
            if ($cutoff !== null && self::timestamp($record->createdAt) < $cutoff) {
                continue;
            }
            $views[] = $this->view($record, $this->readState($id));
        }
        usort($views, static fn (array $a, array $b): int => strcmp((string) $b['id'], (string) $a['id']));
        return $views;
    }

    /** Aktivní úloha na zdroji dat (nejvýš jedna, D58). @return array<string, mixed>|null */
    public function activeJobFor(string $dataSourceId): ?array
    {
        foreach ($this->list(null, $dataSourceId) as $view) {
            if ($view['active'] === true) {
                return $view;
            }
        }
        return null;
    }

    /** Smaže neaktivní úlohy starší než `$days`; vrací počet smazaných. */
    public function prune(int $days = self::RETENTION_DAYS): int
    {
        $cutoff = $this->now()->getTimestamp() - $days * 86400;
        $removed = 0;
        foreach ($this->ids() as $id) {
            $record = $this->read($id);
            if ($record === null) {
                // Rozbité zadání bez čitelného job.json — po retenci podle názvu (čas v id).
                if (self::idTimestamp($id) < $cutoff) {
                    $this->removeDir($this->jobDir($id));
                    $removed++;
                }
                continue;
            }
            if (self::timestamp($record->createdAt) >= $cutoff) {
                continue;
            }
            [$status] = $this->status($record, $this->readState($id));
            if (JobStatus::isActive($status)) {
                continue;
            }
            $this->removeDir($this->jobDir($id));
            $removed++;
        }
        return $removed;
    }

    // ── Průběh, výstup, výsledek ────────────────────────────────────────────

    /**
     * Události z `progress.jsonl` od bajtového offsetu — jen celé řádky;
     * `nextOffset` ukazuje za poslední `\n`, rozepsaný řádek přijde příště.
     *
     * @return array{events: list<array<string, mixed>>, nextOffset: int}
     */
    public function progress(string $id, int $offset): array
    {
        $path = $this->path($id, self::PROGRESS_FILE);
        if (!is_file($path)) {
            return ['events' => [], 'nextOffset' => 0];
        }
        $size = (int) filesize($path);
        if ($offset < 0 || $offset > $size) {
            $offset = 0;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return ['events' => [], 'nextOffset' => $offset];
        }
        try {
            fseek($handle, $offset);
            $chunk = (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
        $lastNl = strrpos($chunk, "\n");
        if ($lastNl === false) {
            return ['events' => [], 'nextOffset' => $offset];
        }
        $events = [];
        foreach (explode("\n", substr($chunk, 0, $lastNl)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }
        return ['events' => $events, 'nextOffset' => $offset + $lastNl + 1];
    }

    public function outputTail(string $id, int $lines = self::OUTPUT_TAIL_LINES): string
    {
        return implode("\n", (new LogTail($this->path($id, self::OUTPUT_FILE)))->readLast($lines));
    }

    /** Výsledek kroku s `captureStdout`; prázdný nebo nevalidní JSON → null. @return array<string, mixed>|null */
    public function result(string $id): ?array
    {
        $path = $this->path($id, self::RESULT_FILE);
        if (!is_file($path)) {
            return null;
        }
        $raw = trim((string) @file_get_contents($path));
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    // ── Pomocné ─────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function ids(): array
    {
        if (!is_dir($this->jobsDir)) {
            return [];
        }
        $ids = [];
        foreach (scandir($this->jobsDir) ?: [] as $entry) {
            if (self::isValidId($entry) && is_dir($this->jobsDir . '/' . $entry)) {
                $ids[] = $entry;
            }
        }
        return $ids;
    }

    private static function timestamp(string $iso): int
    {
        try {
            return (new \DateTimeImmutable($iso))->getTimestamp();
        } catch (\Exception) {
            return 0;
        }
    }

    private static function idTimestamp(string $id): int
    {
        $dt = \DateTimeImmutable::createFromFormat('Ymd-His', substr($id, 0, 15), new \DateTimeZone('UTC'));
        return $dt === false ? 0 : $dt->getTimestamp();
    }

    /** @param array<string, mixed> $data @throws \RuntimeException */
    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException("Cannot encode {$path}");
        }
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json . "\n") === false) {
            throw new \RuntimeException("Cannot write {$tmp}");
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot rename {$tmp} to {$path}");
        }
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
