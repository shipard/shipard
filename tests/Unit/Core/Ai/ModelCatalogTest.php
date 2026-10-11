<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Ai;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Ai\ModelSpec;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Katalog modelů nad inline fixturou (tasks/ai-model-catalog.md D30, D33,
 * D34): shoda ID s datovou příponou i s prefixem platformy, nejdelší
 * kandidát vyhrává, prefix sám nestačí; výchozí model úrovně; náhrada
 * vyřazeného modelu jen přes ID přímého API; ceny včetně dvou pásem Haiku
 * 5.5 (případy převzaté z bývalého AnthropicPricingTest); prázdný katalog.
 */
final class ModelCatalogTest extends TestCase
{
    private const FIXTURE = [
        'claude-opus-5-5' => [
            'name' => 'Claude Opus 5.5', 'order' => 10, 'tier' => 'strong',
            'platformIds' => ['anthropic' => 'claude-opus-5-5', 'bedrock' => 'anthropic.claude-opus-5-5'],
            'capabilities' => ['temperature' => false, 'thinkingModes' => ['adaptive'], 'thinkingDefault' => 'adaptive',
                'effortLevels' => ['low', 'medium', 'high', 'xhigh', 'max'], 'maxOutputTokens' => 128000],
            'prices' => ['input' => 4, 'output' => 20],
            'lifecycle' => ['status' => 'active', 'retiresNotBefore' => '2027-09-22'],
        ],
        'claude-opus-4-8' => [
            'name' => 'Claude Opus 4.8', 'order' => 12, 'tier' => 'strong',
            'platformIds' => ['anthropic' => 'claude-opus-4-8', 'bedrock' => 'anthropic.claude-opus-4-8'],
            'prices' => ['input' => 5, 'output' => 25],
        ],
        'claude-opus-4-1' => [
            'name' => 'Claude Opus 4.1', 'order' => 113,
            'platformIds' => ['anthropic' => 'claude-opus-4-1'],
            'prices' => ['input' => 15, 'output' => 75],
            // Vyřazený bez náhrady — přepis se nekoná.
            'lifecycle' => ['status' => 'retired', 'retiresAt' => '2026-08-05'],
        ],
        'claude-opus-4' => [
            'name' => 'Claude Opus 4', 'order' => 114,
            'platformIds' => ['anthropic' => 'claude-opus-4'],
            'prices' => ['input' => 15, 'output' => 75],
            'lifecycle' => ['status' => 'retired', 'retiresAt' => '2026-06-15', 'replacedBy' => 'claude-opus-4-8'],
        ],
        'claude-sonnet-5-5' => [
            'name' => 'Claude Sonnet 5.5', 'order' => 20, 'tier' => 'balanced',
            'platformIds' => ['anthropic' => 'claude-sonnet-5-5', 'bedrock' => 'anthropic.claude-sonnet-5-5'],
            'capabilities' => ['temperature' => false, 'thinkingModes' => ['adaptive', 'between_tools'], 'thinkingDefault' => 'adaptive',
                'effortLevels' => ['low', 'medium', 'high', 'xhigh', 'max'], 'thinkingOffMaxEffort' => 'high',
                'maxOutputTokens' => 128000],
            'prices' => ['input' => 2, 'output' => 10],
        ],
        'claude-sonnet-4-6' => [
            'name' => 'Claude Sonnet 4.6', 'order' => 22, 'tier' => 'balanced',
            'platformIds' => ['anthropic' => 'claude-sonnet-4-6'],
            'capabilities' => ['temperature' => true, 'thinkingModes' => ['adaptive', 'disabled', 'enabled'], 'thinkingDefault' => 'off',
                'effortLevels' => ['low', 'medium', 'high', 'max'], 'maxOutputTokens' => 128000],
            'prices' => ['input' => 3, 'output' => 15],
        ],
        'claude-sonnet-4-5' => [
            'name' => 'Claude Sonnet 4.5', 'order' => 23, 'tier' => 'balanced',
            'platformIds' => ['anthropic' => 'claude-sonnet-4-5', 'bedrock' => 'anthropic.claude-sonnet-4-5'],
            'prices' => ['input' => 3, 'output' => 15],
            'lifecycle' => ['status' => 'deprecated', 'retiresAt' => '2026-11-30', 'replacedBy' => 'claude-sonnet-4-6'],
        ],
        'claude-sonnet-4' => [
            'name' => 'Claude Sonnet 4', 'order' => 120,
            'platformIds' => ['anthropic' => 'claude-sonnet-4'],
            'prices' => ['input' => 3, 'output' => 15],
        ],
        'claude-haiku-5-5' => [
            'name' => 'Claude Haiku 5.5', 'order' => 30, 'tier' => 'fast',
            'platformIds' => ['anthropic' => 'claude-haiku-5-5', 'bedrock' => 'anthropic.claude-haiku-5-5'],
            'prices' => ['input' => 0.10, 'output' => 0.50, 'longPrompt' => ['over' => 100000, 'input' => 0.50, 'output' => 2.50]],
        ],
        'claude-fable-5-1' => [
            'name' => 'Claude Fable 5.1', 'order' => 100,
            'platformIds' => ['anthropic' => 'claude-fable-5-1'],
            'prices' => ['input' => 10, 'output' => 50],
        ],
    ];

    protected function setUp(): void
    {
        ErrorLogger::setLogLevel('error');
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
    }

    /** Mapa úrovní jako v repu (D37): fast a balanced, strong záměrně bez výchozího; balanced s modelem pro Bedrock EU (D58). */
    private const TIERS = [
        'balanced' => ['name' => 'Balanced', 'order' => 20, 'model' => 'claude-sonnet-4-6', 'platformModels' => ['bedrock-eu' => 'claude-sonnet-5-5']],
        'fast' => ['name' => 'Fast', 'order' => 10, 'model' => 'claude-haiku-5-5'],
    ];

    private function catalog(): ModelCatalog
    {
        return ModelCatalog::fromArray(self::FIXTURE, self::TIERS);
    }

    // -------------------------------------------------------------- find()

    public function testFindExactAndDatedIds(): void
    {
        $catalog = $this->catalog();

        $this->assertSame('claude-sonnet-4-6', $catalog->find('claude-sonnet-4-6')?->id);
        $this->assertSame('claude-sonnet-4-5', $catalog->find('claude-sonnet-4-5-20250929')?->id);
        $this->assertSame('claude-opus-4', $catalog->find('claude-opus-4-20250514')?->id);
        $this->assertSame('claude-opus-4-1', $catalog->find('claude-opus-4-1-20250805')?->id);
        $this->assertSame('claude-sonnet-4-5', $catalog->find('  claude-sonnet-4-5 ')?->id, 'okraje se ořezávají');
    }

    public function testFindAcceptsPlatformIds(): void
    {
        $catalog = $this->catalog();

        $this->assertSame('claude-sonnet-5-5', $catalog->find('anthropic.claude-sonnet-5-5')?->id);
        $this->assertSame('claude-opus-5-5', $catalog->find('anthropic.claude-opus-5-5-20260922')?->id);
        // Model bez Bedrock ID se přes prefix platformy nenajde.
        $this->assertNull($catalog->find('anthropic.claude-sonnet-4-6'));
    }

    public function testLongestCandidateWinsAndPrefixAloneIsNotAMatch(): void
    {
        $catalog = $this->catalog();

        // `claude-opus-4` (15 / 75) nesmí chytit `claude-opus-4-8` (5 / 25)…
        $this->assertSame('claude-opus-4-8', $catalog->find('claude-opus-4-8')?->id);
        $this->assertSame('claude-opus-4-8', $catalog->find('claude-opus-4-8-20260301')?->id);
        // …ani `claude-sonnet-4` model `claude-sonnet-4-5-…`.
        $this->assertSame('claude-sonnet-4-5', $catalog->find('claude-sonnet-4-5-20260101')?->id);
        // Prefix bez datové přípony není shoda.
        $this->assertNull($catalog->find('claude-sonnet-4-50'));
        $this->assertNull($catalog->find('claude-opus-4-80'));
        $this->assertNull($catalog->find('claude-opus-4-8-2026030'), 'datová přípona má 8 číslic');
        $this->assertNull($catalog->find('claude-opus-4-8-latest'));
    }

    public function testPlatformOfTellsWhichPlatformAnIdBelongsTo(): void
    {
        $catalog = $this->catalog();

        $this->assertSame('anthropic', $catalog->platformOf('claude-sonnet-5-5'));
        $this->assertSame('anthropic', $catalog->platformOf('claude-sonnet-4-5-20250929'));
        $this->assertSame('bedrock', $catalog->platformOf('anthropic.claude-sonnet-5-5'));
        $this->assertNull($catalog->platformOf('gpt-5'));
    }

    public function testFindAcceptsGeographicProfilePrefixesOfBedrock(): void
    {
        // tasks/ai-bedrock-eu.md D57: `eu.anthropic.…` je ID rodiny bedrock
        // s geografickým profilem — kvůli ceně, tvarování a ručně zadanému ID.
        $catalog = $this->catalog();

        $this->assertSame('claude-sonnet-5-5', $catalog->find('eu.anthropic.claude-sonnet-5-5')?->id);
        $this->assertSame('claude-opus-5-5', $catalog->find('global.anthropic.claude-opus-5-5-20260922')?->id, 'i s datovou příponou');
        $this->assertSame('claude-haiku-5-5', $catalog->find('us.anthropic.claude-haiku-5-5')?->id);
        $this->assertSame('bedrock', $catalog->platformOf('eu.anthropic.claude-sonnet-5-5'));
        $this->assertSame('eu.', $catalog->geoPrefixOf('eu.anthropic.claude-sonnet-5-5'));
        $this->assertSame('us.', $catalog->geoPrefixOf('us.anthropic.claude-haiku-5-5'));
        $this->assertSame('', $catalog->geoPrefixOf('anthropic.claude-sonnet-5-5'));
        $this->assertSame('', $catalog->geoPrefixOf('claude-sonnet-5-5'));
        $this->assertNull($catalog->geoPrefixOf('gpt-5'));

        // Prefix bez ID rodiny bedrock nic nechytí: model bez Bedrock ID, ID přímého API, neznámý prefix.
        $this->assertNull($catalog->find('eu.anthropic.claude-sonnet-4-6'));
        $this->assertNull($catalog->find('eu.claude-sonnet-5-5'));
        $this->assertNull($catalog->find('mars.anthropic.claude-sonnet-5-5'));
        // Náhrada vyřazeného modelu se ID s prefixem netýká (jen rodina anthropic).
        $this->assertNull($catalog->retiredReplacement('eu.anthropic.claude-sonnet-4-5'));
    }

    public function testFindUnknownOrEmptyIsNull(): void
    {
        $catalog = $this->catalog();

        $this->assertNull($catalog->find('gpt-5'));
        $this->assertNull($catalog->find(''));
        $this->assertNull(ModelCatalog::empty()->find('claude-sonnet-4-6'));
    }

    // ------------------------------------------------------ defaultModel()

    public function testDefaultModelComesFromTheTierMap(): void
    {
        $catalog = $this->catalog();

        $this->assertSame('claude-sonnet-4-6', $catalog->defaultModel(ModelSpec::TIER_BALANCED));
        $this->assertSame('claude-haiku-5-5', $catalog->defaultModel(ModelSpec::TIER_FAST));
        $this->assertNull($catalog->defaultModel(ModelSpec::TIER_STRONG), 'úroveň mimo mapu je bez výchozího');
        $this->assertNull($catalog->defaultModel('nonsense'));
        $this->assertNull(ModelCatalog::empty()->defaultModel(ModelSpec::TIER_BALANCED));
        // Katalog bez mapy úrovní (compiled config před přidáním core.ai.tiers).
        $this->assertNull(ModelCatalog::fromArray(self::FIXTURE)->defaultModel(ModelSpec::TIER_BALANCED));
    }

    public function testDefaultModelPerPlatform(): void
    {
        // tasks/ai-bedrock-eu.md D58: položka pro platformu má přednost, bez ní obecný model.
        $catalog = $this->catalog();

        $this->assertSame('claude-sonnet-5-5', $catalog->defaultModel(ModelSpec::TIER_BALANCED, 'bedrock-eu'));
        $this->assertSame('claude-sonnet-4-6', $catalog->defaultModel(ModelSpec::TIER_BALANCED, 'anthropic'));
        $this->assertSame('claude-sonnet-4-6', $catalog->defaultModel(ModelSpec::TIER_BALANCED, null));
        $this->assertSame('claude-haiku-5-5', $catalog->defaultModel(ModelSpec::TIER_FAST, 'bedrock-eu'), 'úroveň bez položky pro platformu');
        $this->assertNull($catalog->defaultModel(ModelSpec::TIER_STRONG, 'bedrock-eu'));
        $this->assertSame(['bedrock-eu' => 'claude-sonnet-5-5'], $catalog->tiers()['balanced']['platformModels']);
        $this->assertSame([], $catalog->tiers()['fast']['platformModels']);

        foreach ([
            ['platformModels' => 'claude-sonnet-5-5'],
            ['platformModels' => ['bedrock-eu' => '']],
            ['platformModels' => ['' => 'claude-sonnet-5-5']],
            ['platformModels' => [0 => 'claude-sonnet-5-5']],
        ] as $extra) {
            try {
                ModelCatalog::fromArray(self::FIXTURE, ['fast' => ['model' => 'claude-haiku-5-5'] + $extra]);
                $this->fail('nevalidní platformModels musí být chyba: ' . json_encode($extra));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTiersAreOrderedAndValidated(): void
    {
        $this->assertSame(['fast', 'balanced'], array_keys($this->catalog()->tiers()));
        $this->assertSame('claude-haiku-5-5', $this->catalog()->tiers()['fast']['model']);
        $this->assertSame('Fast', $this->catalog()->tiers()['fast']['name']);
        $this->assertSame([], ModelCatalog::empty()->tiers());

        try {
            ModelCatalog::fromArray(self::FIXTURE, ['huge' => ['model' => 'claude-opus-5-5']]);
            $this->fail('neznámá úroveň musí být chyba');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("unknown tier 'huge'", $e->getMessage());
        }
        try {
            ModelCatalog::fromArray(self::FIXTURE, ['fast' => ['name' => 'Fast']]);
            $this->fail('úroveň bez modelu musí být chyba');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("tier 'fast' must declare a model", $e->getMessage());
        }
    }

    // ------------------------------------------------ retiredReplacement()

    public function testRetiredReplacementMatchesAnthropicIdsOnly(): void
    {
        $catalog = $this->catalog();

        $this->assertSame('claude-sonnet-4-6', $catalog->retiredReplacement('claude-sonnet-4-5'));
        $this->assertSame('claude-sonnet-4-6', $catalog->retiredReplacement('claude-sonnet-4-5-20250929'));
        $this->assertSame('claude-opus-4-8', $catalog->retiredReplacement('claude-opus-4-20250514'));
        // Aktivní model, neznámý model, prefix bez datové přípony.
        $this->assertNull($catalog->retiredReplacement('claude-sonnet-4-6'));
        $this->assertNull($catalog->retiredReplacement('claude-sonnet-4-50'));
        $this->assertNull($catalog->retiredReplacement('claude-opus-4-8'));
        $this->assertNull($catalog->retiredReplacement('gpt-5'));
        // Vyřazený bez `replacedBy` se nepřepisuje.
        $this->assertNull($catalog->retiredReplacement('claude-opus-4-1'));
        // ID s prefixem platformy se nemění — partnerské platformy mají vlastní termíny.
        $this->assertNull($catalog->retiredReplacement('anthropic.claude-sonnet-4-5'));
        $this->assertNull($catalog->retiredReplacement('anthropic.claude-sonnet-4-5-20250929'));
    }

    // -------------------------------------------------- rates() / costUsd()

    /** Sazby per model — prefix i ID s datovou příponou (bývalý AnthropicPricingTest). */
    public function testRatesPerModel(): void
    {
        $catalog = $this->catalog();
        $expected = [
            'claude-sonnet-5-5' => [2.0, 10.0],
            'claude-sonnet-4-6' => [3.0, 15.0],
            'claude-sonnet-4-5' => [3.0, 15.0],
            'claude-sonnet-4' => [3.0, 15.0],
            'claude-haiku-5-5' => [0.10, 0.50],
            'claude-opus-5-5' => [4.0, 20.0],
            'claude-opus-4-8' => [5.0, 25.0],
            'claude-opus-4-1' => [15.0, 75.0],
            'claude-opus-4' => [15.0, 75.0],
            'claude-fable-5-1' => [10.0, 50.0],
        ];
        foreach ($expected as $model => $rates) {
            $this->assertSame($rates, $catalog->rates($model), $model);
            $this->assertSame($rates, $catalog->rates($model . '-20260101'), $model . ' (dated)');
        }
        $this->assertSame([5.0, 25.0], $catalog->rates('claude-opus-4-8-20260301'));
        $this->assertSame([15.0, 75.0], $catalog->rates('claude-opus-4-20250514'));
        $this->assertSame([2.0, 10.0], $catalog->rates('anthropic.claude-sonnet-5-5'));
    }

    public function testHaiku55HasALongPromptTier(): void
    {
        $catalog = $this->catalog();

        $this->assertSame([0.10, 0.50], $catalog->rates('claude-haiku-5-5', 100_000));
        $this->assertSame([0.50, 2.50], $catalog->rates('claude-haiku-5-5', 100_001));
        $this->assertSame(0.000003, $catalog->costUsd('claude-haiku-5-5', 10, 4));
        // 150 000 vstupních × 0,50 + 1 000 výstupních × 2,50 = 0,0775 USD.
        $this->assertSame(0.0775, $catalog->costUsd('claude-haiku-5-5', 150_000, 1_000));
        // Jiný model má jedno pásmo bez ohledu na délku promptu.
        $this->assertSame([2.0, 10.0], $catalog->rates('claude-sonnet-5-5', 500_000));
    }

    public function testCostIsPerMillionTokensRoundedToSixPlaces(): void
    {
        $catalog = $this->catalog();

        $this->assertSame(0.0105, $catalog->costUsd('claude-sonnet-4-6', 1000, 500));
        $this->assertSame(0.0, $catalog->costUsd('claude-sonnet-4-6', 0, 0));
        $this->assertSame(0.000003, $catalog->costUsd('claude-sonnet-4-6', 1, 0));
        $this->assertSame(0.007, $catalog->costUsd('claude-sonnet-5-5', 1000, 500));
        $this->assertSame(0.0105, $catalog->costUsd('claude-sonnet-4-5-20260101', 1000, 500));
    }

    public function testUnknownModelOrEmptyCatalogCostsZero(): void
    {
        $catalog = $this->catalog();

        $this->assertNull($catalog->rates('gpt-5'));
        $this->assertSame(0.0, $catalog->costUsd('gpt-5', 1000, 1000));
        $this->assertSame(0.0, ModelCatalog::empty()->costUsd('claude-sonnet-4-6', 1000, 1000));
    }

    public function testEntryWithoutPricesHasNoRates(): void
    {
        $catalog = ModelCatalog::fromArray([
            'claude-test' => ['name' => 'Test', 'platformIds' => ['anthropic' => 'claude-test']],
        ]);

        $this->assertNotNull($catalog->find('claude-test'));
        $this->assertFalse($catalog->find('claude-test')->hasPrices());
        $this->assertNull($catalog->rates('claude-test'));
        $this->assertSame(0.0, $catalog->costUsd('claude-test', 1000, 1000));
    }

    // ------------------------------------------------------ konstrukce

    public function testFromConfigReadsTheCfgItemAndTolerateMissing(): void
    {
        $this->assertTrue(ModelCatalog::fromConfig(null)->isEmpty());

        $cfg = $this->createMock(ConfigRuntime::class);
        $cfg->method('cfgItem')->willReturnCallback(
            fn (string $id) => match ($id) {
                ModelCatalog::CFG_ITEM => self::FIXTURE,
                ModelCatalog::TIERS_CFG_ITEM => self::TIERS,
                default => null,
            },
        );
        $catalog = ModelCatalog::fromConfig($cfg);
        $this->assertFalse($catalog->isEmpty());
        $this->assertSame('claude-sonnet-4-6', $catalog->defaultModel('balanced'));

        // Config s katalogem, ale bez mapy úrovní — katalog ano, výchozí model ne.
        $noTiers = $this->createMock(ConfigRuntime::class);
        $noTiers->method('cfgItem')->willReturnCallback(
            fn (string $id) => $id === ModelCatalog::CFG_ITEM ? self::FIXTURE : null,
        );
        $this->assertFalse(ModelCatalog::fromConfig($noTiers)->isEmpty());
        $this->assertNull(ModelCatalog::fromConfig($noTiers)->defaultModel('balanced'));

        $without = $this->createMock(ConfigRuntime::class);
        $without->method('cfgItem')->willReturn(null);
        $this->assertTrue(ModelCatalog::fromConfig($without)->isEmpty());
    }

    public function testAllIsSortedByOrderThenId(): void
    {
        $ids = array_map(static fn (ModelSpec $s): string => $s->id, $this->catalog()->all());

        $this->assertSame([
            'claude-opus-5-5', 'claude-opus-4-8', 'claude-sonnet-5-5', 'claude-sonnet-4-6', 'claude-sonnet-4-5',
            'claude-haiku-5-5', 'claude-fable-5-1', 'claude-opus-4-1', 'claude-opus-4', 'claude-sonnet-4',
        ], $ids);
    }

    public function testSpecReadsCapabilitiesAndNullMeansUnknown(): void
    {
        $catalog = $this->catalog();
        $sonnet55 = $catalog->find('claude-sonnet-5-5');
        $this->assertNotNull($sonnet55);
        $this->assertFalse($sonnet55->temperature);
        $this->assertSame(['adaptive', 'between_tools'], $sonnet55->thinkingModes);
        $this->assertSame('adaptive', $sonnet55->thinkingDefault);
        $this->assertSame('high', $sonnet55->thinkingOffMaxEffort);
        $this->assertSame(128000, $sonnet55->maxOutputTokens);
        $this->assertSame('anthropic', $sonnet55->vendor);
        $this->assertSame('anthropic-messages', $sonnet55->dialect);
        $this->assertSame('active', $sonnet55->status);
        $this->assertFalse($sonnet55->isRetiring());

        // Položka jen s cenami: všechny schopnosti null.
        $opus48 = $catalog->find('claude-opus-4-8');
        $this->assertNotNull($opus48);
        $this->assertNull($opus48->temperature);
        $this->assertNull($opus48->thinkingModes);
        $this->assertNull($opus48->thinkingDefault);
        $this->assertNull($opus48->effortLevels);
        $this->assertNull($opus48->thinkingOffMaxEffort);
        $this->assertNull($opus48->maxOutputTokens);

        $sonnet45 = $catalog->find('claude-sonnet-4-5');
        $this->assertTrue($sonnet45?->isRetiring());
        $this->assertSame('2026-11-30', $sonnet45->retiresAt);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidSpecs(): iterable
    {
        $base = ['name' => 'X', 'platformIds' => ['anthropic' => 'claude-x']];
        yield 'unknown tier' => [$base + ['tier' => 'huge']];
        yield 'unknown status' => [$base + ['lifecycle' => ['status' => 'gone']]];
        yield 'negative price' => [$base + ['prices' => ['input' => -1, 'output' => 5]]];
        yield 'only one price' => [$base + ['prices' => ['input' => 1]]];
        yield 'bad date' => [$base + ['lifecycle' => ['status' => 'active', 'retiresNotBefore' => '2027/01/01']]];
        yield 'thinkingOffMaxEffort outside levels' => [$base + ['capabilities' => ['effortLevels' => ['low'], 'thinkingOffMaxEffort' => 'high']]];
        yield 'unknown thinkingDefault' => [$base + ['capabilities' => ['thinkingDefault' => 'always']]];
        yield 'replacedBy self' => [$base + ['lifecycle' => ['status' => 'retired', 'replacedBy' => 'claude-x']]];
        yield 'missing name' => [['platformIds' => ['anthropic' => 'claude-x']]];
        yield 'longPrompt without over' => [$base + ['prices' => ['input' => 1, 'output' => 2, 'longPrompt' => ['input' => 2, 'output' => 3]]]];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('invalidSpecs')]
    public function testInvalidSpecIsRejected(array $data): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModelSpec::fromArray('claude-x', $data);
    }

    public function testInvalidEntryShapeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModelCatalog::fromArray(['claude-x' => 'not an object']);
    }
}
