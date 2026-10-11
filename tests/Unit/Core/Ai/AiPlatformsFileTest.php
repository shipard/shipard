<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiPlatform;
use Shipard\Core\Ai\AiPlatforms;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Utils\JsoncParser;

/**
 * Konzistence skutečné mapy platforem `modules/core/ai/config/platforms.jsonc`
 * s katalogem `models.jsonc` (tasks/ai-bedrock-eu.md D57): každá platforma
 * má známý dialekt, adresu https, `modelIds` ukazuje na rodinu ID, kterou
 * katalog zná, a platforma `bedrock-eu` existuje s prefixem `eu.`
 * a násobitelem 1,1 (D60).
 */
final class AiPlatformsFileTest extends TestCase
{
    private static function configDir(): string
    {
        return dirname(__DIR__, 4) . '/modules/core/ai/config/';
    }

    private function platforms(): AiPlatforms
    {
        return AiPlatforms::fromArray(JsoncParser::parseFile(self::configDir() . 'platforms.jsonc'));
    }

    public function testEveryPlatformIsConsistentWithTheCatalog(): void
    {
        $catalog = ModelCatalog::fromArray(JsoncParser::parseFile(self::configDir() . 'models.jsonc'));
        $families = [];
        foreach ($catalog->all() as $spec) {
            foreach (array_keys($spec->platformIds) as $family) {
                $families[(string) $family] = true;
            }
        }

        $platforms = $this->platforms();
        $this->assertFalse($platforms->isEmpty());
        foreach ($platforms->all() as $platform) {
            $this->assertSame(AiPlatform::DIALECT_ANTHROPIC_MESSAGES, $platform->dialect, "{$platform->id}: klient zná jen Messages API");
            $this->assertStringStartsWith('https://', $platform->baseUrl, "{$platform->id}: výchozí adresa");
            $this->assertArrayHasKey($platform->modelIds, $families, "{$platform->id}: modelIds '{$platform->modelIds}' není rodina ID katalogu");
            $this->assertGreaterThanOrEqual(1.0, $platform->priceMultiplier, "{$platform->id}: násobitel ceny");
        }
    }

    public function testAnthropicAndBedrockEu(): void
    {
        $platforms = $this->platforms();

        $anthropic = $platforms->get('anthropic');
        $this->assertNotNull($anthropic);
        $this->assertSame('https://api.anthropic.com', $anthropic->baseUrl);
        $this->assertSame(ModelCatalog::PLATFORM_ANTHROPIC, $anthropic->modelIds);
        $this->assertSame('', $anthropic->modelIdPrefix);
        $this->assertSame(1.0, $anthropic->priceMultiplier);

        $bedrock = $platforms->get('bedrock-eu');
        $this->assertNotNull($bedrock, 'platforma bedrock-eu (D57)');
        $this->assertSame('Amazon Bedrock (EU)', $bedrock->name);
        $this->assertSame('https://bedrock-runtime.eu-central-1.amazonaws.com/anthropic', $bedrock->baseUrl);
        $this->assertSame(ModelCatalog::PLATFORM_BEDROCK, $bedrock->modelIds);
        $this->assertSame('eu.', $bedrock->modelIdPrefix);
        $this->assertSame(1.1, $bedrock->priceMultiplier, 'D60');
        $this->assertSame(['anthropic', 'bedrock-eu'], array_map(static fn (AiPlatform $p): string => $p->id, $platforms->all()));
    }
}
