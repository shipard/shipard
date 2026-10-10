<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiBackendResolver;
use Shipard\Core\Ai\AiCallOverride;
use Shipard\Core\Ai\AiFunctionRegistry;
use Shipard\Core\Ai\AiFunctionResolver;
use Shipard\Core\Ai\Exception\AiFunctionException;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Ai\ResolvedAiCall;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Security\Exception\InvalidCiphertextException;
use Shipard\Core\Security\Exception\SecretsKeyMissingException;

/**
 * AiFunctionResolver (tasks/ai-functions.md D40, D43): pořadí zdrojů pro
 * každou hodnotu (přepsání → řádek funkce → deklarace / mapa úrovní),
 * výchozí a přepsané připojení, neaktivní připojení, chybějící klíč,
 * vlastní model s `model_like` a bez něj, model mimo platformu, funkce
 * s `tools` na modelu bez tool smyčky, `default` vs `auto`, prázdný
 * katalog, tělo chatParams.
 */
final class AiFunctionResolverTest extends TestCase
{
    private const FUNCTIONS = [
        'mail.extraction' => ['name' => 'Vytěžení', 'tier' => 'balanced', 'defaults' => ['maxTokens' => 32768, 'temperature' => 0.0], 'requires' => ['pdf']],
        'chat.default' => ['name' => 'Chat', 'tier' => 'balanced', 'defaults' => ['maxTokens' => 4096], 'requires' => ['tools']],
        'dashboard.summary' => ['name' => 'Shrnutí', 'tier' => 'fast', 'defaults' => ['maxTokens' => 2000]],
    ];

    private const MODELS = [
        'claude-sonnet-5-5' => [
            'name' => 'Claude Sonnet 5.5', 'order' => 20, 'tier' => 'balanced',
            'platformIds' => ['anthropic' => 'claude-sonnet-5-5', 'bedrock' => 'anthropic.claude-sonnet-5-5'],
            'capabilities' => ['temperature' => false, 'thinkingModes' => ['adaptive', 'between_tools'], 'thinkingDefault' => 'adaptive',
                'effortLevels' => ['low', 'medium', 'high', 'xhigh', 'max'], 'thinkingOffMaxEffort' => 'high', 'maxOutputTokens' => 128000, 'chatToolLoop' => false],
            'prices' => ['input' => 2, 'output' => 10],
        ],
        'claude-sonnet-4-6' => [
            'name' => 'Claude Sonnet 4.6', 'order' => 22, 'tier' => 'balanced', 'platformIds' => ['anthropic' => 'claude-sonnet-4-6'],
            'capabilities' => ['temperature' => true, 'thinkingModes' => ['adaptive', 'disabled'], 'thinkingDefault' => 'off',
                'effortLevels' => ['low', 'medium', 'high', 'max'], 'maxOutputTokens' => 128000, 'chatToolLoop' => true],
            'prices' => ['input' => 3, 'output' => 15],
        ],
        'claude-sonnet-4-5' => [
            'name' => 'Claude Sonnet 4.5', 'order' => 23, 'tier' => 'balanced', 'platformIds' => ['anthropic' => 'claude-sonnet-4-5'],
            'prices' => ['input' => 3, 'output' => 15],
            'lifecycle' => ['status' => 'deprecated', 'retiresAt' => '2026-11-30', 'replacedBy' => 'claude-sonnet-4-6'],
        ],
        'claude-haiku-5-5' => [
            'name' => 'Claude Haiku 5.5', 'order' => 30, 'tier' => 'fast',
            'platformIds' => ['anthropic' => 'claude-haiku-5-5', 'bedrock' => 'anthropic.claude-haiku-5-5'],
            'capabilities' => ['chatToolLoop' => false],
            'prices' => ['input' => 0.1, 'output' => 0.5],
        ],
    ];

    private const TIERS = [
        'fast' => ['name' => 'Rychlý', 'order' => 10, 'model' => 'claude-sonnet-4-6'],
        'balanced' => ['name' => 'Vyvážený', 'order' => 20, 'model' => 'claude-sonnet-4-6'],
        'strong' => ['name' => 'Silný', 'order' => 30, 'model' => 'claude-sonnet-4-6'],
    ];

    private const PLATFORMS = [
        'anthropic' => ['name' => 'Anthropic', 'dialect' => 'anthropic-messages', 'baseUrl' => 'https://api.anthropic.com'],
    ];

    private const DEFAULT_CONNECTION = ['id' => 1, 'backend_id' => 'default', 'name' => 'Anthropic Claude', 'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-6', 'base_url' => null, 'api_key' => 'enc', 'is_default' => 1, 'is_active' => 1];
    private const SECOND_CONNECTION = ['id' => 2, 'backend_id' => 'gateway', 'name' => 'Gateway', 'provider' => 'anthropic',
        'model' => null, 'base_url' => 'https://portal.example/_hosting/ai-gw', 'api_key' => 'enc2', 'is_default' => 0, 'is_active' => 1];

    protected function setUp(): void
    {
        ErrorLogger::setLogLevel('error');
        AiFunctionResolver::resetWarningsForTesting();
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
    }

    /** @param array<string, mixed> $cfgItems */
    private function config(array $cfgItems = []): ConfigRuntime
    {
        $items = $cfgItems + [
            AiFunctionRegistry::CFG_ITEM => self::FUNCTIONS,
            ModelCatalog::CFG_ITEM => self::MODELS,
            ModelCatalog::TIERS_CFG_ITEM => self::TIERS,
            AiFunctionResolver::PLATFORMS_CFG_ITEM => self::PLATFORMS,
        ];
        $cfg = $this->createMock(ConfigRuntime::class);
        $cfg->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $items[$id] ?? null);
        return $cfg;
    }

    /**
     * @param array<string, array<string, mixed>> $rows      function_id → řádek nastavení
     * @param array<int, array<string, mixed>>    $connections id → řádek připojení (aktivní)
     * @param array<string, string|null|\Throwable> $keys      backend_id → klíč / null / výjimka
     */
    private function resolver(
        array $rows = [],
        ?array $default = self::DEFAULT_CONNECTION,
        array $connections = [1 => self::DEFAULT_CONNECTION, 2 => self::SECOND_CONNECTION],
        array $keys = ['default' => 'sk-default', 'gateway' => 'shpd_gw_x'],
        ?ConfigRuntime $config = null,
    ): AiFunctionResolver {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(static function (string $sql, mixed ...$args) use ($rows): ?array {
            return $rows[(string) ($args[0] ?? '')] ?? null;
        });
        $backends = $this->createMock(AiBackendResolver::class);
        $backends->method('defaultBackend')->willReturn($default);
        $backends->method('backendByNdx')->willReturnCallback(static fn (int $ndx): ?array => $connections[$ndx] ?? null);
        $backends->method('apiKey')->willReturnCallback(static function (array $connection) use ($keys): ?string {
            $key = $keys[(string) $connection['backend_id']] ?? null;
            if ($key instanceof \Throwable) {
                throw $key;
            }
            return $key;
        });
        return new AiFunctionResolver($db, $config ?? $this->config(), null, null, null, $backends);
    }

    // ------------------------------------------------------------ defaults

    public function testDeclarationAndTierMapDecideWithoutAnyOverride(): void
    {
        $call = $this->resolver()->resolve('mail.extraction');

        $this->assertSame('anthropic', $call->platform);
        $this->assertSame('https://api.anthropic.com', $call->baseUrl, 'adresa platformy, připojení ji nemá');
        $this->assertSame('sk-default', $call->apiKey);
        $this->assertSame('claude-sonnet-4-6', $call->model);
        $this->assertSame('claude-sonnet-4-6', $call->shapeAs);
        $this->assertSame('claude-sonnet-4-6', $call->catalogModel);
        $this->assertFalse($call->isCustomModel());
        $this->assertSame(32768, $call->maxTokens);
        $this->assertSame(0.0, $call->temperature);
        $this->assertNull($call->thinking);
        $this->assertNull($call->effort);
        $this->assertSame(1, $call->connectionId);
        $this->assertSame('default', $call->connectionCode);
        $this->assertSame([
            'connection' => ResolvedAiCall::ORIGIN_DEFAULT,
            'model' => ResolvedAiCall::ORIGIN_TIER,
            'thinking' => ResolvedAiCall::ORIGIN_DECLARATION,
            'effort' => ResolvedAiCall::ORIGIN_DECLARATION,
            'maxTokens' => ResolvedAiCall::ORIGIN_DECLARATION,
        ], $call->origins);

        // Funkce bez teploty ji neposílá; strop z deklarace.
        $summary = $this->resolver()->resolve('dashboard.summary');
        $this->assertNull($summary->temperature);
        $this->assertSame(2000, $summary->maxTokens);
        $this->assertArrayNotHasKey('apiKey', $summary->toArray());
    }

    public function testChatParamsCarryTheDecisionAndTheCallContent(): void
    {
        $params = $this->resolver()->resolve('mail.extraction')->chatParams(
            system: null,
            messages: [['role' => 'user', 'content' => 'hi']],
            tools: null,
            stallTimeoutSeconds: 180,
            timeoutSeconds: 840,
        );

        $this->assertSame('anthropic', $params->provider);
        $this->assertSame('claude-sonnet-4-6', $params->model);
        $this->assertSame('sk-default', $params->apiKey);
        $this->assertSame(32768, $params->maxTokens);
        $this->assertSame(0.0, $params->temperature);
        $this->assertNull($params->thinking);
        $this->assertNull($params->effort);
        $this->assertSame('claude-sonnet-4-6', $params->shapeAs);
        $this->assertSame(180, $params->stallTimeoutSeconds);
        $this->assertSame(840, $params->timeoutSeconds);
        $this->assertSame([['role' => 'user', 'content' => 'hi']], $params->messages);
    }

    // -------------------------------------------------- pořadí zdrojů

    public function testFunctionRowOverridesDeclarationAndOverrideWinsOverRow(): void
    {
        $row = ['function_id' => 'mail.extraction', 'connection' => 2, 'model' => 'claude-sonnet-5-5', 'model_like' => null,
            'thinking' => 'between_tools', 'effort' => 'high', 'max_tokens' => 16000];
        $resolver = $this->resolver(['mail.extraction' => $row]);

        $call = $resolver->resolve('mail.extraction');
        $this->assertSame(2, $call->connectionId);
        $this->assertSame('https://portal.example/_hosting/ai-gw', $call->baseUrl);
        $this->assertSame('shpd_gw_x', $call->apiKey);
        $this->assertSame('claude-sonnet-5-5', $call->model);
        $this->assertSame('between_tools', $call->thinking);
        $this->assertSame('high', $call->effort);
        $this->assertSame(16000, $call->maxTokens);
        $this->assertSame(0.0, $call->temperature, 'teplota jen z deklarace');
        $this->assertSame(ResolvedAiCall::ORIGIN_FUNCTION, $call->origins['connection']);
        $this->assertSame(ResolvedAiCall::ORIGIN_FUNCTION, $call->origins['model']);
        $this->assertSame(ResolvedAiCall::ORIGIN_FUNCTION, $call->origins['maxTokens']);

        $override = new AiCallOverride(connectionId: 1, model: 'claude-haiku-5-5', thinking: 'auto', effort: 'low', maxTokens: 500);
        $call = $resolver->resolve('mail.extraction', $override);
        $this->assertSame(1, $call->connectionId);
        $this->assertSame('claude-haiku-5-5', $call->model);
        $this->assertNull($call->thinking, '`auto` v přepsání = výslovně neposílat');
        $this->assertSame('low', $call->effort);
        $this->assertSame(500, $call->maxTokens);
        $this->assertSame(array_fill_keys(['connection', 'model', 'thinking', 'effort', 'maxTokens'], ResolvedAiCall::ORIGIN_OVERRIDE), $call->origins);

        // `default` v přepsání = nepřepisovat → platí řádek; 0 tokenů = nepřepisovat.
        $call = $resolver->resolve('mail.extraction', new AiCallOverride(thinking: 'default', effort: 'default', maxTokens: 0));
        $this->assertSame('between_tools', $call->thinking);
        $this->assertSame('high', $call->effort);
        $this->assertSame(16000, $call->maxTokens);
    }

    public function testDefaultVsAutoOnTheFunctionRow(): void
    {
        $base = ['function_id' => 'mail.extraction', 'connection' => null, 'model' => null, 'model_like' => null, 'max_tokens' => null];

        $call = $this->resolver(['mail.extraction' => $base + ['thinking' => 'default', 'effort' => 'default']])->resolve('mail.extraction');
        $this->assertNull($call->thinking);
        $this->assertSame(ResolvedAiCall::ORIGIN_DECLARATION, $call->origins['thinking']);

        $call = $this->resolver(['mail.extraction' => $base + ['thinking' => 'auto', 'effort' => 'adaptive']])->resolve('mail.extraction');
        $this->assertNull($call->thinking);
        $this->assertSame(ResolvedAiCall::ORIGIN_FUNCTION, $call->origins['thinking'], '`auto` je rozhodnutí řádku');
        $this->assertSame('adaptive', $call->effort);
    }

    public function testOverrideFromProfileRow(): void
    {
        $override = AiCallOverride::fromRow(['backend' => '2', 'model' => ' claude-haiku-5-5 ', 'model_like' => '', 'thinking' => 'default', 'effort' => 'auto', 'max_tokens' => '0']);
        $this->assertSame(2, $override->connectionId);
        $this->assertSame('claude-haiku-5-5', $override->model);
        $this->assertNull($override->modelLike);
        $this->assertNull($override->tuningValue($override->thinking));
        $this->assertSame('auto', $override->effort);
        $this->assertNull($override->maxTokens);
        $this->assertFalse($override->isEmpty());

        $this->assertTrue(AiCallOverride::fromRow(['backend' => null, 'thinking' => 'default', 'max_tokens' => 0])->isEmpty());
        $this->assertTrue(AiCallOverride::none()->isEmpty());
    }

    // ------------------------------------------------------- připojení

    public function testMissingDefaultConnectionAndInactiveSelectedConnection(): void
    {
        try {
            $this->resolver(default: null)->resolve('mail.extraction');
            $this->fail();
        } catch (AiFunctionException $e) {
            $this->assertSame(AiFunctionException::NO_CONNECTION, $e->errorCode);
        }

        $row = ['function_id' => 'mail.extraction', 'connection' => 9];
        try {
            $this->resolver(['mail.extraction' => $row])->resolve('mail.extraction');
            $this->fail();
        } catch (AiFunctionException $e) {
            $this->assertSame(AiFunctionException::NO_CONNECTION, $e->errorCode);
            $this->assertSame(9, $e->details['connectionId']);
            $this->assertSame(ResolvedAiCall::ORIGIN_FUNCTION, $e->details['origin']);
        }

        try {
            $this->resolver()->resolve('mail.extraction', new AiCallOverride(connectionId: 9));
            $this->fail();
        } catch (AiFunctionException $e) {
            $this->assertSame(AiFunctionException::NO_CONNECTION, $e->errorCode);
            $this->assertSame(ResolvedAiCall::ORIGIN_OVERRIDE, $e->details['origin']);
        }
    }

    public function testUnknownPlatformIsRejectedWhenPlatformsAreKnown(): void
    {
        $connection = ['provider' => 'openai'] + self::DEFAULT_CONNECTION;
        try {
            $this->resolver(default: $connection, connections: [1 => $connection])->resolve('mail.extraction');
            $this->fail();
        } catch (AiFunctionException $e) {
            $this->assertSame(AiFunctionException::UNKNOWN_PLATFORM, $e->errorCode);
        }

        // Bez cfgItemu platforem se platforma nehlídá a adresa zůstává prázdná (klient má výchozí).
        $call = $this->resolver(config: $this->config([AiFunctionResolver::PLATFORMS_CFG_ITEM => null]))->resolve('mail.extraction');
        $this->assertSame('', $call->baseUrl);
    }

    // -------------------------------------------------------------- klíč

    public function testKeyErrorsMapToCodes(): void
    {
        $cases = [
            [null, AiFunctionException::KEY_MISSING],
            ['', AiFunctionException::KEY_MISSING],
            [new SecretsKeyMissingException('no key file'), AiFunctionException::SECRETS_UNAVAILABLE],
            [new InvalidCiphertextException('bad'), AiFunctionException::KEY_CORRUPTED],
            [new \RuntimeException('AiBackendResolver: DataSourceConfig is required'), AiFunctionException::SECRETS_UNAVAILABLE],
        ];
        foreach ($cases as [$key, $code]) {
            try {
                $this->resolver(keys: ['default' => $key])->resolve('mail.extraction');
                $this->fail($code);
            } catch (AiFunctionException $e) {
                $this->assertSame($code, $e->errorCode);
                $this->assertTrue($e->isKeyError());
                $this->assertSame('default', $e->details['connectionCode']);
            }
        }
    }

    // ------------------------------------------------------------- model

    public function testCustomModelNeedsModelLikeFromTheCatalog(): void
    {
        $row = ['function_id' => 'mail.extraction', 'model' => 'claude-sonnet-6', 'model_like' => null];
        try {
            $this->resolver(['mail.extraction' => $row])->resolve('mail.extraction');
            $this->fail();
        } catch (AiFunctionException $e) {
            $this->assertSame(AiFunctionException::UNKNOWN_MODEL, $e->errorCode);
            $this->assertTrue($e->isModelError());
        }

        $row['model_like'] = 'gpt-5';
        try {
            $this->resolver(['mail.extraction' => $row])->resolve('mail.extraction');
            $this->fail();
        } catch (AiFunctionException $e) {
            $this->assertSame(AiFunctionException::UNKNOWN_MODEL, $e->errorCode);
        }

        $row['model_like'] = 'claude-sonnet-5-5';
        $call = $this->resolver(['mail.extraction' => $row])->resolve('mail.extraction');
        $this->assertSame('claude-sonnet-6', $call->model, 'vlastní ID odchází beze změny');
        $this->assertSame('claude-sonnet-5-5', $call->shapeAs);
        $this->assertNull($call->catalogModel);
        $this->assertTrue($call->isCustomModel());

        // Přepsání profilem: model_like z přepsání.
        $call = $this->resolver()->resolve('mail.extraction', new AiCallOverride(model: 'claude-sonnet-6', modelLike: 'claude-sonnet-4-6'));
        $this->assertSame('claude-sonnet-4-6', $call->shapeAs);
    }

    public function testModelIdsOfThePlatformGoOutVerbatimOthersAreTranslatedOrRejected(): void
    {
        // Datová přípona zůstává, jak ji správce napsal.
        $call = $this->resolver()->resolve('mail.extraction', new AiCallOverride(model: 'claude-sonnet-4-5-20250929'));
        $this->assertSame('claude-sonnet-4-5-20250929', $call->model);
        $this->assertSame('claude-sonnet-4-5', $call->shapeAs);

        // ID z Bedrocku na připojení Anthropic → ID pro Anthropic.
        $call = $this->resolver()->resolve('mail.extraction', new AiCallOverride(model: 'anthropic.claude-sonnet-5-5'));
        $this->assertSame('claude-sonnet-5-5', $call->model);

        // Model bez ID pro platformu bedrock.
        $bedrock = ['provider' => 'bedrock'] + self::DEFAULT_CONNECTION;
        $config = $this->config([AiFunctionResolver::PLATFORMS_CFG_ITEM => self::PLATFORMS + ['bedrock' => ['name' => 'Bedrock']]]);
        try {
            $this->resolver(default: $bedrock, connections: [1 => $bedrock], config: $config)->resolve('mail.extraction');
            $this->fail();
        } catch (AiFunctionException $e) {
            $this->assertSame(AiFunctionException::MODEL_NOT_ON_PLATFORM, $e->errorCode);
            $this->assertSame('claude-sonnet-4-6', $e->details['model']);
        }
        $call = $this->resolver(default: $bedrock, connections: [1 => $bedrock], config: $config)
            ->resolve('mail.extraction', new AiCallOverride(model: 'claude-sonnet-5-5'));
        $this->assertSame('anthropic.claude-sonnet-5-5', $call->model);
    }

    public function testFunctionRequiringToolsRejectsModelsWithoutToolLoop(): void
    {
        $this->assertSame('claude-sonnet-4-6', $this->resolver()->resolve('chat.default')->model);

        try {
            $this->resolver()->resolve('chat.default', new AiCallOverride(model: 'claude-sonnet-5-5'));
            $this->fail();
        } catch (AiFunctionException $e) {
            $this->assertSame(AiFunctionException::MODEL_NOT_SUPPORTED_FOR_FUNCTION, $e->errorCode);
            $this->assertSame('claude-sonnet-5-5', $e->details['model']);
        }
        // Vlastní ID tvarované podle modelu bez tool smyčky — totéž.
        try {
            $this->resolver()->resolve('chat.default', new AiCallOverride(model: 'claude-x', modelLike: 'claude-haiku-5-5'));
            $this->fail();
        } catch (AiFunctionException $e) {
            $this->assertSame(AiFunctionException::MODEL_NOT_SUPPORTED_FOR_FUNCTION, $e->errorCode);
        }
        // Funkce bez `tools` stejný model přijme.
        $this->assertSame('claude-sonnet-5-5', $this->resolver()->resolve('mail.extraction', new AiCallOverride(model: 'claude-sonnet-5-5'))->model);
    }

    public function testUnknownFunctionAndMissingTierMap(): void
    {
        try {
            $this->resolver()->resolve('mail.titles');
            $this->fail();
        } catch (AiFunctionException $e) {
            $this->assertSame(AiFunctionException::UNKNOWN_FUNCTION, $e->errorCode);
        }

        try {
            $this->resolver(config: $this->config([ModelCatalog::TIERS_CFG_ITEM => null]))->resolve('mail.extraction');
            $this->fail();
        } catch (AiFunctionException $e) {
            $this->assertSame(AiFunctionException::NO_MODEL, $e->errorCode);
        }
    }

    public function testEmptyCatalogSendsTheConfiguredModelUnchecked(): void
    {
        // Compiled config bez katalogu (před ds-upgrade): model z řádku jde ven,
        // tvarování žádné — jako dřív; ale mapa úrovní chybí, takže bez
        // vlastního modelu volání nejde sestavit.
        $config = $this->config([ModelCatalog::CFG_ITEM => null, ModelCatalog::TIERS_CFG_ITEM => null]);
        $row = ['function_id' => 'chat.default', 'model' => 'claude-sonnet-5-5'];
        $call = $this->resolver(['chat.default' => $row], config: $config)->resolve('chat.default');
        $this->assertSame('claude-sonnet-5-5', $call->model);
        $this->assertNull($call->shapeAs);
        $this->assertNull($call->catalogModel);
    }

    public function testSettingsTableMissingFallsBackToDeclaration(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willThrowException(new \RuntimeException("Table 'core_ai_function_settings' doesn't exist"));
        $backends = $this->createMock(AiBackendResolver::class);
        $backends->method('defaultBackend')->willReturn(self::DEFAULT_CONNECTION);
        $backends->method('apiKey')->willReturn('sk');
        $resolver = new AiFunctionResolver($db, $this->config(), null, null, null, $backends);

        $call = $resolver->resolve('mail.extraction');
        $this->assertSame('claude-sonnet-4-6', $call->model);
        $this->assertSame(32768, $call->maxTokens);
    }
}
