<?php

declare(strict_types=1);

namespace Shipard\Core\DevJobs;

use Shipard\Cli\BinPaths;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Process\DetachedProcess;

/**
 * Spuštění a zrušení úlohy (D58). `launch()` běží pod `flock` na
 * `dev-jobs/.lock`: kontrola konfliktu ({@see conflictingJob()}: jedna
 * aktivní úloha na zdroj dat, výlučná `update` vylučuje všechny — #129 U1,
 * kolo `round` jen druhé kolo — D134) → zápis `job.json` → `setsid -f`
 * obalu `shpd-server dev-job-run <adresář>` ({@see DetachedProcess}). Obal
 * je session leader (PGID = PID), takže zrušení = signál celé skupině
 * `posix_kill(-pid, 15)` zasáhne i kroky; dílčí úlohy kola mají vlastní
 * session, proto {@see cancel()} kaskáduje na aktivní úlohy s `parent`.
 * `force` pošle SIGKILL a po uvolnění `wrapper.lock` dopíše `state.json`
 * (jediný případ vedle selhaného spawnu, kdy do něj zapisuje API). Pod
 * php-fpm není `pcntl`, proto čísla signálů místo konstant `SIGTERM`.
 * Zámek se otevírá s `e` (close-on-exec) — jinak by ho zdědil obal i jeho
 * kroky a držely ho po smrti držitele.
 */
final class JobLauncher
{
    public const int SIGTERM = 15;
    public const int SIGKILL = 9;
    private const float FORCE_WAIT_SECONDS = 1.0;

    /**
     * @param \Closure(list<string> $argv, string $cwd, string $logFile): bool|null $spawn test seam; výchozí {@see DetachedProcess::spawn()}
     * @param \Closure(int $pid, int $signal): bool|null $kill test seam; výchozí `posix_kill`
     * @param list<string>|null $serverCommand argv prefix `shpd-server`; výchozí {@see BinPaths::shpdServerCommand()}
     */
    public function __construct(
        private readonly JobStore $store,
        private readonly ?\Closure $spawn = null,
        private readonly ?\Closure $kill = null,
        private readonly ?array $serverCommand = null,
    ) {}

    /**
     * @return array<string, mixed> pohled na úlohu ({@see JobStore::view()})
     * @throws JobConflict běží úloha, která novou vylučuje
     * @throws \RuntimeException adresář úloh nejde založit nebo zapsat
     */
    public function launch(JobRecord $record): array
    {
        if (!$this->store->ensureDir()) {
            throw new \RuntimeException('Cannot create the jobs directory ' . $this->store->jobsDir());
        }
        $lock = @fopen($this->store->jobsDir() . '/' . JobStore::LAUNCH_LOCK, 'ce');
        if ($lock === false) {
            throw new \RuntimeException('Cannot open the launch lock in ' . $this->store->jobsDir());
        }
        try {
            flock($lock, LOCK_EX);
            $active = $this->conflictingJob($record);
            if ($active !== null) {
                throw new JobConflict((string) $active['id'], (string) ($active['dataSource']['id'] ?? ''), (string) $active['type']);
            }
            $this->store->create($record);
            $jobDir = $this->store->jobDir($record->id);
            $argv = [...($this->serverCommand ?? BinPaths::shpdServerCommand()), 'dev-job-run', $jobDir];
            $spawn = $this->spawn ?? static fn (array $a, string $cwd, string $log): bool => DetachedProcess::spawn($a, $cwd, $log);
            if (!$spawn($argv, $jobDir, $jobDir . '/' . JobStore::OUTPUT_FILE)) {
                $now = JobStore::formatTime($this->store->now());
                $state = JobState::pending(count($record->steps));
                $state->status = JobStatus::FAILED;
                $state->error = 'wrapper spawn failed (setsid / proc_open)';
                $state->startedAt = $now;
                $state->finishedAt = $now;
                $state->closeOpenSteps(JobState::STEP_FAILED, $now);
                $this->store->writeState($record->id, $state);
                ErrorLogger::warn('DevJobs: cannot spawn the job wrapper', ['job' => $record->id, 'argv' => $argv]);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $this->store->view($record, $this->store->readState($record->id));
    }

    /**
     * Běžící úloha, která novou vylučuje: výlučná úloha (`update`, U1)
     * koliduje s jakoukoli aktivní úlohou a jakákoli nová s ní; jiná úloha
     * celého serveru (kolo) jen s aktivní úlohou téhož typu (D134: jedno
     * kolo naráz, dílčí úlohy kola i cizí úlohy vedle něj běží); úloha se
     * zdrojem dat s aktivní úlohou téhož zdroje.
     *
     * @return array<string, mixed>|null
     */
    private function conflictingJob(JobRecord $record): ?array
    {
        foreach ($this->store->activeJobs() as $job) {
            $jobType = (string) ($job['type'] ?? '');
            if ($record->isExclusive() || in_array($jobType, JobRecord::EXCLUSIVE_TYPES, true)) {
                return $job;
            }
            if ($record->isServerWide()) {
                if ($jobType === $record->type) {
                    return $job;
                }
                continue;
            }
            $jobDataSource = $job['dataSource']['id'] ?? null;
            if ($jobDataSource !== null && $jobDataSource === $record->dataSourceId) {
                return $job;
            }
        }
        return null;
    }

    /**
     * Zrušení aktivní úlohy; null = úloha neexistuje nebo není co rušit
     * (neaktivní, nebo obal ještě nezapsal PID). Aktivní dílčí úlohy
     * (`parent` = tato úloha) se zruší stejně — mají vlastní session, signál
     * skupině obalu je nezasáhne (D134).
     *
     * @return array<string, mixed>|null
     */
    public function cancel(string $id, bool $force = false): ?array
    {
        $record = $this->store->read($id);
        if ($record === null) {
            return null;
        }
        $state = $this->store->readState($id);
        $view = $this->store->view($record, $state);
        if ($view['active'] !== true || $state === null || $state->pid === null) {
            return null;
        }
        $this->store->requestCancel($id, $force);
        $kill = $this->kill ?? static fn (int $pid, int $signal): bool => function_exists('posix_kill') && posix_kill($pid, $signal);
        $kill(-$state->pid, $force ? self::SIGKILL : self::SIGTERM);
        foreach ($this->store->activeChildren($id) as $child) {
            $this->cancel((string) $child['id'], $force);
        }

        if ($force) {
            $deadline = microtime(true) + self::FORCE_WAIT_SECONDS;
            while ($this->store->isWrapperAlive($id) && microtime(true) < $deadline) {
                usleep(100_000);
            }
            if (!$this->store->isWrapperAlive($id)) {
                $now = JobStore::formatTime($this->store->now());
                $state->status = JobStatus::CANCELLED;
                $state->forced = true;
                $state->cancelRequestedAt ??= $now;
                $state->finishedAt = $now;
                $state->closeOpenSteps(JobState::STEP_CANCELLED, $now);
                $this->store->writeState($id, $state);
            }
        }
        return $this->store->view($record, $this->store->readState($id));
    }
}
