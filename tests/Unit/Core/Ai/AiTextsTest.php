<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiFunctionRegistry;
use Shipard\Core\Ai\AiTexts;
use Shipard\Core\Ai\Exception\AiFunctionException;
use Shipard\Core\Ai\Exception\LlmApiException;
use Shipard\Core\Ai\RequestChange;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;

/**
 * Katalog `core.ai.texts` (tasks/ai-settings-section.md D51): každý kód
 * chyby resolveru, každá úprava shaperu, každý původ a druh chyby API má
 * větu ve třech jazycích a žádný neznámý `{param}`; varianty podle
 * detailů; bez katalogu anglický originál.
 */
final class AiTextsTest extends TestCase
{
    /** @var array<string, array<string, string>> */
    private static array $catalog;

    public static function setUpBeforeClass(): void
    {
        self::$catalog = JsoncParser::parseFile(dirname(__DIR__, 4) . '/modules/core/ai/config/texts.jsonc');
    }

    /** ConfigRuntime jako po kompilaci pro jazyk: `text` = `text:<lang>`. */
    private function config(string $lang = 'cs'): ConfigRuntime
    {
        $localized = [];
        foreach (self::$catalog as $key => $entry) {
            $localized[$key] = ['text' => $entry['text:' . $lang] ?? $entry['text']];
        }
        $cfg = $this->createMock(ConfigRuntime::class);
        $cfg->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $id === AiTexts::CFG_ITEM ? $localized : null);
        return $cfg;
    }

    private function registry(): AiFunctionRegistry
    {
        return AiFunctionRegistry::fromArray([
            'mail.extraction' => ['name' => 'Vytěžení došlé pošty', 'tier' => 'balanced', 'defaults' => ['maxTokens' => 1000]],
        ]);
    }

    public function testCatalogCoversEveryCodeInThreeLanguages(): void
    {
        $codes = (new \ReflectionClass(AiFunctionException::class))->getConstants();
        $errorCodes = array_filter($codes, static fn ($v, $k): bool => is_string($v) && !in_array($k, ['KEY_CODES', 'MODEL_CODES'], true), ARRAY_FILTER_USE_BOTH);
        $expected = [];
        foreach ($errorCodes as $code) {
            $expected[] = 'error.' . $code;
            $expected[] = 'hint.' . $code;
        }
        foreach (['NO_CONNECTION.selected', 'UNKNOWN_MODEL.like'] as $variant) {
            $expected[] = 'error.' . $variant;
            $expected[] = 'hint.' . $variant;
        }
        foreach (RequestChange::CODES as $code) {
            $expected[] = 'change.' . $code;
        }
        foreach (['override', 'function', 'declaration', 'tier', 'default'] as $origin) {
            $expected[] = 'origin.' . $origin;
        }
        foreach (['authentication', 'forbidden', 'notFound', 'spendLimit', 'rateLimit', 'transport', 'server', 'other'] as $kind) {
            $expected[] = 'api.' . $kind;
        }

        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, self::$catalog, $key);
            foreach (['text', 'text:cs', 'text:en'] as $field) {
                $this->assertNotSame('', trim((string) (self::$catalog[$key][$field] ?? '')), "{$key}.{$field}");
            }
            $this->assertSame(self::$catalog[$key]['text'], self::$catalog[$key]['text:en'], "{$key}: holé text = anglický originál");
        }
        $this->assertSame([], array_diff(array_keys(self::$catalog), $expected), 'katalog nemá klíče navíc');
    }

    public function testPlaceholdersAreKnownParameters(): void
    {
        $allowed = [
            'error' => ['functionId', 'function', 'connectionId', 'connectionCode', 'platform', 'tier', 'model', 'modelLike', 'origin', 'requires'],
            'hint' => ['functionId', 'function', 'connectionId', 'connectionCode', 'platform', 'tier', 'model', 'modelLike', 'origin', 'requires'],
            'change.temperatureOmitted' => ['temperature'],
            'change.thinkingReplaced' => ['from', 'to', 'accepted'],
            'change.thinkingOmitted' => ['thinking', 'accepted'],
            'change.thinkingUnsupported' => ['thinking'],
            'change.effortOmitted' => ['effort'],
            'change.effortLowered' => ['from', 'to', 'accepted'],
            'change.effortCapped' => ['from', 'to', 'thinking'],
            'change.maxTokensCapped' => ['from', 'to'],
            'origin' => [],
            'api' => ['status', 'type', 'model', 'message'],
        ];
        foreach (self::$catalog as $key => $entry) {
            $prefix = explode('.', $key, 2)[0];
            $params = $allowed[$key] ?? $allowed[$prefix] ?? null;
            $this->assertNotNull($params, "neznámý prefix klíče {$key}");
            foreach (['text', 'text:cs', 'text:en'] as $field) {
                preg_match_all('/\{([a-zA-Z]+)\}/', $entry[$field], $m);
                $this->assertSame([], array_values(array_diff($m[1], $params)), "{$key}.{$field}: neznámý parametr");
            }
        }
    }

    public function testErrorSentenceWithHintUsesFunctionNameAndVariant(): void
    {
        $texts = new AiTexts($this->config(), $this->registry());

        $selected = new AiFunctionException(
            AiFunctionException::NO_CONNECTION,
            'AI connection #2 selected for mail.extraction (function) is missing or inactive',
            ['functionId' => 'mail.extraction', 'connectionId' => 2, 'origin' => 'function'],
        );
        $this->assertSame(
            'AI připojení č. 2 nastavené u funkce Vytěžení došlé pošty neexistuje nebo není aktivní.'
            . ' Nastavení → AI → Funkce → Vytěžení došlé pošty: vyber jiné připojení, nebo pole vyprázdni (výchozí připojení).',
            $texts->error($selected),
        );
        $this->assertSame(
            'AI připojení č. 2 nastavené u funkce Vytěžení došlé pošty neexistuje nebo není aktivní.',
            $texts->error($selected, withHint: false),
        );

        $default = new AiFunctionException(AiFunctionException::NO_CONNECTION, 'No active default AI connection is configured', ['functionId' => 'mail.extraction']);
        $this->assertSame('Není nastavené žádné výchozí aktivní AI připojení.', $texts->error($default, withHint: false));
        $this->assertSame('Nastavení → AI → Připojení: označ připojení jako výchozí a aktivní.', $texts->hint($default));

        $like = new AiFunctionException(AiFunctionException::UNKNOWN_MODEL, 'x', ['functionId' => 'chat.default', 'model' => 'claude-x', 'modelLike' => 'gpt-5']);
        $this->assertStringStartsWith('Model gpt-5, podle kterého', $texts->error($like));
        $this->assertStringContainsString('funkce chat.default', $texts->error($like), 'funkce mimo registr zůstává jako id');

        $key = new AiFunctionException(AiFunctionException::KEY_MISSING, 'x', ['connectionId' => 1, 'connectionCode' => 'default']);
        $this->assertSame(
            'AI připojení default nemá uložený API klíč. Správce ho zadá v konzoli: shpd-ds ai-backend-set-key --backend default',
            $texts->error($key),
        );

        $this->assertSame(
            'AI connection default has no API key stored. An administrator sets it from the console: shpd-ds ai-backend-set-key --backend default',
            (new AiTexts($this->config('en')))->error($key),
        );
    }

    public function testChangeOriginAndApiErrorTexts(): void
    {
        $texts = new AiTexts($this->config());

        $this->assertSame(
            'Teplota 0 se neposílá — model nepřijímá jinou než výchozí teplotu.',
            $texts->change(new RequestChange(RequestChange::TEMPERATURE_OMITTED, ['temperature' => 0.0])),
        );
        $this->assertSame(
            'Úsilí xhigh se posílá jako high — s přemýšlením between_tools model přijímá nejvýš high.',
            $texts->change(new RequestChange(RequestChange::EFFORT_CAPPED, ['from' => 'xhigh', 'to' => 'high', 'thinking' => 'between_tools'])),
        );
        $this->assertSame('podle úrovně modelu', $texts->origin('tier'));

        $this->assertSame(
            'Platforma odmítla API klíč (HTTP 401) — klíč je neplatný nebo zrušený.',
            $texts->apiError(new LlmApiException(401, 'authentication_error', 'invalid x-api-key')),
        );
        $this->assertSame(
            'Model claude-x na platformě neexistuje nebo už skončil (HTTP 404).',
            $texts->apiError(new LlmApiException(404, 'not_found_error', 'model: claude-x'), 'claude-x'),
        );
        $this->assertStringStartsWith(
            'Vyčerpaný limit útraty',
            $texts->apiError(new LlmApiException(429, 'rate_limit_error', 'x', LlmApiException::ERROR_CODE_SPEND_LIMIT)),
        );
        $this->assertSame('Chyba na straně platformy (HTTP 529).', $texts->apiError(new LlmApiException(529, 'overloaded_error', 'x')));
        $this->assertSame('Platforma neodpověděla (síťová chyba nebo vypršel čas).', $texts->apiError(new LlmApiException(0, 'transport_error', 'x')));
    }

    public function testWithoutCatalogEnglishOriginalsComeBack(): void
    {
        $texts = new AiTexts(null);
        $e = new AiFunctionException(AiFunctionException::KEY_MISSING, "AI connection 'default' has no API key set.", ['connectionCode' => 'default']);

        $this->assertSame("AI connection 'default' has no API key set.", $texts->error($e));
        $this->assertNull($texts->hint($e));
        $change = new RequestChange(RequestChange::MAX_TOKENS_CAPPED, ['from' => 100000, 'to' => 64000]);
        $this->assertSame('max_tokens 100000 → 64000 (model limit)', $texts->change($change));
        $this->assertSame('tier', $texts->origin('tier'));
        $this->assertSame('HTTP 401 authentication_error: bad key', $texts->apiError(new LlmApiException(401, 'authentication_error', 'bad key')));
        $this->assertNull($texts->t('error.NO_CONNECTION'));
    }
}
