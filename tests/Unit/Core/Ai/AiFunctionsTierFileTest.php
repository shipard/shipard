<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Ai;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiBackendResolver;
use Shipard\Core\Ai\AiFunctionRegistry;
use Shipard\Core\Ai\AiFunctionResolver;
use Shipard\Core\Ai\AiPlatform;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Ai\ModelSpec;
use Shipard\Core\Ai\RequestChange;
use Shipard\Core\Ai\RequestShaper;
use Shipard\Core\Ai\ResolvedAiCall;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Skutečné deklarace AI funkcí (`aiFunctions` v module.jsonc všech modulů)
 * × skutečný katalog `models.jsonc` a mapa úrovní `tiers.jsonc`
 * (tasks/ai-tiers-series5.md, #103 D47–D50): které funkce kterou úroveň
 * používají, jaký model jim mapa dnes dává a co s parametry deklarace
 * udělá tvarovač — řada 5 teplotu nepřijímá, takže `temperature 0`
 * vytěžení se vynechá (`TEMPERATURE_OMITTED`), ostatní funkce teplotu
 * nedeklarují a jdou bez úprav. Totéž na platformě `bedrock-eu`.
 */
final class AiFunctionsTierFileTest extends TestCase
{
    /** Které funkce kterou úroveň používají — tabulka z tasks/ai-tiers-series5.md. */
    private const array FUNCTIONS_BY_TIER = [
        ModelSpec::TIER_BALANCED => ['mail.extraction', 'exchange.bookingHistory', 'chat.default'],
        ModelSpec::TIER_FAST => ['exchange.contentTags', 'dashboard.summary'],
        ModelSpec::TIER_STRONG => [],
    ];

    /** Model úrovně na přímém API (katalog nemá pro Bedrock jiný). */
    private const array TIER_MODELS = [
        ModelSpec::TIER_FAST => 'claude-sonnet-5-5',
        ModelSpec::TIER_BALANCED => 'claude-sonnet-5-5',
        ModelSpec::TIER_STRONG => 'claude-opus-5-5',
    ];

    private const array ANTHROPIC_CONNECTION = ['id' => 1, 'backend_id' => 'default', 'name' => 'Anthropic', 'provider' => 'anthropic',
        'model' => null, 'base_url' => null, 'api_key' => 'enc', 'is_default' => 1, 'is_active' => 1];
    private const array BEDROCK_CONNECTION = ['id' => 2, 'backend_id' => 'bedrock-eu', 'name' => 'Bedrock EU', 'provider' => 'bedrock-eu',
        'model' => null, 'base_url' => null, 'api_key' => 'enc2', 'is_default' => 1, 'is_active' => 1];

    private static ?ModulePathResolver $modules = null;

    protected function setUp(): void
    {
        ErrorLogger::setLogLevel('error');
        AiFunctionResolver::resetWarningsForTesting();
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
    }

    private static function modules(): ModulePathResolver
    {
        return self::$modules ??= new ModulePathResolver([dirname(__DIR__, 4) . '/modules']);
    }

    /** @return array<string, mixed> surový cfgItem modulu core.ai z repozitáře */
    private static function coreAiItem(string $cfgItemId): array
    {
        foreach (ModuleLoader::loadAllModules(self::modules()) as $module) {
            if ($module->id !== 'core.ai') {
                continue;
            }
            foreach ($module->config as $entry) {
                if (($entry['id'] ?? null) === $cfgItemId) {
                    return JsoncParser::parseFile(self::modules()->getPath($module->id) . '/' . $entry['file']);
                }
            }
        }
        self::fail('core.ai neregistruje cfgItem ' . $cfgItemId);
    }

    /**
     * Registr ze všech modulů v repu — stejné složení jako ConfigCompiler
     * (cfgItem `core.ai.functions`), bez lokalizace.
     *
     * @param array<string, array<string, mixed>> $extra další funkce id → deklarace (test úrovně bez funkce)
     */
    private static function registry(array $extra = []): AiFunctionRegistry
    {
        $items = [];
        foreach (ModuleLoader::loadAllModules(self::modules()) as $module) {
            foreach ($module->aiFunctions as $function) {
                $id = (string) $function['id'];
                self::assertArrayNotHasKey($id, $items, "AI funkce '{$id}' deklarovaná víckrát");
                unset($function['id']);
                $items[$id] = $function;
            }
        }
        return AiFunctionRegistry::fromArray($items + $extra);
    }

    private static function catalog(): ModelCatalog
    {
        return ModelCatalog::fromArray(self::coreAiItem(ModelCatalog::CFG_ITEM), self::coreAiItem(ModelCatalog::TIERS_CFG_ITEM));
    }

    /**
     * Resolver bez řádků nastavení funkcí (žádné přepsání) nad jedním
     * výchozím připojením dané platformy.
     *
     * @param array<string, mixed> $connection
     */
    private function resolver(array $connection, ?AiFunctionRegistry $registry = null): AiFunctionResolver
    {
        $platforms = self::coreAiItem(AiPlatform::CFG_ITEM);
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $id === AiPlatform::CFG_ITEM ? $platforms : null);

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $backends = $this->createMock(AiBackendResolver::class);
        $backends->method('defaultBackend')->willReturn($connection);
        $backends->method('backendByNdx')->willReturnCallback(static fn (int $ndx): ?array => $ndx === (int) $connection['id'] ? $connection : null);

        return new AiFunctionResolver($db, $config, null, $registry ?? self::registry(), self::catalog(), $backends);
    }

    public function testDeclaredFunctionsUseTheTiersFromTheTask(): void
    {
        $byTier = array_fill_keys(ModelSpec::TIERS, []);
        foreach (self::registry()->all() as $function) {
            $byTier[$function->tier][] = $function->id;
        }
        foreach ($byTier as &$ids) {
            sort($ids);
        }
        unset($ids);
        $expected = [];
        foreach (ModelSpec::TIERS as $tier) {
            $expected[$tier] = self::FUNCTIONS_BY_TIER[$tier];
            sort($expected[$tier]);
        }

        $this->assertSame($expected, $byTier, 'tabulka úroveň → funkce v tasks/ai-tiers-series5.md neodpovídá deklaracím');
    }

    /** @return iterable<string, array{string, string}> funkce → platforma */
    public static function functionsOnPlatforms(): iterable
    {
        foreach (self::FUNCTIONS_BY_TIER as $functionIds) {
            foreach ($functionIds as $functionId) {
                yield "{$functionId} / anthropic" => [$functionId, 'anthropic'];
                yield "{$functionId} / bedrock-eu" => [$functionId, 'bedrock-eu'];
            }
        }
    }

    #[DataProvider('functionsOnPlatforms')]
    public function testEveryFunctionGetsASeries5ModelAndNoTemperature(string $functionId, string $platform): void
    {
        $connection = $platform === 'bedrock-eu' ? self::BEDROCK_CONNECTION : self::ANTHROPIC_CONNECTION;
        $resolver = $this->resolver($connection);
        $function = $resolver->registry()->get($functionId);
        $this->assertNotNull($function);
        $tierModel = self::TIER_MODELS[$function->tier];

        $preview = $resolver->preview($functionId);
        $this->assertTrue($preview->isOk(), (string) $preview->error?->getMessage());
        $plan = $preview->plan;
        $this->assertNotNull($plan);

        // Model z mapy úrovní, na Bedrocku s profilem `eu.`, shapeAs netřeba (model je z katalogu).
        $this->assertSame(ResolvedAiCall::ORIGIN_TIER, $plan->origins['model']);
        $this->assertSame($tierModel, $plan->catalogModel);
        $this->assertSame($platform === 'bedrock-eu' ? "eu.anthropic.{$tierModel}" : $tierModel, $plan->model);
        $this->assertSame($platform, $plan->platform);

        // Deklarace: thinking / effort se neposílají (výchozí hodnoty modelu, D47),
        // teplotu 0 má jen vytěžení — a tu tvarovač u řady 5 vynechá.
        $this->assertNull($plan->thinking);
        $this->assertNull($plan->effort);
        $this->assertSame($functionId === 'mail.extraction' ? 0.0 : null, $plan->temperature, 'teplota deklarace');
        $this->assertSame(
            $functionId === 'mail.extraction' ? [RequestChange::TEMPERATURE_OMITTED] : [],
            array_map(static fn (RequestChange $c): string => $c->code, $preview->changes),
        );

        $spec = $resolver->catalog()->find($plan->catalogModel);
        $this->assertNotNull($spec);
        $this->assertFalse($spec->temperature, "{$tierModel} teplotu nepřijímá");
        [$shaped] = RequestShaper::shape($plan->previewParams(), $spec);
        $this->assertNull($shaped->temperature);
        $this->assertNull($shaped->thinking);
        $this->assertNull($shaped->effort);
        $this->assertSame($function->maxTokens, $shaped->maxTokens, 'strop deklarace pod mezí modelu');
    }

    public function testStrongTierGivesOpusAndDropsTemperatureToo(): void
    {
        $strong = ['name' => 'Strong test', 'tier' => ModelSpec::TIER_STRONG, 'defaults' => ['maxTokens' => 4000, 'temperature' => 0]];
        foreach (['anthropic' => self::ANTHROPIC_CONNECTION, 'bedrock-eu' => self::BEDROCK_CONNECTION] as $platform => $connection) {
            $preview = $this->resolver($connection, self::registry(['test.strong' => $strong]))->preview('test.strong');
            $this->assertTrue($preview->isOk(), (string) $preview->error?->getMessage());
            $this->assertSame('claude-opus-5-5', $preview->plan?->catalogModel, $platform);
            $this->assertSame($platform === 'bedrock-eu' ? 'eu.anthropic.claude-opus-5-5' : 'claude-opus-5-5', $preview->plan?->model, $platform);
            $this->assertSame([RequestChange::TEMPERATURE_OMITTED], array_map(static fn (RequestChange $c): string => $c->code, $preview->changes), $platform);
        }
    }
}
