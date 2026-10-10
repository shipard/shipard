<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AnthropicPricing;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Cena volání podle nejdelšího shodného prefixu ID modelu (D19), sazby
 * podle skutečnosti (tasks/ai-models-phase0.md F0-D9); neznámý model = 0.
 */
final class AnthropicPricingTest extends TestCase
{
    protected function setUp(): void
    {
        ErrorLogger::setLogLevel('error');
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
    }

    /** Každý řádek tabulky F0-D9 — prefix i ID s datovou příponou. */
    public function testRatesPerModelFamily(): void
    {
        $expected = [
            'claude-sonnet-5-5' => [2.0, 10.0],
            'claude-sonnet-5' => [2.0, 10.0],
            'claude-sonnet-4-6' => [3.0, 15.0],
            'claude-sonnet-4-5' => [3.0, 15.0],
            'claude-sonnet-4' => [3.0, 15.0],
            'claude-haiku-5-5' => [0.10, 0.50],
            'claude-haiku-4-5' => [1.0, 5.0],
            'claude-opus-5-5' => [4.0, 20.0],
            'claude-opus-5' => [5.0, 25.0],
            'claude-opus-4-8' => [5.0, 25.0],
            'claude-opus-4-7' => [5.0, 25.0],
            'claude-opus-4-6' => [5.0, 25.0],
            'claude-opus-4-5' => [5.0, 25.0],
            'claude-opus-4-1' => [15.0, 75.0],
            'claude-opus-4' => [15.0, 75.0],
            'claude-fable-5' => [10.0, 50.0],
            'claude-fable-5-1' => [10.0, 50.0],
            'claude-mythos-5' => [10.0, 50.0],
            'claude-mythos-5-1' => [10.0, 50.0],
            // Řada 3 zůstává.
            'claude-3-5-sonnet' => [3.0, 15.0],
            'claude-3-5-haiku' => [0.80, 4.0],
            'claude-3-opus' => [15.0, 75.0],
        ];
        foreach ($expected as $model => $rates) {
            $this->assertSame($rates, AnthropicPricing::rates($model), $model);
            $this->assertSame($rates, AnthropicPricing::rates($model . '-20260101'), $model . ' (dated)');
        }
    }

    public function testLongestPrefixWins(): void
    {
        // `claude-opus-4` (15 / 75) nesmí chytit `claude-opus-4-5` až `-4-8` (5 / 25)…
        $this->assertSame([5.0, 25.0], AnthropicPricing::rates('claude-opus-4-8-20260301'));
        $this->assertSame([15.0, 75.0], AnthropicPricing::rates('claude-opus-4-1-20250805'));
        $this->assertSame([15.0, 75.0], AnthropicPricing::rates('claude-opus-4-20250514'));
        // …a `claude-sonnet-4-5-…` sedí na `claude-sonnet-4` i `claude-sonnet-4-5` — vyhrává delší.
        $this->assertSame([3.0, 15.0], AnthropicPricing::rates('claude-sonnet-4-5-20260101'));
        $this->assertSame([2.0, 10.0], AnthropicPricing::rates('claude-sonnet-5-5-20260601'));
        // Haiku 5.5 nesmí spadnout do žádného staršího prefixu.
        $this->assertSame([0.10, 0.50], AnthropicPricing::rates('claude-haiku-5-5-20260401'));
    }

    public function testHaiku55HasALongPromptTier(): void
    {
        $this->assertSame([0.10, 0.50], AnthropicPricing::rates('claude-haiku-5-5', 100_000));
        $this->assertSame([0.50, 2.50], AnthropicPricing::rates('claude-haiku-5-5', 100_001));
        $this->assertSame(0.000003, AnthropicPricing::costUsd('claude-haiku-5-5', 10, 4));
        // 150 000 vstupních × 0,50 + 1 000 výstupních × 2,50 = 0,0775 USD.
        $this->assertSame(0.0775, AnthropicPricing::costUsd('claude-haiku-5-5', 150_000, 1_000));
        // Jiný model má jedno pásmo bez ohledu na délku promptu.
        $this->assertSame([2.0, 10.0], AnthropicPricing::rates('claude-sonnet-5-5', 500_000));
    }

    public function testCostIsPerMillionTokensRoundedToSixPlaces(): void
    {
        $this->assertSame(0.0105, AnthropicPricing::costUsd('claude-sonnet-4-6', 1000, 500));
        $this->assertSame(0.0, AnthropicPricing::costUsd('claude-sonnet-4-6', 0, 0));
        $this->assertSame(0.000003, AnthropicPricing::costUsd('claude-sonnet-4-6', 1, 0));
        $this->assertSame(0.007, AnthropicPricing::costUsd('claude-sonnet-5-5', 1000, 500));
    }

    public function testUnknownModelCostsZero(): void
    {
        $this->assertNull(AnthropicPricing::rates('gpt-5'));
        $this->assertNull(AnthropicPricing::rates('anthropic.claude-sonnet-4-6'));
        $this->assertSame(0.0, AnthropicPricing::costUsd('gpt-5', 1000, 1000));
    }
}
