<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\DevJobs\JobLauncher;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobState;
use Shipard\Core\DevJobs\JobStatus;
use Shipard\Core\DevJobs\JobStepRunner;
use Shipard\Core\DevJobs\JobStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `shpd-server dev-job-run <adresář úlohy>` — **skrytý** obal úlohy
 * vývojářského dashboardu (tasks/testing-phase6b.md § 1, D58). Spouští ho
 * jen {@see \Shipard\Core\DevJobs\JobLauncher} přes `setsid -f`, takže je
 * session leader a skupinový signál z API zasáhne i jeho kroky.
 *
 * Běh: `flock` na `wrapper.lock` (živost — drží se do konce), `state.json`
 * s PID a stavem `running`, kroky z `job.json` postupně přes
 * {@see JobStepRunner} (první selhání zastaví zbytek → `skipped`), konečný
 * stav: žádost o zrušení → `cancelled` (exit 130) bez ohledu na exit kódy,
 * všechny kroky exit 0 → `finished` (0), jinak `failed` (1). SIGTERM / SIGINT
 * jen nastaví příznak ({@see handleSignal()} vrací false, Symfony proces
 * neukončí); běžící krok dostane SIGTERM, po `cancelGraceSeconds` SIGKILL.
 * Seamy pro testy: {@see store()}, {@see now()}, {@see tick()},
 * {@see graceSeconds()}, {@see pollMicroseconds()}.
 */
class DevJobRunCommand extends Command implements SignalableCommandInterface
{
    public const int EXIT_CANCELLED = 130;

    private bool $cancelRequested = false;
    private ?string $cancelRequestedAt = null;

    protected function configure(): void
    {
        $this->setName('dev-job-run')
            ->setHidden(true)
            ->setDescription('Internal: run one developer-dashboard background job (started by the dashboard, not by hand)')
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

    /** Žádost o zrušení (cesta signálu i test seam); první žádost zapíše čas. */
    public function requestCancel(): void
    {
        if (!$this->cancelRequested) {
            $this->cancelRequested = true;
            $this->cancelRequestedAt = JobStore::formatTime($this->now());
        }
    }

    // ── Seamy ──────────────────────────────────────────────────────────────

    protected function store(string $jobsDir): JobStore
    {
        return new JobStore($jobsDir);
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    /** Volá se v každé otočce čekací smyčky kroku. */
    protected function tick(): void
    {
    }

    protected function graceSeconds(JobRecord $record): float
    {
        return (float) $record->cancelGraceSeconds;
    }

    protected function pollMicroseconds(): int
    {
        return 200_000;
    }

    // ── Běh ────────────────────────────────────────────────────────────────

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobDir = rtrim((string) $input->getArgument('jobDir'), '/');
        $id = basename($jobDir);
        if (!JobStore::isValidId($id) || !is_file($jobDir . '/' . JobStore::JOB_FILE)) {
            $output->writeln("<error>Not a job directory: {$jobDir}</error>");
            return Command::INVALID;
        }
        $store = $this->store(dirname($jobDir));
        $record = $store->read($id);
        if ($record === null) {
            $output->writeln("<error>Unreadable job.json in {$jobDir}</error>");
            return Command::INVALID;
        }

        $lock = @fopen($store->path($id, JobStore::LOCK_FILE), 'c');
        if ($lock === false) {
            $output->writeln("<error>Cannot open wrapper.lock in {$jobDir}</error>");
            return Command::FAILURE;
        }
        // Blokující: sonda živosti z API drží LOCK_SH jen na okamžik.
        flock($lock, LOCK_EX);

        $pid = getmypid();
        $state = JobState::pending(count($record->steps));
        $state->pid = $pid === false ? null : $pid;
        $state->status = JobStatus::RUNNING;
        $state->startedAt = JobStore::formatTime($this->now());
        $store->writeState($id, $state);

        // Úloha `update` přepíše kód pod běžícím obalem: všechno, co obal
        // může ještě potřebovat, musí být v paměti teď. JobLauncher jinak
        // načte až cesta zrušení (konstanty SIGTERM / SIGKILL v runneru).
        class_exists(JobLauncher::class);

        $outputLog = $store->path($id, JobStore::OUTPUT_FILE);
        $resultFile = $store->path($id, JobStore::RESULT_FILE);
        $runner = new JobStepRunner(
            fn (): bool => $this->cancelRequested,
            $this->graceSeconds($record),
            $this->tick(...),
            $this->pollMicroseconds(),
        );

        $failed = false;
        foreach ($record->steps as $i => $step) {
            if ($this->cancelRequested || $failed) {
                $state->steps[$i]['status'] = JobState::STEP_SKIPPED;
                continue;
            }
            $this->appendLog($outputLog, sprintf('[STEP] %s %s', JobStore::formatTime($this->now()), $step->label));
            $state->steps[$i]['status'] = JobState::STEP_RUNNING;
            $state->steps[$i]['startedAt'] = JobStore::formatTime($this->now());
            $store->writeState($id, $state);

            $result = $runner->run($step, $outputLog, $resultFile);

            $state->steps[$i]['exitCode'] = $result['exitCode'];
            $state->steps[$i]['termSignal'] = $result['termSignal'];
            $state->steps[$i]['finishedAt'] = JobStore::formatTime($this->now());
            if ($result['error'] !== null) {
                $state->steps[$i]['status'] = JobState::STEP_FAILED;
                $state->error = $result['error'];
                $failed = true;
                $this->appendLog($outputLog, '[ERROR] ' . $result['error']);
            } elseif ($result['cancelled'] || $this->cancelRequested) {
                $state->steps[$i]['status'] = JobState::STEP_CANCELLED;
            } elseif ($result['exitCode'] === 0) {
                $state->steps[$i]['status'] = JobState::STEP_FINISHED;
            } else {
                $state->steps[$i]['status'] = JobState::STEP_FAILED;
                $failed = true;
                $this->appendLog($outputLog, sprintf(
                    '[ERROR] step "%s" failed (%s)',
                    $step->label,
                    $result['exitCode'] !== null ? 'exit ' . $result['exitCode'] : 'signal ' . $result['termSignal'],
                ));
            }
            $store->writeState($id, $state);
        }

        $state->cancelRequestedAt = $this->cancelRequestedAt;
        $state->status = $this->cancelRequested ? JobStatus::CANCELLED : ($failed ? JobStatus::FAILED : JobStatus::FINISHED);
        $state->finishedAt = JobStore::formatTime($this->now());
        $store->writeState($id, $state);
        $this->appendLog($outputLog, sprintf('[END] %s %s', $state->finishedAt, $state->status));

        flock($lock, LOCK_UN);
        fclose($lock);

        return match ($state->status) {
            JobStatus::FINISHED => Command::SUCCESS,
            JobStatus::CANCELLED => self::EXIT_CANCELLED,
            default => Command::FAILURE,
        };
    }

    private function appendLog(string $path, string $line): void
    {
        @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
