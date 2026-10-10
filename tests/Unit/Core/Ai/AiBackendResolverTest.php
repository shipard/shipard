<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiBackendResolver;

/**
 * `AiBackendResolver::tuning()` — jediný převod řádku backendu na ladicí
 * parametry (tasks/ai-models-phase0.md F0-D7): `auto` → null, NULL teplota
 * → null, `0` → 0.0 (dnešní požadavek runneru se nemění).
 */
final class AiBackendResolverTest extends TestCase
{
    public function testAutoAndNullMeanNotSent(): void
    {
        $this->assertSame(
            ['temperature' => null, 'thinking' => null, 'effort' => null],
            AiBackendResolver::tuning(['temperature' => null, 'thinking' => 'auto', 'effort' => 'auto']),
        );
        // Řádek před ds-upgrade / mock bez sloupců.
        $this->assertSame(
            ['temperature' => null, 'thinking' => null, 'effort' => null],
            AiBackendResolver::tuning(['model' => 'claude-x']),
        );
        $this->assertSame(
            ['temperature' => null, 'thinking' => null, 'effort' => null],
            AiBackendResolver::tuning(['temperature' => '', 'thinking' => '', 'effort' => ' auto ']),
        );
    }

    public function testStoredTemperatureIsKeptIncludingZero(): void
    {
        $this->assertSame(0.0, AiBackendResolver::tuning(['temperature' => 0])['temperature']);
        $this->assertSame(0.0, AiBackendResolver::tuning(['temperature' => '0.00'])['temperature']);
        $this->assertSame(0.7, AiBackendResolver::tuning(['temperature' => '0.70'])['temperature']);
        $this->assertSame(1.0, AiBackendResolver::tuning(['temperature' => 1.0])['temperature']);
    }

    public function testExplicitValuesPassThroughUnvalidated(): void
    {
        $tuning = AiBackendResolver::tuning(['temperature' => null, 'thinking' => 'between_tools', 'effort' => 'xhigh']);
        $this->assertSame(['temperature' => null, 'thinking' => 'between_tools', 'effort' => 'xhigh'], $tuning);
        // Neznámá hodnota se neověřuje — chybu vrátí API (F0-D2).
        $this->assertSame('whatever', AiBackendResolver::tuning(['thinking' => 'whatever'])['thinking']);
    }
}
