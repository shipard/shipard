<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Round;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundDatasetPlan;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundEstimate;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundEvalOptions;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundPlan;
use Shipard\Module\Core\Mail\Eval\EvalEstimateRunner;

/** Odhad ceny evaluací kola (D131): součet per sada, chybějící zdroj dat kola nebo varianta = problém, nic se nespouští. */
final class RoundEstimateTest extends TestCase
{
    /** @var array<string, array<string, mixed>> cwd zdroje dat → výsledek podprocesu */
    private array $byCwd = [];
    /** @var list<string> */
    private array $cwds = [];

    private function estimate(): RoundEstimate
    {
        $runner = new EvalEstimateRunner(['php', 'shpd-ds'], function (array $argv, string $cwd, float $timeout): array {
            $this->cwds[] = $cwd;
            return ($this->byCwd[$cwd] ?? []) + ['exitCode' => 0, 'termSignal' => null, 'timedOut' => false, 'stdout' => '', 'stderr' => ''];
        });
        return new RoundEstimate($runner, '/opt/ds/');
    }

    private function plan(RoundDatasetPlan ...$datasets): RoundPlan
    {
        return new RoundPlan([], array_values($datasets), [], [], [], []);
    }

    public function testSumsPerDatasetEstimates(): void
    {
        $this->byCwd['/opt/ds/aaaa'] = ['stdout' => json_encode(['ok' => true, 'calls' => 16, 'model' => 'claude-sonnet-5-5', 'estimate' => ['total' => 1.25, 'basis' => 'catalog']]) . "\n"];
        $this->byCwd['/opt/ds/bbbb'] = ['stdout' => json_encode(['ok' => true, 'calls' => 20, 'model' => 'claude-sonnet-5-5', 'estimate' => ['total' => 0.5, 'basis' => 'sameModel']]) . "\n"];
        $plan = $this->plan(
            new RoundDatasetPlan('cz-non-payer', 'N', 'cz', '/s/non-payer', 'aaaa', 'Kolo N'),
            new RoundDatasetPlan('cz-vat-payer', 'V', 'cz', '/s/vat-payer', 'bbbb', 'Kolo V'),
        );

        $result = $this->estimate()->forPlan($plan, new RoundEvalOptions('baseline', 2, 3.0), '/repo/tests/eval/variants/baseline.jsonc');

        $this->assertEqualsWithDelta(1.75, $result['total'], 0.000001);
        $this->assertSame([], $result['problems']);
        $this->assertSame(['cz-non-payer', 'cz-vat-payer'], array_column($result['rows'], 'dataset'));
        $this->assertSame([1.25, 0.5], array_column($result['rows'], 'total'));
        $this->assertSame([16, 20], array_column($result['rows'], 'calls'));
        $this->assertSame(['catalog', 'sameModel'], array_column($result['rows'], 'basis'));
        $this->assertSame(['/opt/ds/aaaa', '/opt/ds/bbbb'], $this->cwds);
    }

    public function testMissingDataSourceUnknownModelPreflightAndVariantAreProblems(): void
    {
        $this->byCwd['/opt/ds/bbbb'] = ['stdout' => json_encode(['ok' => true, 'calls' => 20, 'estimate' => ['total' => null, 'basis' => 'unknown']]) . "\n"];
        $this->byCwd['/opt/ds/cccc'] = ['exitCode' => 2, 'stdout' => json_encode(['ok' => false, 'problems' => ['server je v režimu production']]) . "\n"];
        $this->byCwd['/opt/ds/dddd'] = ['exitCode' => 1, 'stderr' => "Fatal\n"];
        $plan = $this->plan(
            new RoundDatasetPlan('cz-non-payer', 'N', 'cz', '/s', null, null),
            new RoundDatasetPlan('cz-vat-payer', 'V', 'cz', '/s', 'bbbb', 'Kolo V'),
            new RoundDatasetPlan('cz-association', 'A', 'cz', '/s', 'cccc', 'Kolo A'),
            new RoundDatasetPlan('cz-other', 'O', 'cz', '/s', 'dddd', 'Kolo O'),
        );

        $result = $this->estimate()->forPlan($plan, new RoundEvalOptions('baseline', 1, 1.0), '/v.jsonc');

        $this->assertSame(0.0, $result['total']);
        $this->assertCount(4, $result['problems']);
        $this->assertStringContainsString('Zdroj dat kola pro sadu cz-non-payer ještě neexistuje', $result['problems'][0]);
        $this->assertStringContainsString('bez --eval', $result['problems'][0]);
        $this->assertStringContainsString('Odhad pro sadu cz-vat-payer je neznámý', $result['problems'][1]);
        $this->assertSame('Sada cz-association: server je v režimu production', $result['problems'][2]);
        $this->assertSame('Sada cz-other: ai-eval --estimate selhal (exit 1): Fatal', $result['problems'][3]);
        $this->assertSame(['/opt/ds/bbbb', '/opt/ds/cccc', '/opt/ds/dddd'], $this->cwds, 'chybějící zdroj dat se nespouští');

        $none = $this->estimate()->forPlan($plan, new RoundEvalOptions('nope', 1, 1.0), null);
        $this->assertSame(["Varianta 'nope' není v tests/eval/variants"], $none['problems']);
        $empty = $this->estimate()->forPlan($this->plan(), new RoundEvalOptions('baseline', 1, 1.0), '/v.jsonc');
        $this->assertStringContainsString('Žádná sada k evaluaci', $empty['problems'][0]);
    }
}
