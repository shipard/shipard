<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\AiTestCommand;
use Shipard\Core\Ai\AiBackendResolver;
use Shipard\Core\Ai\AiFunctionRegistry;
use Shipard\Core\Ai\AiFunctionResolver;
use Shipard\Core\Ai\AiTexts;
use Shipard\Core\Ai\AnthropicLlmClient;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\LlmChatResult;
use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Utils\JsoncParser;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** Falešný LLM klient: fronta odpovědí (výsledek nebo výjimka), zachycené parametry. */
final class ScriptedLlmClient implements LlmClient
{
    /** @var list<LlmChatParams> */
    public array $calls = [];

    /** @param list<LlmChatResult|\Throwable> $script */
    public function __construct(private array $script) {}

    public function streamChat(LlmChatParams $params, callable $onTextDelta, ?callable $onThinking = null): LlmChatResult
    {
        $this->calls[] = $params;
        $next = array_shift($this->script);
        if ($next === null) {
            throw new \LogicException('ScriptedLlmClient: no more scripted responses');
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }
        $onTextDelta($next->text);
        return $next;
    }
}

/**
 * `shpd-ds ai-test` (tasks/ai-settings-section.md D52): minimální
 * požadavek per funkce (bez teploty a nástrojů, thinking vypnutý, effort
 * low, malý strop), řádky OK s časem, tokeny a cenou, chyba resolveru bez
 * volání modelu, chyby API 401 / 404 / strop útraty, `--function`,
 * `--json`, návratové kódy, chybějící compiled config.
 */
final class AiTestCommandTest extends TestCase
{
    private const FUNCTIONS = [
        'mail.extraction' => ['name' => 'Vytěžení došlé pošty', 'tier' => 'balanced', 'defaults' => ['maxTokens' => 32768, 'temperature' => 0.0], 'requires' => ['pdf']],
        'chat.default' => ['name' => 'Chat asistenta', 'tier' => 'balanced', 'defaults' => ['maxTokens' => 4096], 'requires' => ['tools']],
        'dashboard.summary' => ['name' => 'Shrnutí dashboardu', 'tier' => 'fast', 'defaults' => ['maxTokens' => 2000]],
    ];

    private const MODELS = [
        'claude-sonnet-5-5' => [
            'name' => 'Claude Sonnet 5.5', 'order' => 20, 'tier' => 'balanced', 'platformIds' => ['anthropic' => 'claude-sonnet-5-5'],
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
        // Jen ceník — bez schopností (null): thinking se nevnucuje.
        'claude-fable-5-1' => [
            'name' => 'Claude Fable 5.1', 'order' => 100, 'platformIds' => ['anthropic' => 'claude-fable-5-1'],
            'prices' => ['input' => 10, 'output' => 50],
        ],
    ];

    private const TIERS = [
        'fast' => ['name' => 'Rychlý', 'order' => 10, 'model' => 'claude-sonnet-4-6'],
        'balanced' => ['name' => 'Vyvážený', 'order' => 20, 'model' => 'claude-sonnet-4-6'],
    ];

    private const CONNECTION = ['id' => 1, 'backend_id' => 'default', 'name' => 'Anthropic Claude', 'provider' => 'anthropic',
        'base_url' => null, 'api_key' => 'enc', 'is_default' => 1, 'is_active' => 1];

    protected function setUp(): void
    {
        ErrorLogger::setLogLevel('error');
        AiFunctionResolver::resetWarningsForTesting();
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
    }

    private function config(): ConfigRuntime
    {
        $texts = [];
        foreach (JsoncParser::parseFile(dirname(__DIR__, 4) . '/modules/core/ai/config/texts.jsonc') as $key => $entry) {
            $texts[$key] = ['text' => $entry['text:cs']];
        }
        $items = [
            AiFunctionRegistry::CFG_ITEM => self::FUNCTIONS,
            ModelCatalog::CFG_ITEM => self::MODELS,
            ModelCatalog::TIERS_CFG_ITEM => self::TIERS,
            AiFunctionResolver::PLATFORMS_CFG_ITEM => ['anthropic' => ['name' => 'Anthropic', 'baseUrl' => 'https://api.anthropic.com']],
            AiTexts::CFG_ITEM => $texts,
        ];
        $cfg = $this->createMock(ConfigRuntime::class);
        $cfg->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $items[$id] ?? null);
        return $cfg;
    }

    /**
     * @param array<string, array<string, mixed>> $rows function_id → řádek nastavení
     * @param array<string, mixed>|null           $connection výchozí připojení
     */
    private function tester(
        ScriptedLlmClient $llm,
        array $rows = [],
        ?array $connection = self::CONNECTION,
        ?string $apiKey = 'sk-default',
        bool $compiledConfig = true,
    ): CommandTester {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(static fn (string $sql, mixed ...$args): ?array => $rows[(string) ($args[0] ?? '')] ?? null);
        $backends = $this->createMock(AiBackendResolver::class);
        $backends->method('defaultBackend')->willReturn($connection);
        $backends->method('backendByNdx')->willReturn($connection);
        $backends->method('apiKey')->willReturn($apiKey);
        $config = $this->config();
        $resolver = new AiFunctionResolver($db, $config, null, null, null, $backends);

        $dsConfig = $this->createMock(DataSourceConfig::class);
        $dsConfig->method('getDefaultLanguage')->willReturn('cs');

        $command = new class ($dsConfig, $db, $llm, $resolver, $config, $compiledConfig) extends AiTestCommand {
            public function __construct(
                DataSourceConfig $dsConfig,
                DataSourceConnection $db,
                LlmClient $llm,
                private readonly AiFunctionResolver $stubResolver,
                private readonly ConfigRuntime $stubConfig,
                private readonly bool $compiledConfig,
            ) {
                parent::__construct($dsConfig, $db, $llm);
            }

            protected function getDataSourceDir(): string
            {
                return '/nonexistent';
            }

            protected function loadConfig(string $dsDir, string $language): ConfigRuntime
            {
                if (!$this->compiledConfig) {
                    throw new \RuntimeException("Compiled config not found: {$dsDir}/config/configuration/compiled.{$language}.json");
                }
                return $this->stubConfig;
            }

            protected function createResolver(DataSourceConnection $db, ConfigRuntime $config, DataSourceConfig $dsConfig): AiFunctionResolver
            {
                return $this->stubResolver;
            }
        };
        (new Application())->add($command);
        return new CommandTester($command);
    }

    private static function ok(string $model = 'claude-sonnet-4-6', int $in = 12, int $out = 3, string $stop = 'end_turn'): LlmChatResult
    {
        return new LlmChatResult('OK', $in, $out, $stop, $model);
    }

    public function testEveryFunctionGetsAMinimalRequestAndPassesWithCostAndTime(): void
    {
        $llm = new ScriptedLlmClient([self::ok(), self::ok(), self::ok(in: 100, out: 60, stop: 'max_tokens')]);
        $tester = $this->tester($llm);

        $exit = $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertSame(0, $exit, $display);
        $this->assertCount(3, $llm->calls, 'všechny deklarované funkce v pořadí registru');
        $this->assertMatchesRegularExpression('/OK\s+mail\.extraction\s+default · claude-sonnet-4-6\s+\d+\.\d s\s+in 12 \/ out 3\s+\$0\.000081\s+end_turn/', $display);
        $this->assertMatchesRegularExpression('/OK\s+dashboard\.summary\s+.*in 100 \/ out 60\s+\$0\.001200\s+max_tokens/', $display, 'max_tokens je pořád úspěch');

        $params = $llm->calls[0];
        $this->assertSame('sk-default', $params->apiKey);
        $this->assertSame('claude-sonnet-4-6', $params->model);
        $this->assertSame('https://api.anthropic.com', $params->baseUrl);
        $this->assertSame([['role' => 'user', 'content' => AiTestCommand::PROMPT]], $params->messages);
        $this->assertNull($params->system);
        $this->assertSame(AiTestCommand::MAX_TOKENS, $params->maxTokens);
        $this->assertNull($params->temperature, 'teplota z deklarace (0) se do testu neposílá');
        $this->assertNull($params->tools, 'chat.default se testuje bez nástrojů');
        $this->assertSame('disabled', $params->thinking, 'model se známými režimy: thinking vypnout (shaper případně přepne)');
        $this->assertSame(AiTestCommand::EFFORT, $params->effort);
        $this->assertSame(30, $params->stallTimeoutSeconds);
        $this->assertSame(60, $params->timeoutSeconds);
        $this->assertSame('claude-sonnet-4-6', $params->shapeAs);
    }

    public function testModelWithUnknownCapabilitiesKeepsThePlannedThinking(): void
    {
        $llm = new ScriptedLlmClient([self::ok('claude-fable-5-1')]);
        $row = ['function_id' => 'dashboard.summary', 'model' => 'claude-fable-5-1', 'thinking' => 'auto', 'effort' => 'default'];
        $tester = $this->tester($llm, ['dashboard.summary' => $row]);

        $this->assertSame(0, $tester->execute(['--function' => 'dashboard.summary']));
        $this->assertCount(1, $llm->calls);
        $this->assertNull($llm->calls[0]->thinking, 'bez známých režimů nic nevnucovat (Fable 400 na disabled)');
        $this->assertSame('claude-fable-5-1', $llm->calls[0]->model);
        $this->assertStringContainsString('$0.000270', $tester->getDisplay(), 'cena podle ceníku položky jen s cenami');
    }

    public function testResolverErrorFailsWithoutCallingTheModel(): void
    {
        $llm = new ScriptedLlmClient([]);
        $tester = $this->tester($llm, connection: null);

        $exit = $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertSame(1, $exit);
        $this->assertSame([], $llm->calls);
        $this->assertSame(3, substr_count($display, 'FAIL'));
        $this->assertStringContainsString(
            'Není nastavené žádné výchozí aktivní AI připojení. Nastavení → AI → Připojení: označ připojení jako výchozí a aktivní.',
            $display,
            'stejná věta jako v detailu funkce',
        );
    }

    public function testChatOnASeries5ModelFailsOnlyThatFunction(): void
    {
        $llm = new ScriptedLlmClient([self::ok(), self::ok()]);
        $row = ['function_id' => 'chat.default', 'model' => 'claude-sonnet-5-5'];
        $tester = $this->tester($llm, ['chat.default' => $row]);

        $exit = $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertSame(1, $exit);
        $this->assertCount(2, $llm->calls, 'mail.extraction a dashboard.summary se zavolaly, chat ne');
        $this->assertStringContainsString('Model claude-sonnet-5-5 zatím nemůže obsloužit AI funkci Chat asistenta', $display);
    }

    public function testApiErrorsAreReportedByKind(): void
    {
        $llm = new ScriptedLlmClient([
            AnthropicLlmClient::exceptionFromErrorResponse(401, '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}'),
            AnthropicLlmClient::exceptionFromErrorResponse(404, '{"type":"error","error":{"type":"not_found_error","message":"model: claude-sonnet-4-6"}}'),
            AnthropicLlmClient::exceptionFromErrorResponse(429, '{"type":"error","error":{"type":"rate_limit_error","message":"limit","details":{"error_code":"enforced_spend_limit_reached"}}}'),
        ]);
        $tester = $this->tester($llm);

        $exit = $tester->execute(['--json' => true]);
        $display = $tester->getDisplay();

        $this->assertSame(1, $exit);
        $json = json_decode(trim($display), true);
        $this->assertIsArray($json, 'v --json režimu je stdout jediný JSON objekt: ' . $display);
        $this->assertFalse($json['ok']);
        $errors = array_column($json['functions'], 'error', 'id');
        $this->assertSame('Platforma odmítla API klíč (HTTP 401) — klíč je neplatný nebo zrušený.', $errors['mail.extraction']);
        $this->assertSame('Model claude-sonnet-4-6 na platformě neexistuje nebo už skončil (HTTP 404).', $errors['chat.default']);
        $this->assertStringStartsWith('Vyčerpaný limit útraty', $errors['dashboard.summary']);
        $this->assertSame(['API_401', 'API_404', 'API_429'], array_column($json['functions'], 'code'));
        $this->assertSame('invalid x-api-key', $json['functions'][0]['apiMessage']);
    }

    public function testJsonOutputOnSuccess(): void
    {
        $tester = $this->tester(new ScriptedLlmClient([self::ok()]));

        $exit = $tester->execute(['--function' => 'mail.extraction', '--json' => true]);
        $json = json_decode(trim($tester->getDisplay()), true);

        $this->assertSame(0, $exit);
        $this->assertTrue($json['ok']);
        $this->assertCount(1, $json['functions']);
        $f = $json['functions'][0];
        $this->assertSame('mail.extraction', $f['id']);
        $this->assertTrue($f['ok']);
        $this->assertSame('default', $f['connection']);
        $this->assertSame('claude-sonnet-4-6', $f['model']);
        $this->assertSame(12, $f['inputTokens']);
        $this->assertSame(3, $f['outputTokens']);
        $this->assertSame(0.000081, $f['costUsd']);
        $this->assertSame('end_turn', $f['stopReason']);
        $this->assertSame('OK', $f['reply']);
        $this->assertIsInt($f['elapsedMs']);
    }

    public function testUnknownFunctionAndMissingCompiledConfigFail(): void
    {
        $llm = new ScriptedLlmClient([]);
        $tester = $this->tester($llm);
        $this->assertSame(1, $tester->execute(['--function' => 'nope.nope']));
        $this->assertStringContainsString('AI funkci nope.nope nedeklaruje žádný aktivní modul.', $tester->getDisplay());
        $this->assertSame([], $llm->calls);

        $tester = $this->tester($llm, compiledConfig: false);
        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('run shpd-ds ds-upgrade', $tester->getDisplay());
    }

    public function testUndecryptableKeyIsAFailureAfterThePlan(): void
    {
        $llm = new ScriptedLlmClient([]);
        $tester = $this->tester($llm, apiKey: null);

        $this->assertSame(1, $tester->execute(['--function' => 'mail.extraction']));
        $this->assertSame([], $llm->calls);
        $this->assertStringContainsString('AI připojení default nemá uložený API klíč.', $tester->getDisplay());
    }
}
