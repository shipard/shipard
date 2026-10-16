<?php

declare(strict_types=1);

namespace Shipard\Core\DevJobs;

/**
 * Běh jednoho kroku uvnitř obalu `dev-job-run`: `proc_open` s argv (žádný
 * shell), stdout do `result.json` (`captureStdout`) nebo `output.log`,
 * stderr vždy do `output.log`. Smyčka `proc_get_status` + `usleep`; stav
 * potomka se zachytí při **prvním** pozorování konce (po signálu nese
 * `exitcode -1` a `termsig`, druhé volání už nic nevrátí). Při žádosti
 * o zrušení SIGTERM potomku (skupinový signál z API ho už nejspíš dostal —
 * duplicitní SIGTERM je neškodný), po `graceSeconds` SIGKILL.
 */
final class JobStepRunner
{
    /**
     * @param \Closure(): bool $shouldCancel
     * @param \Closure(): void|null $tick test seam volaný v každé otočce smyčky
     */
    public function __construct(
        private readonly \Closure $shouldCancel,
        private readonly float $graceSeconds,
        private readonly ?\Closure $tick = null,
        private readonly int $pollMicroseconds = 200_000,
    ) {}

    /** @return array{exitCode: ?int, termSignal: ?int, cancelled: bool, error: ?string} */
    public function run(JobStep $step, string $outputLog, string $resultFile): array
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => $step->captureStdout ? ['file', $resultFile, 'w'] : ['file', $outputLog, 'a'],
            2 => ['file', $outputLog, 'a'],
        ];
        $process = @proc_open($step->argv, $descriptors, $pipes, $step->cwd);
        if (!is_resource($process)) {
            return ['exitCode' => null, 'termSignal' => null, 'cancelled' => false, 'error' => 'proc_open failed: ' . implode(' ', $step->argv)];
        }

        $cancelled = false;
        $termSentAt = null;
        $killSent = false;
        $exitCode = null;
        $termSignal = null;
        while (true) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            if ($this->tick !== null) {
                ($this->tick)();
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['signaled'] ? null : (int) $status['exitcode'];
                $termSignal = $status['signaled'] ? (int) $status['termsig'] : null;
                break;
            }
            if (!$cancelled && ($this->shouldCancel)()) {
                $cancelled = true;
                $termSentAt = microtime(true);
                proc_terminate($process, JobLauncher::SIGTERM);
            } elseif ($cancelled && !$killSent && $termSentAt !== null && microtime(true) - $termSentAt > $this->graceSeconds) {
                $killSent = true;
                proc_terminate($process, JobLauncher::SIGKILL);
            }
            usleep($this->pollMicroseconds);
        }
        proc_close($process);

        return ['exitCode' => $exitCode, 'termSignal' => $termSignal, 'cancelled' => $cancelled, 'error' => null];
    }
}
