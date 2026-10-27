<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Round;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundResult;

/** Souhrn kola (D133): tabulka kroků, součty, stav a exit kód, útrata evaluací. */
final class RoundResultTest extends TestCase
{
    public function testStepsSummaryStatusAndExitCode(): void
    {
        $result = new RoundResult(['layers' => ['code']], ['commit' => 'abc', 'branch' => 'stable', 'dirty' => false], '2026-10-27T12:00:00Z', '20261027-120000-aaaaaa');
        $this->assertSame(RoundResult::STATUS_RUNNING, $result->status());
        $this->assertNull($result->toArray()['exitCode']);

        $i = $result->startStep('code', 'phpunit', 'PHPUnit', null, null, '2026-10-27T12:00:01Z');
        $this->assertSame(RoundResult::STEP_RUNNING, $result->toArray()['steps'][0]['status']);
        $result->finishStep($i, RoundResult::STEP_OK, '2026-10-27T12:05:00Z', ['durationMs' => 299000, 'exitCode' => 0, 'counts' => ['tests' => 10, 'passed' => 10, 'failed' => 0]]);
        $result->recordStep('datasets', 'verify', 'dataset-verify', 'cz-non-payer', null, RoundResult::STEP_SKIPPED, '2026-10-27T12:05:01Z', 'přeseedování neproběhlo');
        $result->addDataSource('dataset', 'cz-non-payer', 'nnnn-0000-0000-0000', 'Testovací kolo — Neplátce', true, '20261027-120001-bbbbbb');

        $result->finish('2026-10-27T12:06:00Z', false);
        $this->assertSame(RoundResult::STATUS_FAILED, $result->status(), 'skipped = not a full success');
        $this->assertSame(1, $result->exitCode());
        $array = $result->toArray();
        $this->assertSame(RoundResult::FORMAT, $array['format']);
        $this->assertSame(['ok' => 1, 'failed' => 0, 'skipped' => 1, 'cancelled' => 0, 'running' => 0], $array['summary']);
        $this->assertSame('2026-10-27T12:06:00Z', $array['finishedAt']);
        $this->assertSame('20261027-120000-aaaaaa', $array['jobId']);
        $this->assertSame('abc', $array['git']['commit']);
        $this->assertSame(['tests' => 10, 'passed' => 10, 'failed' => 0], $array['steps'][0]['counts']);
        $this->assertSame('přeseedování neproběhlo', $array['steps'][1]['reason']);
        $this->assertSame(0, $array['steps'][1]['durationMs']);
        $this->assertTrue($array['dataSources'][0]['created']);
    }

    public function testAllOkIsExitZeroAndCancelledIs130(): void
    {
        $ok = new RoundResult([], ['commit' => null, 'branch' => null, 'dirty' => null], '2026-10-27T12:00:00Z', null);
        $ok->recordStep('code', 'css-units', 'x', null, null, RoundResult::STEP_OK, '2026-10-27T12:00:01Z');
        $ok->finish('2026-10-27T12:00:02Z', false);
        $this->assertSame(RoundResult::STATUS_OK, $ok->status());
        $this->assertSame(0, $ok->exitCode());

        $cancelled = new RoundResult([], ['commit' => null, 'branch' => null, 'dirty' => null], '2026-10-27T12:00:00Z', null);
        $cancelled->recordStep('code', 'phpunit', 'x', null, null, RoundResult::STEP_CANCELLED, '2026-10-27T12:00:01Z', 'kolo zrušeno');
        $cancelled->finish('2026-10-27T12:00:02Z', true);
        $this->assertSame(RoundResult::STATUS_CANCELLED, $cancelled->status());
        $this->assertSame(130, $cancelled->exitCode());
    }

    public function testEvalSpentSumsCostOfFinishedEvaluations(): void
    {
        $result = new RoundResult([], ['commit' => null, 'branch' => null, 'dirty' => null], '2026-10-27T12:00:00Z', null);
        $a = $result->startStep('datasets', 'eval', 'ai-eval', 'a', null, 't');
        $result->finishStep($a, RoundResult::STEP_OK, 't', ['eval' => ['file' => 'x.json', 'fieldsHit' => 0.99, 'costUsd' => 1.25, 'stoppedBy' => null]]);
        $b = $result->startStep('datasets', 'eval', 'ai-eval', 'b', null, 't');
        $result->finishStep($b, RoundResult::STEP_FAILED, 't', ['eval' => ['file' => null, 'fieldsHit' => null, 'costUsd' => 0.5, 'stoppedBy' => 'maxCost']]);
        $this->assertEqualsWithDelta(1.75, $result->evalSpentUsd(), 0.000001);
    }
}
