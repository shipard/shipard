<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Eval;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiCallPlan;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Module\Core\Mail\Eval\EvalCostEstimator;

/** Odhad ceny: poslední běh se stejným modelem → běh s modelem téže řady → ceník × násobitel platformy; vlastní model bez ceny (D34, D44, D56). */
final class EvalCostEstimatorTest extends TestCase
{
    private static function plan(?string $catalogModel = 'claude-sonnet-4-6', float $multiplier = 1.0): AiCallPlan
    {
        return new AiCallPlan('mail.extraction', 'anthropic', '', 'x', $catalogModel, $catalogModel, 32768, null, null, null, 1, 'default', priceMultiplier: $multiplier);
    }

    private static function tagPlan(): AiCallPlan
    {
        return new AiCallPlan('exchange.contentTags', 'anthropic', '', 'claude-haiku-5-5', 'claude-haiku-5-5', 'claude-haiku-5-5', 8000, null, null, null, 1, 'default');
    }

    private static function catalog(): ModelCatalog
    {
        return ModelCatalog::fromArray([
            'claude-sonnet-4-6' => ['name' => 'S46', 'order' => 1, 'tier' => 'balanced', 'platformIds' => ['anthropic' => 'claude-sonnet-4-6'], 'prices' => ['input' => 3, 'output' => 15]],
            'claude-sonnet-5-5' => ['name' => 'S55', 'order' => 2, 'tier' => 'balanced', 'platformIds' => ['anthropic' => 'claude-sonnet-5-5'], 'prices' => ['input' => 2, 'output' => 10]],
            'claude-opus-5-5' => ['name' => 'O55', 'order' => 3, 'tier' => 'strong', 'platformIds' => ['anthropic' => 'claude-opus-5-5'], 'prices' => ['input' => 4, 'output' => 20]],
            'claude-haiku-5-5' => ['name' => 'H', 'order' => 4, 'tier' => 'fast', 'platformIds' => ['anthropic' => 'claude-haiku-5-5'], 'prices' => ['input' => 1, 'output' => 5]],
        ]);
    }

    /** @return array<string, mixed> */
    private static function runFile(string $file, string $catalogModel, ?int $tokensIn = 11091, ?int $tokensOut = 2572, string $started = '2026-10-15T10:00:00+00:00'): array
    {
        return [
            '_file' => '/repo/tests/eval/cz-vat-payer/' . $file,
            'startedAt' => $started,
            'variant' => ['name' => 'v', 'effective' => ['catalogModel' => $catalogModel]],
            'metrics' => ['cost.usd' => 2.87, 'calls.total' => 60, 'tokens.inputPerDocument' => $tokensIn, 'tokens.outputPerDocument' => $tokensOut],
        ];
    }

    public function testLastRunWithTheSameModelWinsAndAddsTagCost(): void
    {
        // D56: starší běh bez `tags.usd` → k průměru 0,5 / 20 = 0,025 se přičte typická cena štítků Haiku 0,0021.
        $last = self::runFile('2026-10-15-baseline.json', 'claude-sonnet-4-6');
        $last['metrics'] = ['cost.usd' => 0.5, 'calls.total' => 20];
        $series = self::runFile('2026-10-15-sonnet-5-5.json', 'claude-sonnet-5-5');

        $estimate = EvalCostEstimator::estimate(10, $last, $series, self::plan(), self::tagPlan(), self::catalog());
        $this->assertSame(
            ['calls' => 10, 'aiCalls' => 10, 'isdocCalls' => 0, 'perCall' => 0.0271, 'isdocPerCall' => 0.0021, 'total' => 0.271, 'basis' => 'sameModel', 'source' => ['file' => '2026-10-15-baseline.json', 'model' => 'claude-sonnet-4-6']],
            $estimate,
            'řada se nepoužije, štítky typickou velikostí',
        );
        $this->assertSame(0.025, EvalCostEstimator::estimate(10, $last, null, self::plan(), null, self::catalog())['perCall'], 'bez funkce štítků bez přirážky');

        // Běh s cenou štítků (F6b krok 2): (0,5 + 0,1) / 20 = 0,03.
        $last['metrics']['tags.usd'] = 0.1;
        $this->assertSame(0.03, EvalCostEstimator::estimate(10, $last, null, self::plan(), self::tagPlan(), self::catalog())['perCall']);
        $last['metrics']['tags.usd'] = 0.0;
        $this->assertSame(0.025, EvalCostEstimator::estimate(10, $last, null, self::plan(), self::tagPlan(), self::catalog())['perCall'], 'nula = štítky se nevolaly, nic se nepřičítá');
    }

    public function testSameModelRunIsTheNewestWithTheSameCatalogModelAndKnownCost(): void
    {
        $runs = [
            self::runFile('2026-10-16-other-model.json', 'claude-opus-5-5', started: '2026-10-16T10:00:00+00:00'),
            self::runFile('2026-10-15-no-calls.json', 'claude-sonnet-5-5', started: '2026-10-15T12:00:00+00:00') + ['metrics' => ['cost.usd' => 0.0, 'calls.total' => 0]],
            self::runFile('2026-10-15-same.json', 'claude-sonnet-5-5', started: '2026-10-15T11:00:00+00:00'),
            self::runFile('2026-10-15-same-older.json', 'claude-sonnet-5-5', started: '2026-10-15T10:00:00+00:00'),
        ];
        $runs[1]['metrics'] = ['cost.usd' => 0.0, 'calls.total' => 0];

        $this->assertSame('2026-10-15-same.json', basename(EvalCostEstimator::sameModelRun($runs, 'claude-sonnet-5-5')['_file']));
        $this->assertSame('2026-10-16-other-model.json', basename(EvalCostEstimator::sameModelRun($runs, 'claude-opus-5-5')['_file']));
        $this->assertNull(EvalCostEstimator::sameModelRun($runs, 'claude-haiku-5-5'), 'jiný model = jiná cena');
        $this->assertNull(EvalCostEstimator::sameModelRun($runs, null));
    }

    public function testSeriesRunTokensPricedByThePlannedModel(): void
    {
        // Tokeny na doklad z běhu Sonnet 5.5 (11 091 / 2 572), ceník plánovaného Opus 5.5:
        // 11091 × 4 + 2572 × 20 = 95 804 / 1M = 0,095804; štítky Haiku 1500 × 1 + 120 × 5 = 0,0021.
        $series = self::runFile('2026-10-15-sonnet-5-5-tags.json', 'claude-sonnet-5-5');
        $estimate = EvalCostEstimator::estimate(60, null, $series, self::plan('claude-opus-5-5'), self::tagPlan(), self::catalog());
        $this->assertSame('series', $estimate['basis']);
        $this->assertSame(0.097904, $estimate['perCall']);
        $this->assertSame(5.8742, $estimate['total']);
        $this->assertSame(['file' => '2026-10-15-sonnet-5-5-tags.json', 'model' => 'claude-sonnet-5-5'], $estimate['source']);

        $this->assertSame(0.105384, EvalCostEstimator::estimate(1, null, $series, self::plan('claude-opus-5-5', 1.1), null, self::catalog())['perCall'], 'násobitel platformy 1,1, bez štítků');
        $this->assertSame('unknown', EvalCostEstimator::estimate(1, null, $series, self::plan(null), null, self::catalog())['basis'], 'vlastní model nemá cenu ani s tokeny');
    }

    public function testCatalogWithTypicalSizesAndTagCall(): void
    {
        // Sonnet: 12000 × 3 + 2500 × 15 = 73 500 / 1M = 0,0735; Haiku štítky: 1500 × 1 + 120 × 5 = 2 100 / 1M = 0,0021.
        $estimate = EvalCostEstimator::estimate(4, null, null, self::plan(), self::tagPlan(), self::catalog());
        $this->assertSame('catalog', $estimate['basis']);
        $this->assertSame(0.0756, $estimate['perCall']);
        $this->assertSame(0.3024, $estimate['total']);
        $this->assertNull($estimate['source']);

        $estimate = EvalCostEstimator::estimate(1, ['metrics' => []], null, self::plan(multiplier: 1.1), null, self::catalog());
        $this->assertSame(0.08085, $estimate['perCall'], 'běh bez metrik = jako žádný');

        $this->assertSame('unknown', EvalCostEstimator::estimate(3, null, null, self::plan(null), null, self::catalog())['basis']);
    }

    /** #103 D76: zprávy s ISDOC se počítají jen za štítky; cena na volání `ai` z kyblíku `byPath.ai` posledního běhu. */
    public function testIsdocCallsCountOnlyTagsAndSameModelUsesTheAiBucket(): void
    {
        // 10 volání, 4 cestou ISDOC: 6 × 0,0271 + 4 × 0,0021 = 0,1626 + 0,0084.
        $last = self::runFile('2026-10-15-baseline.json', 'claude-sonnet-4-6');
        $last['metrics'] = ['cost.usd' => 0.5, 'calls.total' => 20];
        $estimate = EvalCostEstimator::estimate(10, $last, null, self::plan(), self::tagPlan(), self::catalog(), 4);
        $this->assertSame(['aiCalls' => 6, 'isdocCalls' => 4, 'perCall' => 0.0271, 'isdocPerCall' => 0.0021, 'total' => 0.171], array_intersect_key($estimate, array_flip(['aiCalls', 'isdocCalls', 'perCall', 'isdocPerCall', 'total'])));

        // Běh s rozpadem po cestách: volání ISDOC za 0 nestahují průměr — bere se kyblík ai (0,6 + 0,06) / 12 = 0,055.
        $last['metrics'] = ['cost.usd' => 0.6, 'calls.total' => 20, 'tags.usd' => 0.1];
        $last['byPath'] = [
            'ai' => ['calls.total' => 12, 'calls.ok' => 12, 'cost.usd' => 0.6, 'tags.usd' => 0.06],
            'isdoc' => ['calls.total' => 8, 'calls.ok' => 8, 'cost.usd' => 0.0, 'tags.usd' => 0.04],
        ];
        $estimate = EvalCostEstimator::estimate(10, $last, null, self::plan(), self::tagPlan(), self::catalog(), 4);
        $this->assertSame(0.055, $estimate['perCall']);
        $this->assertSame(0.3384, $estimate['total'], '6 × 0,055 + 4 × 0,0021');
        $this->assertSame('sameModel', $estimate['basis']);

        // Prázdný kyblík ai (celý běh ISDOC) → metriky celku; strop isdocCalls na calls.
        $last['byPath']['ai'] = ['calls.total' => 0, 'calls.ok' => 0, 'cost.usd' => 0.0, 'tags.usd' => 0.0];
        $estimate = EvalCostEstimator::estimate(3, $last, null, self::plan(), self::tagPlan(), self::catalog(), 9);
        $this->assertSame([0, 3, 0.035], [$estimate['aiCalls'], $estimate['isdocCalls'], $estimate['perCall']]);
        $this->assertSame(0.0063, $estimate['total']);

        // Katalog a neznámý model.
        $this->assertSame(0.3024 - 2 * 0.0756 + 2 * 0.0021, EvalCostEstimator::estimate(4, null, null, self::plan(), self::tagPlan(), self::catalog(), 2)['total']);
        $unknown = EvalCostEstimator::estimate(4, null, null, self::plan(null), self::tagPlan(), self::catalog(), 2);
        $this->assertSame(['unknown', null, 0.0021], [$unknown['basis'], $unknown['total'], $unknown['isdocPerCall']]);
    }

    public function testModelSeriesIsTheFirstNumberOfTheCatalogId(): void
    {
        $this->assertSame('4', EvalCostEstimator::modelSeries('claude-sonnet-4-6'));
        $this->assertSame('5', EvalCostEstimator::modelSeries('claude-sonnet-5-5'));
        $this->assertSame('5', EvalCostEstimator::modelSeries('claude-opus-5'));
        $this->assertSame('5', EvalCostEstimator::modelSeries('claude-fable-5-1'));
        $this->assertSame('3', EvalCostEstimator::modelSeries('claude-3-5-haiku'));
        $this->assertNull(EvalCostEstimator::modelSeries('gpt-4o'));
        $this->assertNull(EvalCostEstimator::modelSeries('custom'));
        $this->assertNull(EvalCostEstimator::modelSeries(null));
    }

    public function testSeriesRunIsTheNewestWithTheSameSeriesAndKnownTokens(): void
    {
        $runs = [
            self::runFile('2026-10-16-baseline-3.json', 'claude-sonnet-4-6', started: '2026-10-16T10:00:00+00:00'),      // jiná řada
            self::runFile('2026-10-15-sonnet-5-5-x.json', 'claude-sonnet-5-5', 0, 0, '2026-10-15T12:00:00+00:00'),       // bez tokenů
            self::runFile('2026-10-15-sonnet-5-5-tags.json', 'claude-sonnet-5-5', started: '2026-10-15T11:00:00+00:00'),
            self::runFile('2026-10-15-sonnet-5-5.json', 'claude-sonnet-5-5', started: '2026-10-15T10:00:00+00:00'),
        ];
        $this->assertSame('2026-10-15-sonnet-5-5-tags.json', basename(EvalCostEstimator::seriesRun($runs, 'claude-haiku-5-5')['_file']));
        $this->assertSame('2026-10-16-baseline-3.json', basename(EvalCostEstimator::seriesRun($runs, 'claude-sonnet-4-6')['_file']));
        $this->assertNull(EvalCostEstimator::seriesRun($runs, 'claude-3-5-haiku'));
        $this->assertNull(EvalCostEstimator::seriesRun($runs, null));
        $this->assertNull(EvalCostEstimator::seriesRun([['variant' => []]], 'claude-sonnet-5-5'), 'běh bez modelu se přeskočí');
    }
}
