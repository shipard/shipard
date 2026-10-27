<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

use Shipard\Core\Process\CapturedProcess;
use Shipard\Module\Core\Exchange\Dataset\Jobs\DatasetJobRecipes;

/**
 * Odhad ceny evaluace podprocesem `shpd-ds ai-eval <sada> --variant …
 * --repeat N [--only …] --estimate --json` v adresáři zdroje dat
 * ({@see CapturedProcess}, limit {@see TIMEOUT_SECONDS}) — jediné místo,
 * které výstup odhadu čte: dashboard (`DevEvalController::estimate`, D61)
 * i testovací kolo (`RoundEstimate`, D131). Odhad nic nevolá a neutrácí;
 * od N11 nepotřebuje čistý zdroj dat.
 *
 * Výsledek: `kind` `ok` (+ `json` objekt odhadu), `preflight` (+ `problems`
 * z `ai-eval`), `timeout`, `error` (+ konce výstupů).
 */
final class EvalEstimateRunner
{
    public const float TIMEOUT_SECONDS = 60.0;
    public const string KIND_OK = 'ok';
    public const string KIND_PREFLIGHT = 'preflight';
    public const string KIND_TIMEOUT = 'timeout';
    public const string KIND_ERROR = 'error';
    private const int OUTPUT_TAIL_BYTES = 4000;

    /**
     * @param list<string> $dsCommand argv prefix `shpd-ds`
     * @param \Closure(list<string>, string, float): array{exitCode: ?int, termSignal: ?int, timedOut: bool, stdout: string, stderr: string}|null $capture test seam; výchozí {@see CapturedProcess::run()}
     */
    public function __construct(
        private readonly array $dsCommand,
        private readonly ?\Closure $capture = null,
    ) {}

    /**
     * @param list<string> $only
     * @return array{kind: string, json: array<string, mixed>|null, problems: list<string>, exitCode: ?int, stdout: string, stderr: string, argv: list<string>}
     */
    public function estimate(string $dsDir, string $datasetDir, string $variantPath, int $repeat, array $only = []): array
    {
        $argv = DatasetJobRecipes::aiEvalArgv($this->dsCommand, $datasetDir, $variantPath, $repeat, $only);
        $argv[] = '--estimate';
        $argv[] = '--json';
        $capture = $this->capture ?? static fn (array $a, string $cwd, float $timeout): array => CapturedProcess::run($a, $cwd, $timeout);
        $result = $capture($argv, $dsDir, self::TIMEOUT_SECONDS);
        $base = ['json' => null, 'problems' => [], 'exitCode' => $result['exitCode'] ?? null, 'stdout' => self::tail((string) ($result['stdout'] ?? '')), 'stderr' => self::tail((string) ($result['stderr'] ?? '')), 'argv' => $argv];
        if (($result['timedOut'] ?? false) === true) {
            return ['kind' => self::KIND_TIMEOUT] + $base;
        }
        $json = self::lastJsonLine((string) ($result['stdout'] ?? ''));
        if (is_array($json) && ($json['ok'] ?? null) === true) {
            return ['kind' => self::KIND_OK, 'json' => $json] + $base;
        }
        if (is_array($json) && ($json['ok'] ?? null) === false) {
            return ['kind' => self::KIND_PREFLIGHT, 'problems' => array_values(array_map('strval', (array) ($json['problems'] ?? [])))] + $base;
        }
        return ['kind' => self::KIND_ERROR] + $base;
    }

    /** @return array<string, mixed>|null poslední řádek stdout, který je JSON objekt */
    public static function lastJsonLine(string $stdout): ?array
    {
        foreach (array_reverse(explode("\n", trim($stdout))) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    public static function tail(string $text): string
    {
        return strlen($text) > self::OUTPUT_TAIL_BYTES ? substr($text, -self::OUTPUT_TAIL_BYTES) : $text;
    }
}
