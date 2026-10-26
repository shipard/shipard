<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiBackendResolver;
use Shipard\Core\Ai\AiFunctionRegistry;
use Shipard\Core\Ai\AiFunctionResolver;
use Shipard\Core\Ai\AiTexts;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Ai\AiFunctionsViewer;
use Shipard\Module\Core\Ai\AiViewerBase;

/**
 * Detail AI funkce říká, co se pošle (tasks/ai-settings-section.md D51):
 * efektivní hodnoty s původem z plánu resolveru, úpravy požadavku pro
 * model řady 5, stav volání s chybou a radou; klíč se nedešifruje.
 * Popisky z `core.ai.viewerLabels`, věty z `core.ai.texts` (soubory repa).
 */
final class AiFunctionsViewerTest extends TestCase
{
    private const FUNCTIONS = [
        'mail.extraction' => ['name' => 'Vytěžení došlé pošty', 'description' => 'Čte doklady z pošty', 'tier' => 'balanced',
            'defaults' => ['maxTokens' => 32768, 'temperature' => 0.0], 'requires' => ['pdf']],
        'chat.default' => ['name' => 'Chat asistenta', 'tier' => 'balanced', 'defaults' => ['maxTokens' => 4096], 'requires' => ['tools']],
    ];

    private const MODELS = [
        'claude-sonnet-5-5' => [
            'name' => 'Claude Sonnet 5.5', 'order' => 20, 'tier' => 'balanced',
            'platformIds' => ['anthropic' => 'claude-sonnet-5-5', 'bedrock' => 'anthropic.claude-sonnet-5-5'],
            'capabilities' => ['temperature' => false, 'thinkingModes' => ['adaptive', 'between_tools'], 'thinkingDefault' => 'adaptive',
                'effortLevels' => ['low', 'medium', 'high', 'xhigh', 'max'], 'thinkingOffMaxEffort' => 'high', 'maxOutputTokens' => 128000],
            'prices' => ['input' => 2, 'output' => 10],
        ],
        'claude-sonnet-4-6' => [
            'name' => 'Claude Sonnet 4.6', 'order' => 22, 'tier' => 'balanced', 'platformIds' => ['anthropic' => 'claude-sonnet-4-6'],
            'capabilities' => ['temperature' => true, 'thinkingModes' => ['adaptive', 'disabled'], 'thinkingDefault' => 'off',
                'effortLevels' => ['low', 'medium', 'high', 'max'], 'maxOutputTokens' => 128000],
            'prices' => ['input' => 3, 'output' => 15],
        ],
    ];

    private const TIERS = [
        'balanced' => ['name' => 'Vyvážený', 'order' => 20, 'model' => 'claude-sonnet-4-6', 'platformModels' => ['bedrock-eu' => 'claude-sonnet-5-5']],
    ];

    private const CONNECTION = ['id' => 1, 'backend_id' => 'default', 'name' => 'Anthropic Claude', 'provider' => 'anthropic',
        'base_url' => null, 'api_key' => 'enc', 'is_default' => 1, 'is_active' => 1];

    /** @return array<string, array{text: string}>|array<string, array{name: string}> */
    private static function localized(string $file, string $field): array
    {
        $out = [];
        foreach (JsoncParser::parseFile(dirname(__DIR__, 5) . '/modules/core/ai/config/' . $file) as $key => $entry) {
            $out[$key] = [$field => $entry[$field . ':cs'] ?? $entry[$field]];
        }
        return $out;
    }

    private function config(): ConfigRuntime
    {
        $items = [
            AiFunctionRegistry::CFG_ITEM => self::FUNCTIONS,
            ModelCatalog::CFG_ITEM => self::MODELS,
            ModelCatalog::TIERS_CFG_ITEM => self::TIERS,
            AiFunctionResolver::PLATFORMS_CFG_ITEM => [
                'anthropic' => ['name' => 'Anthropic', 'baseUrl' => 'https://api.anthropic.com'],
                'bedrock-eu' => ['name' => 'Amazon Bedrock (EU)', 'baseUrl' => 'https://bedrock-runtime.eu-central-1.amazonaws.com/anthropic',
                    'modelIds' => 'bedrock', 'modelIdPrefix' => 'eu.', 'priceMultiplier' => 1.1],
            ],
            AiViewerBase::LABELS_CFG_ITEM => self::localized('viewerLabels.jsonc', 'name'),
            AiTexts::CFG_ITEM => self::localized('texts.jsonc', 'text'),
            'core.ai.thinkingModes' => ['default' => ['name' => 'Podle funkce'], 'adaptive' => ['name' => 'Adaptivní'], 'disabled' => ['name' => 'Vypnuté']],
            'core.ai.effortLevels' => ['default' => ['name' => 'Podle funkce'], 'high' => ['name' => 'Vysoké']],
        ];
        $cfg = $this->createMock(ConfigRuntime::class);
        $cfg->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $items[$id] ?? null);
        return $cfg;
    }

    /**
     * Viewer nad mockem DB (řádek detailu, řádek nastavení pro resolver,
     * název připojení) a resolverem s podvrženým AiBackendResolver — klíč
     * se nikdy nedešifruje.
     *
     * @param array<string, mixed> $row řádek core_ai_function_settings
     */
    private function viewer(array $row, ?array $connection = self::CONNECTION): AiFunctionsViewer
    {
        $record = $row + ['id' => 3, 'function_id' => 'mail.extraction', 'connection' => null, 'model' => null, 'model_like' => null,
            'thinking' => 'default', 'effort' => 'default', 'max_tokens' => null, 'created' => '2026-10-01 10:00:00', 'modified' => null,
            'connection_name' => null, 'connection_code' => null, 'connection_active' => null];

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(static function (string $sql) use ($record, $connection): ?array {
            if (str_contains($sql, 'SELECT `name`, `is_active` FROM `core_ai_backends`')) {
                return $connection !== null ? ['name' => $connection['name'], 'is_active' => 1] : null;
            }
            if (str_contains($sql, 'WHERE `function_id`')) {
                return $record; // řádek pro resolver
            }
            return $record;
        });
        $db->method('fetchAll')->willReturn([$record]);

        $backends = $this->createMock(AiBackendResolver::class);
        $backends->method('defaultBackend')->willReturn($connection);
        $backends->method('backendByNdx')->willReturn($connection);
        $backends->expects($this->never())->method('apiKey');
        $resolver = new AiFunctionResolver($db, $this->config(), null, null, null, $backends);

        $viewer = new class ($db, 'core_ai_function_settings', $resolver) extends AiFunctionsViewer {
            public function __construct(DataSourceConnection $db, string $table, private readonly AiFunctionResolver $stub)
            {
                parent::__construct($db, $table);
            }

            protected function resolver(): AiFunctionResolver
            {
                return $this->stub;
            }
        };
        $viewer->setConfig($this->config());
        $viewer->setLanguage('cs');
        return $viewer;
    }

    /** @return array<string, string> label → value */
    private function items(array $detail, string $groupTitle): array
    {
        foreach ($detail['tabs'][0]['content']['groups'] as $group) {
            if ($group['title'] === $groupTitle) {
                return array_column($group['items'], 'value', 'label');
            }
        }
        $this->fail("group {$groupTitle} missing");
    }

    public function testDetailShowsPlannedCallWithOriginsAndNoChanges(): void
    {
        $detail = $this->viewer([])->renderDetail(3);

        $this->assertSame('Vytěžení došlé pošty', $detail['title']);
        $this->assertSame('mail.extraction', $detail['subtitle']);
        $this->assertSame([['label' => 'V pořádku', 'style' => 'success']], $detail['badges']);
        $this->assertSame(
            ['Funkce', 'Volání', 'Ladění', 'Úpravy požadavku', 'Stav volání', 'Záznam'],
            array_column($detail['tabs'][0]['content']['groups'], 'title'),
        );

        $function = $this->items($detail, 'Funkce');
        $this->assertSame('Vyvážený', $function['Úroveň']);
        $this->assertSame('pdf', $function['Požadavky na model']);
        // Název funkce je titulkem, id podtitulkem — Přehled je neopakuje (#140 D26).
        $this->assertArrayNotHasKey('Funkce', $function);
        $this->assertArrayNotHasKey('ID', $function);

        $call = $this->items($detail, 'Volání');
        $this->assertSame('Anthropic Claude (default) — výchozí připojení', $call['AI připojení']);
        $this->assertSame('Anthropic', $call['Platforma']);
        $this->assertSame('https://api.anthropic.com', $call['Adresa API']);
        $this->assertSame('claude-sonnet-4-6 — podle úrovně modelu', $call['Model']);
        $this->assertArrayNotHasKey('Tvarovat jako model', $call);

        $tuning = $this->items($detail, 'Ladění');
        $this->assertSame('neposílá se — podle deklarace funkce', $tuning['Přemýšlení']);
        $this->assertSame('neposílá se — podle deklarace funkce', $tuning['Úsilí']);
        $this->assertSame('32768 — podle deklarace funkce', $tuning['Max. tokenů']);
        $this->assertSame('0 — podle deklarace funkce', $tuning['Teplota']);

        $this->assertSame(
            ['Výsledek' => 'Žádné — model přijme všechny hodnoty tak, jak jsou nastavené'],
            $this->items($detail, 'Úpravy požadavku'),
        );
        $status = $this->items($detail, 'Stav volání');
        $this->assertStringStartsWith('V pořádku — připojení má uložený klíč', $status['Výsledek']);
        $this->assertSame('shpd-ds ai-test --function mail.extraction', $status['Ověření z konzole']);
        $this->assertSame(['Vytvořeno' => '2026-10-01 10:00:00'], $this->items($detail, 'Záznam'));
    }

    public function testDetailListsAdjustmentsForASeries5ModelSetOnTheFunction(): void
    {
        $detail = $this->viewer(['model' => 'claude-sonnet-5-5', 'thinking' => 'disabled', 'effort' => 'high', 'max_tokens' => 200000])->renderDetail(3);

        $call = $this->items($detail, 'Volání');
        $this->assertSame('claude-sonnet-5-5 — nastaveno u funkce', $call['Model']);
        $tuning = $this->items($detail, 'Ladění');
        $this->assertSame('Vypnuté — nastaveno u funkce', $tuning['Přemýšlení']);
        $this->assertSame('Vysoké — nastaveno u funkce', $tuning['Úsilí']);
        $this->assertSame('200000 — nastaveno u funkce', $tuning['Max. tokenů']);

        $changes = $detail['tabs'][0]['content']['groups'][3]['items'];
        $this->assertSame('Úpravy požadavku', $detail['tabs'][0]['content']['groups'][3]['title']);
        $this->assertSame([
            ['label' => 'Teplota', 'value' => 'Teplota 0 se neposílá — model nepřijímá jinou než výchozí teplotu.'],
            ['label' => 'Přemýšlení', 'value' => 'Přemýšlení disabled se posílá jako between_tools — model přijímá: adaptive, between_tools.'],
            ['label' => 'Max. tokenů', 'value' => 'Strop 200000 tokenů se snižuje na 128000 — limit výstupu modelu.'],
        ], $changes);
        $this->assertSame([['label' => 'V pořádku', 'style' => 'success']], $detail['badges']);
    }

    public function testDetailShowsSetupErrorWithHintAndStoredValues(): void
    {
        $detail = $this->viewer([], connection: null)->renderDetail(3);

        $this->assertSame([['label' => 'Chyba nastavení', 'style' => 'danger']], $detail['badges']);
        $this->assertSame(
            ['Funkce', 'Volání', 'Ladění', 'Stav volání', 'Záznam'],
            array_column($detail['tabs'][0]['content']['groups'], 'title'),
            'bez plánu nejsou úpravy požadavku',
        );
        $this->assertSame(['AI připojení' => 'výchozí připojení', 'Model' => 'claude-sonnet-4-6'], $this->items($detail, 'Volání'));

        $status = $this->items($detail, 'Stav volání');
        $this->assertSame('Není nastavené žádné výchozí aktivní AI připojení.', $status['Výsledek']);
        $this->assertSame('Nastavení → AI → Připojení: označ připojení jako výchozí a aktivní.', $status['Kde to opravit']);
        $this->assertSame('shpd-ds ai-test --function mail.extraction', $status['Ověření z konzole']);
    }

    public function testChatFunctionOnASeries5ModelIsNoLongerASetupError(): void
    {
        // Pojistka D35 zanikla (tasks/chat-thinking-blocks.md D54): asistent
        // na řadě 5 je v pořádku, detail ukáže plán s modelem.
        $detail = $this->viewer(['function_id' => 'chat.default', 'model' => 'claude-sonnet-5-5'])->renderDetail(3);

        $this->assertSame([['label' => 'V pořádku', 'style' => 'success']], $detail['badges']);
        $this->assertSame('claude-sonnet-5-5 — nastaveno u funkce', $this->items($detail, 'Volání')['Model']);
        $status = $this->items($detail, 'Stav volání');
        $this->assertStringStartsWith('V pořádku — připojení má uložený klíč', $status['Výsledek']);
        $this->assertArrayNotHasKey('Kde to opravit', $status);
    }

    public function testRowBadgesComeFromTheLabelCatalog(): void
    {
        $viewer = $this->viewer(['model' => 'claude-sonnet-5-5', 'effort' => 'high']);
        $rows = $viewer->selectRows(null, [], 0);
        $row = $viewer->renderRow($rows[0]);

        $this->assertSame('Vytěžení došlé pošty', $row['t1']);
        $this->assertSame(
            [['text' => 'Vyvážený', 'class' => 'muted'], ['text' => 'vlastní model', 'class' => 'primary'], ['text' => 'vlastní ladění', 'class' => 'primary']],
            $row['i1'],
        );
        $this->assertSame('claude-sonnet-5-5 · výchozí připojení', $row['t2']['text']);
    }

    public function testDetailOnBedrockEuShowsThePlatformModelAndTheSurcharge(): void
    {
        // tasks/ai-bedrock-eu.md D58, D60: výchozí připojení na Bedrocku EU.
        $bedrock = ['id' => 2, 'backend_id' => 'bedrock-eu', 'name' => 'Bedrock EU', 'provider' => 'bedrock-eu'] + self::CONNECTION;
        $detail = $this->viewer([], $bedrock)->renderDetail(3);

        $call = $this->items($detail, 'Volání');
        $this->assertSame('Amazon Bedrock (EU)', $call['Platforma']);
        $this->assertSame('https://bedrock-runtime.eu-central-1.amazonaws.com/anthropic', $call['Adresa API']);
        $this->assertSame('eu.anthropic.claude-sonnet-5-5 — podle úrovně modelu', $call['Model']);
        $this->assertSame('1,1× ceník katalogu', $call['Cena']);

        // Na přímém API se cena nevypisuje (násobitel 1).
        $this->assertArrayNotHasKey('Cena', $this->items($this->viewer([])->renderDetail(3), 'Volání'));
    }

    public function testRowEstimateUsesTheTierModelOfTheConnectionPlatform(): void
    {
        // tasks/ai-bedrock-eu.md D58: připojení řádku na Bedrocku EU → model úrovně pro platformu (ID katalogu).
        $viewer = $this->viewer(['connection' => 7, 'connection_name' => 'Bedrock EU', 'connection_active' => 1, 'connection_provider' => 'bedrock-eu']);
        $row = $viewer->renderRow($viewer->selectRows(null, [], 0)[0]);
        $this->assertSame([['text' => 'Vyvážený', 'class' => 'muted']], $row['i1'], 'model podle úrovně není vlastní model');
        $this->assertSame('claude-sonnet-5-5 · Bedrock EU', $row['t2']['text']);

        // Bez připojení na řádku rozhoduje platforma výchozího připojení (tu mock DB nezná → Anthropic).
        $viewer = $this->viewer([]);
        $row = $viewer->renderRow($viewer->selectRows(null, [], 0)[0]);
        $this->assertSame('claude-sonnet-4-6 · výchozí připojení', $row['t2']['text']);
    }
}
