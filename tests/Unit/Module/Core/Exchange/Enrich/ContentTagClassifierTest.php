<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Enrich;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiFunctionResolver;
use Shipard\Core\Ai\Exception\AiFunctionException;
use Shipard\Core\Ai\ResolvedAiCall;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\LlmChatResult;
use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Exchange\Enrich\ContentTagClassifier;

class ContentTagClassifierTest extends TestCase
{
    private const TAXONOMY = [
        'vehicle.fuel' => ['name' => 'Fuel'],
        'vehicle.consumables' => ['name' => 'Vehicle consumables'],
        'it.software' => ['name' => 'Software'],
    ];

    /** @return array<string, mixed> */
    private function canonical(): array
    {
        return [
            'docType' => 'invoiceReceived',
            'selfParty' => 'customer',
            'supplier' => ['name' => 'Čerpací stanice s.r.o.', 'companyId' => '12345678'],
            'docNumber' => '2026-0042',
            'currency' => 'CZK',
            'rows' => [
                ['description' => 'Natural 95', 'totalPrice' => 1210.0],
                ['description' => 'Náplň do ostřikovačů', 'totalPrice' => 150.0],
            ],
            'totals' => ['totalAmount' => 1360.0],
        ];
    }

    private function classifier(
        string $llmText,
        array $taxonomy = self::TAXONOMY,
        ?ResolvedAiCall $call = null,
        bool $unresolved = false,
        ?LlmClient $llm = null,
        ?string &$capturedPrompt = null,
    ): ContentTagClassifier {
        if ($llm === null) {
            $llm = $this->createMock(LlmClient::class);
            $llm->method('streamChat')->willReturnCallback(
                static function (LlmChatParams $params, callable $onDelta) use ($llmText, &$capturedPrompt): LlmChatResult {
                    $capturedPrompt = (string) $params->messages[0]['content'];
                    return new LlmChatResult($llmText, 100, 40, 'end_turn', 'claude-x');
                },
            );
        }

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['core.exchange.contentTags', $taxonomy],
        ]);

        return new ContentTagClassifier($llm, $this->functions($call, $unresolved), $config);
    }

    /** Resolver AI funkce: hotové rozhodnutí, nebo nenastavená funkce (D40). */
    private function functions(?ResolvedAiCall $call = null, bool $unresolved = false): AiFunctionResolver
    {
        $functions = $this->createMock(AiFunctionResolver::class);
        if ($unresolved) {
            $functions->method('resolve')->willThrowException(new AiFunctionException(AiFunctionException::KEY_MISSING, 'no key'));
        } else {
            $functions->method('resolve')->willReturn($call ?? $this->call());
        }
        $functions->method('catalog')->willReturn(ModelCatalog::fromArray([
            'claude-x' => ['name' => 'X', 'order' => 1, 'tier' => 'fast', 'platformIds' => ['anthropic' => 'claude-x'], 'prices' => ['input' => 1, 'output' => 5]],
        ]));
        return $functions;
    }

    /** Spotřeba volání (F6b D56): tokeny, model a cena podle rozhodnutí resolveru; čtení ji nuluje. */
    public function testUsageOfTheLlmCallIsRecordedAndTakenOnce(): void
    {
        $classifier = $this->classifier('{"primaryTag": "vehicle.fuel", "confidence": 0.9, "rowExceptions": []}', call: $this->call(['catalogModel' => 'claude-x']));
        $this->assertNull($classifier->takeLastUsage(), 'před voláním nic');

        $this->assertSame('vehicle.fuel', $classifier->classify($this->canonical())['primaryTag']);
        // 100 × 1 + 40 × 5 = 300 / 1M = 0,0003 USD
        $this->assertSame(['tokensIn' => 100, 'tokensOut' => 40, 'model' => 'claude-x', 'costUsd' => 0.0003], $classifier->takeLastUsage());
        $this->assertNull($classifier->takeLastUsage(), 'čtení nuluje');

        $this->assertNull($this->classifier('{}')->classify($this->canonical())['primaryTag'], 'prázdný JSON = legitimní „bez štítku“');
        $noCatalogModel = $this->classifier('{"primaryTag": null, "confidence": 0, "rowExceptions": []}');
        $noCatalogModel->classify($this->canonical());
        $this->assertNull($noCatalogModel->takeLastUsage()['costUsd'], 'vlastní ID modelu mimo katalog = bez ceny, tokeny ano');
    }

    public function testUsageIsRecordedEvenForIncompleteOutputButNotWithoutACall(): void
    {
        $llm = $this->createMock(LlmClient::class);
        $llm->method('streamChat')->willReturn(new LlmChatResult('{"primaryTag": "vehicle.fuel"}', 100, 40, 'max_tokens', 'claude-x'));
        $classifier = $this->classifier('', llm: $llm);
        $this->assertNull($classifier->classify($this->canonical()));
        $this->assertSame(100, $classifier->takeLastUsage()['tokensIn'], 'useknutá odpověď = útrata nastala');

        $unresolved = $this->classifier('{}', unresolved: true);
        $this->assertNull($unresolved->classify($this->canonical()));
        $this->assertNull($unresolved->takeLastUsage(), 'nenastavená funkce = bez volání');
    }

    /** @param array<string, mixed> $overrides */
    private function call(array $overrides = []): ResolvedAiCall
    {
        $values = $overrides + [
            'functionId' => 'exchange.contentTags', 'platform' => 'anthropic', 'baseUrl' => '', 'apiKey' => 'sk-test',
            'model' => 'claude-x', 'shapeAs' => null, 'catalogModel' => null, 'maxTokens' => 8000,
            'temperature' => null, 'thinking' => null, 'effort' => null, 'connectionId' => 1, 'connectionCode' => 'default',
        ];
        return new ResolvedAiCall(...$values);
    }

    public function testValidJsonOutputParsed(): void
    {
        $out = json_encode([
            'primaryTag' => 'vehicle.fuel',
            'confidence' => 0.92,
            'rowExceptions' => [['rowIndex' => 1, 'tag' => 'vehicle.consumables']],
        ]);
        $result = $this->classifier($out)->classify($this->canonical());

        $this->assertNotNull($result);
        $this->assertSame('vehicle.fuel', $result['primaryTag']);
        $this->assertSame(0.92, $result['confidence']);
        $this->assertSame([['rowIndex' => 1, 'tag' => 'vehicle.consumables']], $result['rowExceptions']);
    }

    public function testCodeFencedJsonTolerated(): void
    {
        $out = "```json\n{\"primaryTag\": \"it.software\", \"confidence\": 0.8, \"rowExceptions\": []}\n```";
        $result = $this->classifier($out)->classify($this->canonical());

        $this->assertSame('it.software', $result['primaryTag']);
    }

    public function testUnknownTagDiscarded(): void
    {
        // Lekce enum: model si štítky vymýšlí — neznámý primaryTag → null,
        // neznámý tag v rowExceptions se zahodí.
        $out = json_encode([
            'primaryTag' => 'vehicle.spaceship',
            'confidence' => 0.99,
            'rowExceptions' => [
                ['rowIndex' => 0, 'tag' => 'made.up'],
                ['rowIndex' => 1, 'tag' => 'vehicle.fuel'],
            ],
        ]);
        $result = $this->classifier($out)->classify($this->canonical());

        $this->assertNotNull($result);
        $this->assertNull($result['primaryTag']);
        $this->assertSame([['rowIndex' => 1, 'tag' => 'vehicle.fuel']], $result['rowExceptions']);
    }

    public function testNullPrimaryTagIsLegitimate(): void
    {
        $out = json_encode(['primaryTag' => null, 'confidence' => 0.3, 'rowExceptions' => []]);
        $result = $this->classifier($out)->classify($this->canonical());

        $this->assertNotNull($result);
        $this->assertNull($result['primaryTag']);
    }

    public function testNonJsonOutputYieldsNull(): void
    {
        $this->assertNull($this->classifier('Sorry, I cannot classify this.')->classify($this->canonical()));
    }

    /** Useknutý nebo odmítnutý výstup (F0-D4) = totéž co selhání, bez parsování. */
    public function testIncompleteOutputYieldsNull(): void
    {
        foreach (['max_tokens', 'refusal'] as $stop) {
            $llm = $this->createMock(LlmClient::class);
            $llm->method('streamChat')->willReturn(
                new LlmChatResult('{"primaryTag": "vehicle.fuel", "confidence": 0.9, "rowExceptions": []}', 100, 40, $stop, 'claude-x'),
            );
            $this->assertNull($this->classifier('', llm: $llm)->classify($this->canonical()), $stop);
        }
    }

    /**
     * Parametry volání jdou z rozhodnutí resolveru (tasks/ai-functions.md
     * D40): výchozí = 8000 tokenů, bez teploty a ladění — stejné tělo jako
     * před změnou; přepsané ladění projde beze změny.
     */
    public function testParamsComeFromTheResolvedCall(): void
    {
        $captured = null;
        $llm = $this->createMock(LlmClient::class);
        $llm->method('streamChat')->willReturnCallback(
            static function (LlmChatParams $params, callable $onDelta) use (&$captured): LlmChatResult {
                $captured = $params;
                return new LlmChatResult('{"primaryTag": null, "confidence": 0, "rowExceptions": []}', 100, 40, 'end_turn', 'claude-x');
            },
        );

        $this->classifier('', llm: $llm)->classify($this->canonical());
        $this->assertInstanceOf(LlmChatParams::class, $captured);
        $this->assertSame('anthropic', $captured->provider);
        $this->assertSame('claude-x', $captured->model);
        $this->assertSame('sk-test', $captured->apiKey);
        $this->assertNull($captured->temperature);
        $this->assertNull($captured->thinking);
        $this->assertNull($captured->effort);
        $this->assertNull($captured->tools);
        $this->assertSame(8000, $captured->maxTokens);
        $this->assertStringContainsString('fixed', (string) $captured->system);

        $tuned = $this->call(['thinking' => 'adaptive', 'effort' => 'low', 'maxTokens' => 4000, 'baseUrl' => 'https://gw.example']);
        $this->classifier('', call: $tuned, llm: $llm)->classify($this->canonical());
        $this->assertSame('adaptive', $captured->thinking);
        $this->assertSame('low', $captured->effort);
        $this->assertSame(4000, $captured->maxTokens);
        $this->assertSame('https://gw.example', $captured->baseUrl);
    }

    public function testClientExceptionYieldsNull(): void
    {
        $llm = $this->createMock(LlmClient::class);
        $llm->method('streamChat')->willThrowException(new \RuntimeException('network down'));

        $this->assertNull($this->classifier('', llm: $llm)->classify($this->canonical()));
    }

    public function testUnresolvedFunctionYieldsNullWithoutLlmCall(): void
    {
        $llm = $this->createMock(LlmClient::class);
        $llm->expects($this->never())->method('streamChat');

        $this->assertNull($this->classifier('', unresolved: true, llm: $llm)->classify($this->canonical()));
    }

    public function testMissingTaxonomyYieldsNullWithoutLlmCall(): void
    {
        $llm = $this->createMock(LlmClient::class);
        $llm->expects($this->never())->method('streamChat');

        $functions = $this->createMock(AiFunctionResolver::class);
        $functions->expects($this->never())->method('resolve');
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturn(null);

        $this->assertNull((new ContentTagClassifier($llm, $functions, $config))->classify($this->canonical()));
    }

    public function testPromptContainsAllRealTaxonomyKeysAndDigest(): void
    {
        // Enum v promptu = klíče skutečného cfgItemu (drift guard promptu).
        $taxonomy = JsoncParser::parseFile(
            dirname(__DIR__, 6) . '/modules/core/exchange/config/contentTags.jsonc',
        );
        $captured = null;
        $out = json_encode(['primaryTag' => null, 'confidence' => 0, 'rowExceptions' => []]);
        $this->classifier($out, taxonomy: $taxonomy, capturedPrompt: $captured)
            ->classify($this->canonical());

        $this->assertNotNull($captured);
        foreach (array_keys($taxonomy) as $key) {
            $this->assertStringContainsString((string) $key, $captured);
        }
        $this->assertStringContainsString('Čerpací stanice s.r.o.', $captured);
        $this->assertStringContainsString('12345678', $captured);
        $this->assertStringContainsString('Natural 95', $captured);
        $this->assertStringContainsString('1360', $captured);
    }

    public function testPromptRowTextIsComposedNameBeforeDescription(): void
    {
        // Text řádku pro klasifikátor = CanonicalRowText (#84): název před
        // popisem, popis obsažený v názvu se nepřilepí. Dřív vyhrál samotný
        // item.description („DPHM Množství") a název se do promptu nedostal.
        $canonical = $this->canonical();
        $canonical['rows'] = [
            ['item' => ['name' => 'Natural 95', 'description' => 'DPHM Množství'], 'totalPrice' => 1210.0],
            ['item' => ['name' => 'Náplň do ostřikovačů', 'description' => 'náplň do ostřikovačů'], 'totalPrice' => 150.0],
        ];

        $captured = null;
        $out = json_encode(['primaryTag' => null, 'confidence' => 0, 'rowExceptions' => []]);
        $this->classifier($out, capturedPrompt: $captured)->classify($canonical);

        $this->assertNotNull($captured);
        $this->assertStringContainsString('[0] Natural 95 — DPHM Množství (total 1210)', $captured);
        $this->assertStringContainsString('[1] Náplň do ostřikovačů (total 150)', $captured);
    }

    public function testDigestIndexesAreCanonicalRowIndexesAndRowExceptionsPassThrough(): void
    {
        // Nález baseline 2026-10-15 (tasks/testing-phase4.md § 8, „rowIndex
        // posunutý o −3“) padl na poziční porovnání v CanonicalComparator,
        // ne sem: digest čísluje řádky indexem v canonical.rows (i u dlouhého
        // dokladu pod stropem ořezu) a rowIndex z odpovědi se nepřepočítává.
        $canonical = $this->canonical();
        $canonical['rows'] = [];
        for ($i = 0; $i < 44; $i++) {
            $canonical['rows'][] = ['item' => ['name' => "Položka {$i}"], 'totalPrice' => 10.0 + $i];
        }
        $exceptions = [];
        foreach (range(25, 43) as $i) {
            $exceptions[] = ['rowIndex' => $i, 'tag' => 'vehicle.consumables'];
        }
        $out = json_encode(['primaryTag' => 'vehicle.fuel', 'confidence' => 0.8, 'rowExceptions' => $exceptions]);

        $captured = null;
        $result = $this->classifier($out, capturedPrompt: $captured)->classify($canonical);

        $this->assertNotNull($captured);
        $this->assertStringContainsString("\n    [0] Položka 0 (total 10)\n", $captured);
        $this->assertStringContainsString("\n    [25] Položka 25 (total 35)\n", $captured);
        $this->assertStringContainsString("\n    [43] Položka 43 (total 53)\n", $captured);
        $this->assertStringNotContainsString('more rows omitted', $captured);
        $this->assertNotNull($result);
        $this->assertSame($exceptions, $result['rowExceptions']);
    }
}
