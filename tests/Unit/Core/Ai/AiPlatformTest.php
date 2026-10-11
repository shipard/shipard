<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiPlatform;
use Shipard\Core\Ai\AiPlatforms;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Config\ConfigRuntime;

/**
 * AiPlatform / AiPlatforms (tasks/ai-bedrock-eu.md D57, D60): výchozí
 * hodnoty položky jen s názvem, validace polí, ID modelu pro platformu
 * (prefix + rodina), kdy zadané ID na platformu patří, mapa z configu
 * a bez něj.
 */
final class AiPlatformTest extends TestCase
{
    private const MODELS = [
        'claude-sonnet-5-5' => [
            'name' => 'Claude Sonnet 5.5', 'order' => 20, 'tier' => 'balanced',
            'platformIds' => ['anthropic' => 'claude-sonnet-5-5', 'bedrock' => 'anthropic.claude-sonnet-5-5'],
            'prices' => ['input' => 2, 'output' => 10],
        ],
        'claude-sonnet-4-6' => [
            'name' => 'Claude Sonnet 4.6', 'order' => 22, 'tier' => 'balanced', 'platformIds' => ['anthropic' => 'claude-sonnet-4-6'],
            'prices' => ['input' => 3, 'output' => 15],
        ],
    ];

    private function bedrockEu(): AiPlatform
    {
        return AiPlatform::fromArray('bedrock-eu', [
            'name' => 'Amazon Bedrock (EU)', 'dialect' => 'anthropic-messages',
            'baseUrl' => 'https://bedrock-runtime.eu-central-1.amazonaws.com/anthropic/',
            'modelIds' => 'bedrock', 'modelIdPrefix' => 'eu.', 'priceMultiplier' => 1.1, 'order' => 20,
        ]);
    }

    public function testEntryWithNameOnlyGetsTheAnthropicDefaults(): void
    {
        $platform = AiPlatform::fromArray('anthropic', ['name' => 'Anthropic']);
        $this->assertSame('anthropic-messages', $platform->dialect);
        $this->assertSame('', $platform->baseUrl);
        $this->assertSame('anthropic', $platform->modelIds, 'rodina ID = id platformy');
        $this->assertSame('', $platform->modelIdPrefix);
        $this->assertSame(1.0, $platform->priceMultiplier);
        $this->assertSame(1000, $platform->order);

        $fallback = AiPlatform::fallback('bedrock');
        $this->assertSame('bedrock', $fallback->name);
        $this->assertSame('bedrock', $fallback->modelIds);
    }

    public function testFullEntryAndTrailingSlashOfTheBaseUrl(): void
    {
        $platform = $this->bedrockEu();
        $this->assertSame('https://bedrock-runtime.eu-central-1.amazonaws.com/anthropic', $platform->baseUrl, 'bez lomítka na konci');
        $this->assertSame('eu.', $platform->modelIdPrefix);
        $this->assertSame(1.1, $platform->priceMultiplier);
        $this->assertSame('bedrock', $platform->toArray()['modelIds']);
        $this->assertSame('', $platform->keyHint, 'nápovědy formuláře jsou nepovinné');

        $withHints = AiPlatform::fromArray('x', ['keyHint' => ' Klíč z konzole AWS. ', 'baseUrlHint' => 'Prázdné = Frankfurt.']);
        $this->assertSame('Klíč z konzole AWS.', $withHints->keyHint);
        $this->assertSame('Prázdné = Frankfurt.', $withHints->baseUrlHint);
    }

    public function testInvalidEntriesAreRejected(): void
    {
        foreach ([
            ['Bad Id' => []],
            ['x' => ['baseUrl' => 'ftp://x']],
            ['x' => ['modelIdPrefix' => 'eu']],
            ['x' => ['modelIdPrefix' => 'EU.']],
            ['x' => ['priceMultiplier' => 0]],
            ['x' => ['priceMultiplier' => 'drahé']],
            ['x' => 'not an object'],
        ] as $items) {
            try {
                AiPlatforms::fromArray($items);
                $this->fail('expected InvalidArgumentException for ' . json_encode($items));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testModelIdForAndOwnsModelId(): void
    {
        $catalog = ModelCatalog::fromArray(self::MODELS);
        $bedrock = $this->bedrockEu();
        $anthropic = AiPlatform::fromArray('anthropic', ['name' => 'Anthropic']);

        $this->assertSame('eu.anthropic.claude-sonnet-5-5', $bedrock->modelIdFor($catalog->find('claude-sonnet-5-5')));
        $this->assertNull($bedrock->modelIdFor($catalog->find('claude-sonnet-4-6')), 'bez Bedrock ID');
        $this->assertSame('claude-sonnet-4-6', $anthropic->modelIdFor($catalog->find('claude-sonnet-4-6')));

        $this->assertTrue($bedrock->ownsModelId('eu.anthropic.claude-sonnet-5-5', $catalog));
        $this->assertTrue($bedrock->ownsModelId('eu.anthropic.claude-sonnet-5-5-20260928', $catalog), 'i s datovou příponou');
        $this->assertFalse($bedrock->ownsModelId('anthropic.claude-sonnet-5-5', $catalog), 'bez prefixu');
        $this->assertFalse($bedrock->ownsModelId('us.anthropic.claude-sonnet-5-5', $catalog), 'jiný profil');
        $this->assertFalse($bedrock->ownsModelId('claude-sonnet-5-5', $catalog), 'ID přímého API');
        $this->assertFalse($bedrock->ownsModelId('gpt-5', $catalog), 'mimo katalog');

        $this->assertTrue($anthropic->ownsModelId('claude-sonnet-5-5', $catalog));
        $this->assertFalse($anthropic->ownsModelId('anthropic.claude-sonnet-5-5', $catalog));
        $this->assertFalse($anthropic->ownsModelId('eu.anthropic.claude-sonnet-5-5', $catalog));
    }

    public function testMapFromConfigAndWithout(): void
    {
        $cfg = $this->createMock(ConfigRuntime::class);
        $cfg->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $id === AiPlatform::CFG_ITEM
            ? ['bedrock-eu' => ['name' => 'Bedrock EU', 'order' => 20], 'anthropic' => ['name' => 'Anthropic', 'order' => 10]]
            : null);
        $platforms = AiPlatforms::fromConfig($cfg);

        $this->assertFalse($platforms->isEmpty());
        $this->assertTrue($platforms->has('anthropic'));
        $this->assertFalse($platforms->has('openai'));
        $this->assertSame(['anthropic', 'bedrock-eu'], array_map(static fn (AiPlatform $p): string => $p->id, $platforms->all()), 'řazení podle order');
        $this->assertSame('Bedrock EU', $platforms->label('bedrock-eu'));
        $this->assertSame('openai', $platforms->label('openai'), 'bez položky klíč');
        $this->assertSame('openai', $platforms->getOrFallback('openai')->id);
        $this->assertNull($platforms->get('openai'));

        $empty = AiPlatforms::fromConfig(null);
        $this->assertTrue($empty->isEmpty());
        $this->assertSame('', $empty->getOrFallback('anthropic')->baseUrl, 'bez configu bez adresy — klient má výchozí');
    }
}
