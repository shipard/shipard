<?php

declare(strict_types=1);

namespace Shipard\Core\Process;

/**
 * Synchronní podproces s argv (žádný shell), odděleným stdout a stderr
 * a časovým limitem: po jeho uplynutí SIGTERM, 5 s grace, SIGKILL
 * (vzor `CronCommand::runProcessJob`, který ale oba proudy slévá). Pro
 * krátké dotazy z API, např. `ai-eval --estimate --json`, kde stdout nese
 * JSON a stderr lidské řádky. Výstup se zachovává do `maxBytes` per proud
 * (konec se uřezává zepředu).
 */
final class CapturedProcess
{
    public const int MAX_CAPTURE_BYTES = 1_048_576;
    private const float KILL_GRACE_SECONDS = 5.0;

    private function __construct()
    {
    }

    /**
     * @param list<string> $argv
     * @param array<string, string>|null $env prostředí potomka; null = zděděné (5. argument `proc_open`)
     * @return array{exitCode: ?int, termSignal: ?int, timedOut: bool, stdout: string, stderr: string}
     */
    public static function run(array $argv, ?string $cwd, float $timeoutSeconds, int $maxBytes = self::MAX_CAPTURE_BYTES, ?array $env = null): array
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = $argv === [] ? false : @proc_open($argv, $descriptors, $pipes, $cwd, $env);
        if (!is_resource($process)) {
            return ['exitCode' => null, 'termSignal' => null, 'timedOut' => false, 'stdout' => '', 'stderr' => 'proc_open failed'];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $drain = static function () use (&$stdout, &$stderr, $pipes, $maxBytes): void {
            $out = @stream_get_contents($pipes[1]);
            if (is_string($out) && $out !== '') {
                $stdout = substr($stdout . $out, -$maxBytes);
            }
            $err = @stream_get_contents($pipes[2]);
            if (is_string($err) && $err !== '') {
                $stderr = substr($stderr . $err, -$maxBytes);
            }
        };

        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;
        $status = null;
        while (true) {
            $drain();
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                proc_terminate($process, 15);
                $grace = microtime(true) + self::KILL_GRACE_SECONDS;
                while (microtime(true) < $grace) {
                    usleep(100_000);
                    $drain();
                    $status = proc_get_status($process);
                    if (!$status['running']) {
                        break;
                    }
                }
                if ($status['running']) {
                    proc_terminate($process, 9);
                    usleep(100_000);
                    $status = proc_get_status($process);
                }
                break;
            }
            usleep(50_000);
        }
        $drain();
        foreach ($pipes as $pipe) {
            @fclose($pipe);
        }
        proc_close($process);

        $ended = is_array($status) && !$status['running'];
        return [
            'exitCode' => $ended && !$status['signaled'] ? (int) $status['exitcode'] : null,
            'termSignal' => $ended && $status['signaled'] ? (int) $status['termsig'] : null,
            'timedOut' => $timedOut,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }
}
