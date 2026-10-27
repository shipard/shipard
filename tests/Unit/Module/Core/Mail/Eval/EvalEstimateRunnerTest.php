<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Eval;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\Eval\EvalEstimateRunner;

/** Odhad ceny podprocesem `ai-eval --estimate --json`: argv a cwd, čtyři druhy výsledku, poslední JSON řádek. */
final class EvalEstimateRunnerTest extends TestCase
{
    /** @var list<array{argv: list<string>, cwd: string, timeout: float}> */
    private array $calls = [];

    /** @param array{exitCode?: ?int, termSignal?: ?int, timedOut?: bool, stdout?: string, stderr?: string} $result */
    private function runner(array $result): EvalEstimateRunner
    {
        return new EvalEstimateRunner(['/usr/bin/php', '/repo/bin/shpd-ds'], function (array $argv, string $cwd, float $timeout) use ($result): array {
            $this->calls[] = ['argv' => $argv, 'cwd' => $cwd, 'timeout' => $timeout];
            return $result + ['exitCode' => 0, 'termSignal' => null, 'timedOut' => false, 'stdout' => '', 'stderr' => ''];
        });
    }

    public function testOkParsesTheLastJsonLine(): void
    {
        $stdout = "Evaluace AI — sada cz-non-payer: 16 zpráv\n{\"not\": \"last\"}\n{\"ok\":true,\"calls\":16,\"estimate\":{\"total\":1.25,\"basis\":\"catalog\"},\"model\":\"claude-sonnet-5-5\"}\n";
        $outcome = $this->runner(['stdout' => $stdout])->estimate('/opt/ds/aaaa', '/repo/tests/datasets/cz/non-payer', '/repo/tests/eval/variants/baseline.jsonc', 2, ['0101']);

        $this->assertSame(EvalEstimateRunner::KIND_OK, $outcome['kind']);
        $this->assertSame(16, $outcome['json']['calls'] ?? null);
        $this->assertSame(1.25, $outcome['json']['estimate']['total'] ?? null);
        $this->assertSame([
            '/usr/bin/php', '/repo/bin/shpd-ds', 'ai-eval', '/repo/tests/datasets/cz/non-payer', '--variant', '/repo/tests/eval/variants/baseline.jsonc', '--repeat', '2', '--only', '0101', '--estimate', '--json',
        ], $this->calls[0]['argv']);
        $this->assertSame('/opt/ds/aaaa', $this->calls[0]['cwd']);
        $this->assertSame(60.0, $this->calls[0]['timeout']);
        $this->assertSame($this->calls[0]['argv'], $outcome['argv']);
    }

    public function testPreflightTimeoutAndError(): void
    {
        $preflight = $this->runner(['exitCode' => 2, 'stdout' => "{\"ok\":false,\"problems\":[\"server je v režimu production\",\"druhý\"]}\n"])->estimate('/d', '/s', '/v', 1);
        $this->assertSame(EvalEstimateRunner::KIND_PREFLIGHT, $preflight['kind']);
        $this->assertSame(['server je v režimu production', 'druhý'], $preflight['problems']);
        $this->assertNull($preflight['json']);

        $timeout = $this->runner(['timedOut' => true, 'exitCode' => null, 'stderr' => str_repeat('x', 5000)])->estimate('/d', '/s', '/v', 1);
        $this->assertSame(EvalEstimateRunner::KIND_TIMEOUT, $timeout['kind']);
        $this->assertSame(4000, strlen($timeout['stderr']), 'konec výstupu');

        $error = $this->runner(['exitCode' => 1, 'stdout' => "not json\n", 'stderr' => 'boom'])->estimate('/d', '/s', '/v', 1);
        $this->assertSame(EvalEstimateRunner::KIND_ERROR, $error['kind']);
        $this->assertSame(1, $error['exitCode']);
        $this->assertSame('boom', $error['stderr']);
        $this->assertSame([], $error['problems']);
    }

    public function testLastJsonLineSkipsNonObjects(): void
    {
        $this->assertNull(EvalEstimateRunner::lastJsonLine("[1,2]\nplain\n"));
        $this->assertSame(['a' => 1], EvalEstimateRunner::lastJsonLine("{\"a\":1}\n{broken\n"));
    }
}
