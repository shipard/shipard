<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\E2e;

use Shipard\Core\DevUpdate\UpdateEnvironment;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Podproces Playwright Test (tasks/testing-phase7a.md I1, I3): `node
 * <cli.js> test --config <config>` v `tests/e2e`, **bez shellu**, stdout
 * i stderr potomka přes roury do předaného výstupu (stderr příkazu — stdout
 * `dataset-e2e` musí zůstat čistý pro JSON report, zděděné deskriptory by
 * ho rozbily). Smyčka `stream_select` + `proc_get_status`; stav potomka se
 * zachytí při prvním pozorování konce (po signálu nese `exitcode -1`
 * a `termsig`). Žádost o zrušení (`shouldCancel`) = **SIGINT** potomkovi
 * (Playwright zavře prohlížeč a dopíše reportery), po `graceSeconds`
 * SIGKILL. Prostředí: pevný PATH ({@see UpdateEnvironment::env()}), `HOME`
 * (cache prohlížeče, když chybí), `SHPD_E2E_*` a `CI=1`.
 */
class PlaywrightRunner
{
    public const int SIGINT = 2;
    public const int SIGKILL = 9;
    public const string ENV_PREFIX = 'SHPD_E2E_';
    /** Po konci potomka se roury dočtou nejvýš takto dlouho (vnuk by je mohl držet). */
    private const float DRAIN_SECONDS = 2.0;

    /**
     * @param \Closure(): bool $shouldCancel
     */
    public function __construct(
        private readonly string $node,
        private readonly string $cli,
        private readonly string $config,
        private readonly string $cwd,
        private readonly \Closure $shouldCancel,
        private readonly float $graceSeconds = 20.0,
        private readonly int $pollMicroseconds = 200_000,
    ) {}

    /** @return list<string> */
    public function argv(): array
    {
        return [$this->node, $this->cli, 'test', '--config', $this->config];
    }

    /**
     * Prostředí potomka: zděděné + pevný PATH, `HOME` (když chybí), proměnné
     * specu (jen `SHPD_E2E_*`) a `CI=1`.
     *
     * @param array<string, string> $e2eVars
     * @return array<string, string>
     */
    public static function environment(array $e2eVars, ?string $home = null, string $path = UpdateEnvironment::DEFAULT_PATH): array
    {
        $env = UpdateEnvironment::env($path);
        if (($env['HOME'] ?? '') === '' && $home !== null && $home !== '') {
            $env['HOME'] = $home;
        }
        foreach ($e2eVars as $name => $value) {
            if (!is_string($name) || !str_starts_with($name, self::ENV_PREFIX)) {
                throw new \InvalidArgumentException("E2E environment variable must start with " . self::ENV_PREFIX . ": {$name}");
            }
            $env[$name] = $value;
        }
        $env['CI'] = '1';
        return $env;
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
            throw new E2eException('Playwright nejde spustit: ' . implode(' ', $this->argv()));
        }
        /** @var array<int, resource> $open */
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        foreach ($open as $stream) {
            stream_set_blocking($stream, false);
        }

        $cancelled = false;
        $intSentAt = null;
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
                    $intSentAt = microtime(true);
                    proc_terminate($process, self::SIGINT);
                } elseif ($cancelled && !$killSent && $intSentAt !== null && microtime(true) - $intSentAt > $this->graceSeconds) {
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
