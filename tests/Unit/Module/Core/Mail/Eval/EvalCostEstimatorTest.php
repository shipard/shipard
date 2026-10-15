<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Eval;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiCallPlan;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Module\Core\Mail\Eval\EvalCostEstimator;

/** Odhad ceny: poslední běh před ceníkem, ceník × násobitel platformy, vlastní model bez ceny. */
final class EvalCostEstimatorTest extends TestCase
{
    private static function plan(?string $catalogModel = 'claude-sonnet-4-6', float $multiplier = 1.0): AiCallPlan
    {
        return new AiCallPlan('mail.extraction', 'anthropic', '', 'x', $catalogModel, $catalogModel, 32768, null, null, null, 1, 'default', priceMultiplier: $multiplier);
    }

    private static function catalog(): ModelCatalog
    {
        return ModelCatalog::fromArray([
            'claude-sonnet-4-6' => ['name' => 'S', 'order' => 1, 'tier' => 'balanced', 'platformIds' => ['anthropic' => 'claude-sonnet-4-6'], 'prices' => ['input' => 3, 'output' => 15]],
            'claude-haiku-5-5' => ['name' => 'H', 'order' => 2, 'tier' => 'fast', 'platformIds' => ['anthropic' => 'claude-haiku-5-5'], 'prices' => ['input' => 1, 'output' => 5]],
        ]);
    }

    public function testLastRunWins(): void
    {
        $estimate = EvalCostEstimator::estimate(10, ['metrics' => ['cost.usd' => 0.5, 'calls.total' => 20]], self::plan(), null, self::catalog());
        $this->assertSame(['calls' => 10, 'perCall' => 0.025, 'total' => 0.25, 'basis' => 'lastRun'], $estimate);
    }

    public function testCatalogWithTypicalSizesAndTagCall(): void
    {
        // Sonnet: 12000 × 3 + 2500 × 15 = 73 500 / 1M = 0,0735; Haiku štítky: 1500 × 1 + 120 × 5 = 2 100 / 1M = 0,0021.
        $tagPlan = new AiCallPlan('exchange.contentTags', 'anthropic', '', 'claude-haiku-5-5', 'claude-haiku-5-5', 'claude-haiku-5-5', 8000, null, null, null, 1, 'default');
        $estimate = EvalCostEstimator::estimate(4, null, self::plan(), $tagPlan, self::catalog());
        $this->assertSame('catalog', $estimate['basis']);
        $this->assertSame(0.0756, $estimate['perCall']);
        $this->assertSame(0.3024, $estimate['total']);

        $estimate = EvalCostEstimator::estimate(1, ['metrics' => []], self::plan(multiplier: 1.1), null, self::catalog());
        $this->assertSame(0.08085, $estimate['perCall']);

        $this->assertSame('unknown', EvalCostEstimator::estimate(3, null, self::plan(null), null, self::catalog())['basis']);
    }
}
