<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Round;

use PHPUnit\Framework\TestCase;
use Shipard\Core\DevJobs\JobConflict;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobStatus;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Module\Core\Exchange\Dataset\Jobs\DatasetJobRecipes;
use Shipard\Module\Core\Exchange\Dataset\Round\ChildJobGateway;
use Shipard\Module\Core\Exchange\Dataset\Round\CodeStep;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundDatasetPlan;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundEvalOptions;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundPlan;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundResult;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundRunner;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundScenarioPlan;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundSelection;
use Shipard\Module\Core\Exchange\Dataset\Round\RoundVideoPlan;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Orchestrace kola (D130, D133, D134) se skriptovanými dílčími úlohami
 * a kroky kódu: pořadí kód → sady → videa, pokračování po selhání,
 * obsazený zdroj dat = skipped bez čekání, založení chybějících zdrojů
 * dat, zrušení uprostřed kroku kódu i dílčí úlohy, rozpočet evaluací.
 */
final class RoundRunnerTest extends TestCase
{
    private const string ROUND_ID = '20261027-120000-roundx';

    /** @var array<string, JobRecord> */
    private array $launched = [];
    /** @var list<string> */
    private array $launchOrder = [];
    /** @var array<string, string> klíč `type` nebo `type:dataset` nebo `type:dataset:eval` → stav úlohy */
    private array $outcomes = [];
    /** @var array<string, array<string, mixed>> */
    private array $results = [];
    /** @var array<string, string> typ → id úlohy, která má při launch vyhodit JobConflict */
    private array $busy = [];
    /** @var array<string, array{exitCode: ?int, termSignal: ?int, cancelled: bool, output: string}> */
    private array $codeOutcomes = [];
    /** @var list<string> */
    private array $cancelled = [];
    /** @var list<array<string, mixed>> */
    private array $snapshots = [];
    private int $polls = 0;
    private int $sleeps = 0;
    private ?\Closure $onPoll = null;
    private ?\Closure $onCodeStep = null;
    private ?RoundRunner $runner = null;
    /** @var array<string, int> */
    private array $activePolls = [];
    private int $dsCounter = 0;
    private BufferedOutput $log;

    protected function setUp(): void
    {
        $this->log = new BufferedOutput();
    }

    private function gateway(): ChildJobGateway
    {
        return new ChildJobGateway(
            function (JobRecord $record): array {
                $key = $this->key($record);
                foreach ([$key, $record->type] as $k) {
                    if (isset($this->busy[$k])) {
                        throw new JobConflict($this->busy[$k], $record->dataSourceId, 'reseed');
                    }
                }
                $this->launched[$record->id] = $record;
                $this->launchOrder[] = $key;
                return ['id' => $record->id, 'type' => $record->type, 'active' => true, 'status' => JobStatus::RUNNING, 'parent' => $record->parentJobId];
            },
            function (string $id): ?array {
                $this->polls++;
                $record = $this->launched[$id] ?? null;
                if ($record === null) {
                    return null;
                }
                if ($this->onPoll !== null) {
                    ($this->onPoll)($id, $record);
                }
                $remaining = $this->activePolls[$this->key($record)] ?? ($this->activePolls[$record->type] ?? 0);
                if ($remaining > 0) {
                    $this->activePolls[$this->key($record)] = $remaining - 1;
                    return ['id' => $id, 'type' => $record->type, 'active' => true, 'status' => JobStatus::RUNNING];
                }
                if (in_array($id, $this->cancelled, true)) {
                    return ['id' => $id, 'type' => $record->type, 'active' => false, 'status' => JobStatus::CANCELLED, 'exitCode' => 130];
                }
                $status = $this->outcomes[$this->key($record)] ?? $this->outcomes[$record->type] ?? JobStatus::FINISHED;
                return ['id' => $id, 'type' => $record->type, 'active' => false, 'status' => $status, 'exitCode' => $status === JobStatus::FINISHED ? 0 : 1, 'error' => null, 'statusReason' => null];
            },
            function (string $id): void {
                $this->cancelled[] = $id;
            },
            function (string $id): ?array {
                $record = $this->launched[$id] ?? null;
                return $record === null ? null : ($this->results[$this->key($record)] ?? $this->results[$record->type] ?? null);
            },
        );
    }

    private function key(JobRecord $record): string
    {
        $key = $record->type . ':' . ($record->dataset ?? '');
        if ($record->type === JobRecord::TYPE_RESEED && ($record->params['beforeEval'] ?? false) === true) {
            $key .= ':eval';
        }
        return $key;
    }

    /** @return list<CodeStep> */
    private function codeSteps(): array
    {
        return [
            new CodeStep('phpunit', 'PHPUnit', ['php', 'vendor/bin/phpunit'], '/repo', CodeStep::PARSER_PHPUNIT),
            new CodeStep('css-units', 'Kontrola CSS jednotek', ['python3', 'scripts/check-css-units.py'], '/repo', CodeStep::PARSER_EXIT_CODE),
        ];
    }

    private function dataset(string $name, ?string $dsId = 'dddd-0000-0000-0000'): RoundDatasetPlan
    {
        return new RoundDatasetPlan($name, 'Sada ' . $name, 'cz', '/repo/tests/datasets/cz/' . $name, $dsId, $dsId === null ? null : 'Kolo ' . $name);
    }

    private function video(?string $dsId = 'vvvv-0000-0000-0000'): RoundVideoPlan
    {
        return new RoundVideoPlan('it-servis', 'Demo — IT servis', 'cz', $dsId, $dsId === null ? null : 'Videa', [new RoundScenarioPlan('s1-prijata-faktura', '/repo/demo/scenarios/s1-prijata-faktura.jsonc', 'it-servis')]);
    }

    /** @param list<string> $e2eProblems @param list<string> $videoProblems */
    private function runner(RoundPlan $plan, ?RoundSelection $selection = null, ?string $variantPath = null): RoundRunner
    {
        $selection ??= RoundSelection::fromArray([]);
        $recipes = new DatasetJobRecipes(['php', 'shpd-server'], ['php', 'shpd-ds'], '/repo', '/opt/ds', new JobStore('/run/dev-jobs'), static fn () => new \DateTimeImmutable('2026-10-27T12:00:00Z'), null, self::ROUND_ID);
        $this->runner = new RoundRunner(
            $plan,
            $selection,
            $recipes,
            $this->gateway(),
            $this->log,
            function (array $snapshot): void {
                $this->snapshots[] = $snapshot;
            },
            function (CodeStep $step, \Closure $shouldCancel): array {
                if ($this->onCodeStep !== null) {
                    ($this->onCodeStep)($step);
                }
                $outcome = $this->codeOutcomes[$step->id] ?? ['exitCode' => 0, 'termSignal' => null, 'cancelled' => false, 'output' => "OK (5 tests, 9 assertions)\n"];
                if ($shouldCancel()) {
                    $outcome = ['exitCode' => null, 'termSignal' => 15, 'cancelled' => true, 'output' => ''];
                }
                return $outcome + ['durationMs' => 1234];
            },
            function (): string {
                $this->dsCounter++;
                return ['eeee-ffff-gggg-hhhh', 'iiii-jjjj-kkkk-llll'][$this->dsCounter - 1];
            },
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-27T12:00:00Z'),
            function (float $seconds): void {
                $this->sleeps++;
            },
            ['commit' => 'abc123', 'branch' => 'stable', 'dirty' => false],
            self::ROUND_ID,
            $variantPath,
        );
        return $this->runner;
    }

    /** @return list<string> `layer/step/dataset/scenario=status` */
    private function steps(RoundResult $result): array
    {
        return array_map(static fn (array $s): string => sprintf('%s/%s/%s/%s=%s', $s['layer'], $s['step'], $s['dataset'] ?? '-', $s['scenario'] ?? '-', $s['status']), $result->toArray()['steps']);
    }

    public function testHappyPathRunsLayersInOrderWithParentOnChildren(): void
    {
        $plan = new RoundPlan($this->codeSteps(), [$this->dataset('cz-non-payer')], [$this->video()], [], [], []);
        $this->activePolls = ['reseed' => 2];

        $result = $this->runner($plan)->run();

        $this->assertSame([
            'code/phpunit/-/-=ok',
            'code/css-units/-/-=ok',
            'datasets/reseed/cz-non-payer/-=ok',
            'datasets/verify/cz-non-payer/-=ok',
            'datasets/e2e/cz-non-payer/-=ok',
            'videos/video/it-servis/s1-prijata-faktura=ok',
        ], $this->steps($result));
        $this->assertSame(['reseed:cz-non-payer', 'verify:cz-non-payer', 'e2e:cz-non-payer', 'video:it-servis'], $this->launchOrder);
        $this->assertSame(RoundResult::STATUS_OK, $result->status());
        $this->assertSame(0, $result->exitCode());
        foreach ($this->launched as $record) {
            $this->assertSame(self::ROUND_ID, $record->parentJobId, 'every child carries the round as parent');
        }
        $array = $result->toArray();
        $this->assertSame(['tests' => 5, 'passed' => 5, 'failed' => 0], $array['steps'][0]['counts']);
        $this->assertSame(1234, $array['steps'][0]['durationMs']);
        $this->assertNotNull($array['steps'][2]['jobId']);
        $this->assertSame(['ok' => 6, 'failed' => 0, 'skipped' => 0, 'cancelled' => 0, 'running' => 0], $array['summary']);
        $this->assertSame([['role' => 'dataset', 'dataset' => 'cz-non-payer', 'id' => 'dddd-0000-0000-0000', 'name' => 'Kolo cz-non-payer', 'created' => false, 'jobId' => null], ['role' => 'videos', 'dataset' => 'it-servis', 'id' => 'vvvv-0000-0000-0000', 'name' => 'Videa', 'created' => false, 'jobId' => null]], $array['dataSources']);
        $this->assertSame('abc123', $array['git']['commit']);
        $this->assertGreaterThan(6, count($this->snapshots), 'result.json se píše při každé změně');
        $this->assertSame(RoundResult::STATUS_RUNNING, $this->snapshots[0]['status']);
        $this->assertSame(RoundResult::STATUS_OK, end($this->snapshots)['status']);
        $this->assertSame(3, $this->sleeps - 3, 'reseed waited two extra polls (+1 per other child)');
        $this->assertStringContainsString('==> kolo ok', $this->log->fetch());
    }

    public function testFailuresDoNotStopTheRoundAndFailedReseedSkipsVerify(): void
    {
        $plan = new RoundPlan($this->codeSteps(), [$this->dataset('cz-non-payer'), $this->dataset('cz-vat-payer', 'eeee-0000-0000-0000')], [], [], [], []);
        $this->codeOutcomes['phpunit'] = ['exitCode' => 2, 'termSignal' => null, 'cancelled' => false, 'output' => "FF\n\n1) Shipard\\Tests\\FooTest::testX\nFailed asserting that false is true.\n\nFAILURES!\nTests: 4, Assertions: 4, Failures: 2.\n"];
        $this->outcomes['reseed:cz-non-payer'] = JobStatus::FAILED;
        $this->outcomes['verify:cz-vat-payer'] = JobStatus::FAILED;
        $this->results['verify:cz-vat-payer'] = ['summary' => ['ok' => 4, 'diff' => 2, 'skipped' => 0], 'exitCode' => 1];
        $this->outcomes['e2e:cz-vat-payer'] = JobStatus::FAILED;
        $this->results['e2e:cz-vat-payer'] = ['status' => 'failed', 'problems' => ['Karta 0V03 selhala: apply/rejected', 'další']];

        $result = $this->runner($plan)->run();

        $this->assertSame([
            'code/phpunit/-/-=failed',
            'code/css-units/-/-=ok',
            'datasets/reseed/cz-non-payer/-=failed',
            'datasets/verify/cz-non-payer/-=skipped',
            'datasets/e2e/cz-non-payer/-=ok',
            'datasets/reseed/cz-vat-payer/-=ok',
            'datasets/verify/cz-vat-payer/-=failed',
            'datasets/e2e/cz-vat-payer/-=failed',
        ], $this->steps($result));
        $steps = $result->toArray()['steps'];
        $this->assertSame(['tests' => 4, 'passed' => 2, 'failed' => 2], $steps[0]['counts']);
        $this->assertSame('Shipard\\Tests\\FooTest::testX — Failed asserting that false is true.', $steps[0]['firstFailure']);
        $this->assertSame($steps[0]['firstFailure'], $steps[0]['reason']);
        $this->assertSame('přeseedování neproběhlo', $steps[3]['reason']);
        $this->assertSame('kontroly: 4 ok, 2 s rozdílem, 0 přeskočeno', $steps[6]['reason']);
        $this->assertSame('Karta 0V03 selhala: apply/rejected', $steps[7]['reason']);
        $this->assertSame(RoundResult::STATUS_FAILED, $result->status());
        $this->assertSame(1, $result->exitCode());
    }

    public function testBusyDataSourceIsSkippedWithoutWaiting(): void
    {
        $plan = new RoundPlan([], [$this->dataset('cz-non-payer')], [], [], [], []);
        $this->busy['reseed'] = '20261027-110000-otherx';
        $this->busy['e2e'] = '20261027-110000-otherx';

        $result = $this->runner($plan)->run();

        $this->assertSame(['datasets/reseed/cz-non-payer/-=skipped', 'datasets/verify/cz-non-payer/-=skipped', 'datasets/e2e/cz-non-payer/-=skipped'], $this->steps($result));
        $steps = $result->toArray()['steps'];
        $this->assertSame('zdroj dat dddd-0000-0000-0000 má běžící úlohu 20261027-110000-otherx', $steps[0]['reason']);
        $this->assertSame(0, $this->sleeps, 'nečeká se');
        $this->assertSame([], $this->launchOrder);
        $this->assertSame(1, $result->exitCode());
    }

    public function testMissingDataSourcesAreCreatedAndReplaceTheFirstReseed(): void
    {
        $plan = new RoundPlan([], [$this->dataset('cz-non-payer', null)], [$this->video(null)], [], [], []);

        $result = $this->runner($plan)->run();

        $this->assertSame([
            'datasets/create/cz-non-payer/-=ok',
            'datasets/verify/cz-non-payer/-=ok',
            'datasets/e2e/cz-non-payer/-=ok',
            'videos/create/it-servis/-=ok',
            'videos/video/it-servis/s1-prijata-faktura=ok',
        ], $this->steps($result));
        $create = $this->launched[array_key_first($this->launched)];
        $this->assertSame(JobRecord::TYPE_CREATE, $create->type);
        $this->assertSame('eeee-ffff-gggg-hhhh', $create->dataSourceId);
        $this->assertSame('Testovací kolo — Sada cz-non-payer', $create->dataSourceName);
        $argv = $create->steps[0]->argv;
        $this->assertContains('--no-cron', $argv);
        $this->assertSame('testovací kolo — cz-non-payer', $argv[array_search('--purpose', $argv, true) + 1]);
        $this->assertSame('dataset-seed cz-non-payer', $create->steps[1]->label);
        $verify = $this->launched[array_keys($this->launched)[1]];
        $this->assertSame('eeee-ffff-gggg-hhhh', $verify->dataSourceId, 'další kroky jdou na nový zdroj dat');
        $videoCreate = $this->launched[array_keys($this->launched)[3]];
        $this->assertSame('Videa — Demo — IT servis', $videoCreate->dataSourceName);
        $this->assertNotContains('--no-cron', $videoCreate->steps[0]->argv, 'zdroj videí s cronem');
        $this->assertSame('ds-upgrade iiii-jjjj-kkkk-llll', $videoCreate->steps[1]->label);
        $ds = $result->toArray()['dataSources'];
        $this->assertTrue($ds[0]['created']);
        $this->assertSame($create->id, $ds[0]['jobId']);
        $this->assertTrue($ds[1]['created']);
        $this->assertSame(0, $result->exitCode());
    }

    public function testFailedCreateSkipsTheRestOfTheDataset(): void
    {
        $plan = new RoundPlan([], [$this->dataset('cz-non-payer', null)], [$this->video(null)], [], [], []);
        $this->outcomes['create:cz-non-payer'] = JobStatus::FAILED;
        $this->outcomes['create:it-servis'] = JobStatus::FAILED;

        $result = $this->runner($plan)->run();

        $this->assertSame(['datasets/create/cz-non-payer/-=failed', 'datasets/verify/cz-non-payer/-=skipped', 'datasets/e2e/cz-non-payer/-=skipped', 'videos/create/it-servis/-=failed', 'videos/video/it-servis/s1-prijata-faktura=skipped'], $this->steps($result));
        $this->assertSame('zdroj dat kola se nepodařilo založit', $result->toArray()['steps'][1]['reason']);
        $this->assertSame('zdroj dat pro videa se nepodařilo založit', $result->toArray()['steps'][4]['reason']);
    }

    public function testToolProblemsFailStepsWithoutLaunchingChildren(): void
    {
        $plan = new RoundPlan([], [$this->dataset('cz-non-payer')], [$this->video(null)], [new RoundScenarioPlan('s9-broken', '/x', null, 'Scénář nejde načíst')], ['Node chybí — spusť npm ci'], ['ffmpeg chybí']);

        $result = $this->runner($plan)->run();

        $this->assertSame(['datasets/reseed/cz-non-payer/-=ok', 'datasets/verify/cz-non-payer/-=ok', 'datasets/e2e/cz-non-payer/-=failed', 'videos/video/-/s9-broken=failed', 'videos/video/it-servis/s1-prijata-faktura=failed'], $this->steps($result));
        $steps = $result->toArray()['steps'];
        $this->assertSame('Node chybí — spusť npm ci', $steps[2]['reason']);
        $this->assertSame('Scénář nejde načíst', $steps[3]['reason']);
        $this->assertSame('ffmpeg chybí', $steps[4]['reason']);
        $this->assertSame(['reseed:cz-non-payer', 'verify:cz-non-payer'], $this->launchOrder, 'ani e2e, ani založení zdroje videí');
    }

    public function testCancelDuringCodeStepSkipsEverythingElse(): void
    {
        $plan = new RoundPlan($this->codeSteps(), [$this->dataset('cz-non-payer')], [$this->video()], [], [], []);
        $this->onCodeStep = function (CodeStep $step): void {
            if ($step->id === 'phpunit') {
                $this->runner?->requestCancel();
            }
        };

        $result = $this->runner($plan)->run();

        $this->assertSame(['code/phpunit/-/-=cancelled', 'code/css-units/-/-=skipped', 'datasets/reseed/cz-non-payer/-=skipped', 'datasets/verify/cz-non-payer/-=skipped', 'datasets/e2e/cz-non-payer/-=skipped', 'videos/video/it-servis/s1-prijata-faktura=skipped'], $this->steps($result));
        $this->assertSame(['kolo zrušeno', 'kolo zrušeno'], [$result->toArray()['steps'][0]['reason'], $result->toArray()['steps'][1]['reason']]);
        $this->assertSame([], $this->launchOrder);
        $this->assertSame(RoundResult::STATUS_CANCELLED, $result->status());
        $this->assertSame(130, $result->exitCode());
    }

    public function testCancelDuringChildCancelsItAndSkipsTheRest(): void
    {
        $plan = new RoundPlan([], [$this->dataset('cz-non-payer')], [], [], [], []);
        $this->activePolls = ['reseed' => 5];
        $this->onPoll = function (string $id, JobRecord $record): void {
            if ($this->polls === 2) {
                $this->runner?->requestCancel();
            }
        };

        $result = $this->runner($plan)->run();

        $this->assertCount(1, $this->cancelled, 'dílčí úloha dostala cancel právě jednou');
        $this->assertSame(['datasets/reseed/cz-non-payer/-=cancelled', 'datasets/verify/cz-non-payer/-=skipped', 'datasets/e2e/cz-non-payer/-=skipped'], $this->steps($result));
        $this->assertSame('kolo zrušeno', $result->toArray()['steps'][1]['reason']);
        $this->assertSame(130, $result->exitCode());
    }

    public function testCancelWaitIsBoundedByChildGrace(): void
    {
        $plan = new RoundPlan([], [$this->dataset('cz-non-payer')], [], [], [], []);
        $this->activePolls = ['reseed' => 100000];
        $this->onPoll = function (): void {
            if ($this->polls === 1) {
                $this->runner?->requestCancel();
            }
        };
        $this->cancelled = ['never-matches'];

        $result = $this->runner($plan)->run();

        $this->assertSame('cancelled', $result->toArray()['steps'][0]['status']);
        $this->assertLessThan(60 + RoundRunner::CANCEL_EXTRA_WAIT_SECONDS + 5, $this->polls, 'reseed grace 60 s + rezerva, pak dál');
        $this->assertSame(130, $result->exitCode());
    }

    public function testEvalBudgetFlowsFromCapMinusSpent(): void
    {
        $selection = RoundSelection::fromArray(['layers' => ['datasets'], 'eval' => ['variant' => 'baseline', 'repeat' => 1, 'maxCostUsd' => 2.0]]);
        $plan = new RoundPlan([], [$this->dataset('a', 'aaaa-0000-0000-0000'), $this->dataset('b', 'bbbb-0000-0000-0000'), $this->dataset('c', 'cccc-0000-0000-0000')], [], [], [], []);
        $this->results['eval:a'] = ['file' => '/repo/tests/eval/a/2026-10-27-baseline.json', 'metrics' => ['fields.all.hit' => 0.98, 'cost.totalUsd' => 1.5], 'stoppedBy' => null];
        $this->results['eval:b'] = ['file' => '/repo/tests/eval/b/2026-10-27-baseline.json', 'metrics' => ['fields.all.hit' => 0.9, 'cost.totalUsd' => 0.6], 'stoppedBy' => 'maxCost'];

        $result = $this->runner($plan, $selection, '/repo/tests/eval/variants/baseline.jsonc')->run();

        $steps = $this->steps($result);
        $this->assertSame('datasets/reseed-eval/a/-=ok', $steps[3]);
        $this->assertSame('datasets/eval/a/-=ok', $steps[4]);
        $this->assertSame('datasets/eval/b/-=failed', $steps[9]);
        $this->assertSame(['datasets/reseed-eval/c/-=skipped', 'datasets/eval/c/-=skipped'], array_slice($steps, 13, 2));
        $evalA = $this->launched[array_keys($this->launched)[4]];
        $this->assertSame(JobRecord::TYPE_EVAL, $evalA->type);
        $this->assertSame('2', $evalA->steps[0]->argv[array_search('--max-cost', $evalA->steps[0]->argv, true) + 1], 'první evaluace má celý strop');
        $this->assertSame('baseline', $evalA->variant);
        $evalB = $this->launched[array_keys($this->launched)[9]];
        $this->assertSame('0.5', $evalB->steps[0]->argv[array_search('--max-cost', $evalB->steps[0]->argv, true) + 1], 'druhá dostane zbytek 2 − 1,5');
        $array = $result->toArray();
        $this->assertSame(['file' => '2026-10-27-baseline.json', 'fieldsHit' => 0.98, 'costUsd' => 1.5, 'stoppedBy' => null], $array['steps'][4]['eval']);
        $this->assertSame('evaluace zastavena: maxCost', $array['steps'][9]['reason']);
        $this->assertSame('strop útraty vyčerpán', $array['steps'][13]['reason']);
        $this->assertEqualsWithDelta(2.1, $result->evalSpentUsd(), 0.000001);
        $this->assertSame(1, $result->exitCode());
    }
}
