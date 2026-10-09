<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AnthropicPricing;
use Shipard\Core\Logging\ErrorLogger;

/** Cena volání podle nejdelšího shodného prefixu ID modelu (D19); neznámý model = 0. */
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

    public function testLongestPrefixWins(): void
    {
        // `claude-sonnet-4-5-20260101` sedí na `claude-sonnet-4` i `claude-sonnet-4-5` — vyhrává delší.
        $this->assertSame([3.0, 15.0], AnthropicPricing::rates('claude-sonnet-4-5-20260101'));
        $this->assertSame([0.80, 4.0], AnthropicPricing::rates('claude-haiku-4-5'));
        $this->assertSame([15.0, 75.0], AnthropicPricing::rates('claude-opus-4-1-20250805'));
    }

    public function testCostIsPerMillionTokensRoundedToSixPlaces(): void
    {
        $this->assertSame(0.0105, AnthropicPricing::costUsd('claude-sonnet-4-5', 1000, 500));
        $this->assertSame(0.0, AnthropicPricing::costUsd('claude-sonnet-4-5', 0, 0));
        $this->assertSame(0.000003, AnthropicPricing::costUsd('claude-sonnet-4-5', 1, 0));
    }

    public function testUnknownModelCostsZero(): void
    {
        $this->assertNull(AnthropicPricing::rates('gpt-5'));
        $this->assertSame(0.0, AnthropicPricing::costUsd('gpt-5', 1000, 1000));
    }
}
