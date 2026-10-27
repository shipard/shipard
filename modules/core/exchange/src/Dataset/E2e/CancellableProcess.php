<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\E2e;

use Shipard\Core\DevUpdate\UpdateEnvironment;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Zrušitelný podproces bez shellu (tasks/testing-phase7a.md I1, I3;
 * tasks/testing-phase7b.md § 2): `proc_open` s argv potomka, stdin
 * `/dev/null`, stdout i stderr přes roury do předaného výstupu (stderr
 * příkazu — stdout musí zůstat čistý pro JSON report, zděděné deskriptory
 * by ho rozbily). Smyčka `stream_select` + `proc_get_status`; stav potomka
 * se zachytí při prvním pozorování konce (po signálu nese `exitcode -1`
 * a `termsig`). Žádost o zrušení (`shouldCancel`) = `cancelSignal` potomkovi
 * (SIGINT pro Playwright a video-runner, SIGTERM pro python), po
 * `graceSeconds` SIGKILL. Prostředí skládá {@see environmentFor()}: pevný
 * PATH ({@see UpdateEnvironment::env()}), `HOME` (když chybí — PHP-FPM
 * s `clear_env` ho nepředá) a jen proměnné s povolenými prefixy.
 * Konkrétní potomek ({@see PlaywrightRunner}, video-runner, build sady)
 * dodává `argv()`.
 */
abstract class CancellableProcess
{
    public const int SIGINT = 2;
    public const int SIGKILL = 9;
    public const int SIGTERM = 15;
    /** Po konci potomka se roury dočtou nejvýš takto dlouho (vnuk by je mohl držet). */
    private const float DRAIN_SECONDS = 2.0;

    /**
     * @param \Closure(): bool $shouldCancel
     */
    public function __construct(
        private readonly string $cwd,
        private readonly \Closure $shouldCancel,
        private readonly float $graceSeconds = 20.0,
        private readonly int $pollMicroseconds = 200_000,
        private readonly int $cancelSignal = self::SIGINT,
    ) {}

    /** @return list<string> */
    abstract public function argv(): array;

    public function cwd(): string
    {
        return $this->cwd;
    }

    public function cancelSignal(): int
    {
        return $this->cancelSignal;
    }

    /**
     * Prostředí potomka: zděděné + pevný PATH, `HOME` (když chybí) a `$vars`
     * jen s povolenými prefixy — cokoli jiného je chyba programu (`PATH`
     * nebo `HOME` z volajícího by obešly pevné prostředí).
     *
     * @param array<string, string> $vars
     * @param list<string> $prefixes
     * @return array<string, string>
     */
    public static function environmentFor(array $vars, array $prefixes, ?string $home = null, string $path = UpdateEnvironment::DEFAULT_PATH): array
    {
        $env = UpdateEnvironment::env($path);
        if (($env['HOME'] ?? '') === '' && $home !== null && $home !== '') {
            $env['HOME'] = $home;
        }
        foreach ($vars as $name => $value) {
            if (!is_string($name) || !self::hasPrefix($name, $prefixes)) {
                throw new \InvalidArgumentException('Environment variable must start with ' . implode(' / ', $prefixes) . ": {$name}");
            }
            $env[$name] = $value;
        }
        return $env;
    }

    /** @param list<string> $prefixes */
    private static function hasPrefix(string $name, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, string> $env
     * @return array{exitCode: ?int, termSignal: ?int, cancelled: bool, durationMs: int}
     * @throws E2eException potomka nejde spustit
     */
    public function run(array $env, OutputInterface $log): array
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $startedAt = microtime(true);
        $process = @proc_open($this->argv(), $descriptors, $pipes, $this->cwd, $env);
        if (!is_resource($process)) {
            throw new E2eException($this->startFailureMessage());
        }
        /** @var array<int, resource> $open */
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        foreach ($open as $stream) {
            stream_set_blocking($stream, false);
        }

        $cancelled = false;
        $signalSentAt = null;
        $killSent = false;
        $finishedAt = null;
        $exitCode = null;
        $termSignal = null;

        while (true) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            $this->pump($open, $log);

            if ($finishedAt === null) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $finishedAt = microtime(true);
                    $exitCode = $status['signaled'] ? null : (int) $status['exitcode'];
                    $termSignal = $status['signaled'] ? (int) $status['termsig'] : null;
                } elseif (!$cancelled && ($this->shouldCancel)()) {
                    $cancelled = true;
                    $signalSentAt = microtime(true);
                    proc_terminate($process, $this->cancelSignal);
                } elseif ($cancelled && !$killSent && $signalSentAt !== null && microtime(true) - $signalSentAt > $this->graceSeconds) {
                    $killSent = true;
                    proc_terminate($process, self::SIGKILL);
                }
            }
            if ($finishedAt !== null && ($open === [] || microtime(true) - $finishedAt > self::DRAIN_SECONDS)) {
                break;
            }
        }
        foreach ($open as $stream) {
            @fclose($stream);
        }
        proc_close($process);

        return [
            'exitCode' => $exitCode,
            'termSignal' => $termSignal,
            'cancelled' => $cancelled,
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /** Věta výjimky, když `proc_open` selže. */
    protected function startFailureMessage(): string
    {
        return 'Podproces nejde spustit: ' . implode(' ', $this->argv());
    }

    /**
     * Přečte, co je na rourách (nejvýš `pollMicroseconds` čekání), a zavře
     * ty, které skončily.
     *
     * @param array<int, resource> $open
     */
    private function pump(array &$open, OutputInterface $log): void
    {
        if ($open === []) {
            usleep($this->pollMicroseconds);
            return;
        }
        $read = array_values($open);
        $write = null;
        $except = null;
        $n = @stream_select($read, $write, $except, 0, $this->pollMicroseconds);
        if ($n === false || $n === 0) {
            return;
        }
        foreach ($read as $stream) {
            $chunk = fread($stream, 65536);
            if ($chunk !== false && $chunk !== '') {
                $log->write($chunk, false, OutputInterface::OUTPUT_RAW);
                continue;
            }
            if (feof($stream)) {
                $key = array_search($stream, $open, true);
                if ($key !== false) {
                    unset($open[$key]);
                }
                fclose($stream);
            }
        }
    }
}
