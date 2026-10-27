<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Round;

/**
 * Počty a první selhání z výstupu kroku vrstvy kódu (D130: do souhrnu jen
 * počty prošlo / selhalo a první selhání, celý výstup je v logu kola).
 * Čisté funkce nad zachyceným textem ({@see TeeOutput}):
 *
 *   - PHPUnit: `OK (N tests, M assertions)` nebo řádek `Tests: N, Assertions: M,
 *     Errors: E, Failures: F, …`; první selhání = řádek `1) Třída::test` + věta pod ním;
 *   - `node --test` (TAP mimo TTY): `# tests N`, `# pass N`, `# fail N`; první `not ok`;
 *   - python `unittest`: `Ran N tests`, `OK` / `FAILED (failures=F, errors=E)`;
 *     první `FAIL:` / `ERROR:`;
 *   - jen exit kód (skripty kontrol CSS, `check:i18n`): bez počtů, první
 *     neprázdný řádek výstupu jako důvod při selhání.
 *
 * Neznámý tvar výstupu = počty null; stav kroku určuje vždy exit kód, parser
 * jen doplňuje.
 */
final class CodeStepParser
{
    private const int FIRST_FAILURE_MAX = 300;

    private function __construct()
    {
    }

    /**
     * @return array{counts: array{tests: int, passed: int, failed: int}|null, firstFailure: ?string}
     */
    public static function parse(string $parser, string $output, ?int $exitCode): array
    {
        $result = match ($parser) {
            CodeStep::PARSER_PHPUNIT => self::phpunit($output),
            CodeStep::PARSER_NODE_TEST => self::nodeTest($output),
            CodeStep::PARSER_UNITTEST => self::unittest($output),
            default => ['counts' => null, 'firstFailure' => null],
        };
        if ($result['firstFailure'] === null && $exitCode !== 0) {
            $result['firstFailure'] = self::firstMeaningfulLine($output);
        }
        return $result;
    }

    /** @return array{counts: array{tests: int, passed: int, failed: int}|null, firstFailure: ?string} */
    private static function phpunit(string $output): array
    {
        $counts = null;
        if (preg_match('/^OK \((\d+) tests?, \d+ assertions?\)/m', $output, $m) === 1) {
            $counts = ['tests' => (int) $m[1], 'passed' => (int) $m[1], 'failed' => 0];
        } elseif (preg_match('/^Tests: (\d+), Assertions: \d+((?:, [A-Za-z]+: \d+)*)\.?$/m', $output, $m) === 1) {
            $tests = (int) $m[1];
            $failed = 0;
            if (preg_match_all('/, (Errors|Failures): (\d+)/', $m[2], $parts, PREG_SET_ORDER) > 0) {
                foreach ($parts as $part) {
                    $failed += (int) $part[2];
                }
            }
            $counts = ['tests' => $tests, 'passed' => max(0, $tests - $failed), 'failed' => $failed];
        }
        $first = null;
        if (preg_match('/^1\) (.+)$\n(.*)$/m', $output, $m) === 1) {
            $first = trim($m[1]) . (trim($m[2]) !== '' ? ' — ' . trim($m[2]) : '');
        }
        return ['counts' => $counts, 'firstFailure' => self::clip($first)];
    }

    /** @return array{counts: array{tests: int, passed: int, failed: int}|null, firstFailure: ?string} */
    private static function nodeTest(string $output): array
    {
        $counts = null;
        if (preg_match('/^# tests (\d+)$/m', $output, $t) === 1) {
            $pass = preg_match('/^# pass (\d+)$/m', $output, $p) === 1 ? (int) $p[1] : 0;
            $fail = preg_match('/^# fail (\d+)$/m', $output, $f) === 1 ? (int) $f[1] : 0;
            $counts = ['tests' => (int) $t[1], 'passed' => $pass, 'failed' => $fail];
        }
        $first = preg_match('/^\s*not ok \d+ - (.+)$/m', $output, $m) === 1 ? trim($m[1]) : null;
        return ['counts' => $counts, 'firstFailure' => self::clip($first)];
    }

    /** @return array{counts: array{tests: int, passed: int, failed: int}|null, firstFailure: ?string} */
    private static function unittest(string $output): array
    {
        $counts = null;
        if (preg_match('/^Ran (\d+) tests? in /m', $output, $m) === 1) {
            $tests = (int) $m[1];
            $failed = 0;
            if (preg_match('/^FAILED \(([^)]*)\)/m', $output, $f) === 1) {
                if (preg_match_all('/(failures|errors)=(\d+)/', $f[1], $parts, PREG_SET_ORDER) > 0) {
                    foreach ($parts as $part) {
                        $failed += (int) $part[2];
                    }
                }
            }
            $counts = ['tests' => $tests, 'passed' => max(0, $tests - $failed), 'failed' => $failed];
        }
        $first = preg_match('/^(FAIL|ERROR): (.+)$/m', $output, $m) === 1 ? $m[1] . ': ' . trim($m[2]) : null;
        return ['counts' => $counts, 'firstFailure' => self::clip($first)];
    }

    private static function firstMeaningfulLine(string $output): ?string
    {
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '>') || str_starts_with($line, 'npm ')) {
                continue;
            }
            return self::clip($line);
        }
        return null;
    }

    private static function clip(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        return mb_strlen($text) > self::FIRST_FAILURE_MAX ? mb_substr($text, 0, self::FIRST_FAILURE_MAX - 1) . '…' : $text;
    }
}
