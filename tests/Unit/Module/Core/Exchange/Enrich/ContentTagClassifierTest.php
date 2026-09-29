<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Enrich;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiBackendResolver;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\LlmChatResult;
use Shipard\Core\Ai\LlmClient;
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
        ?array $backend = ['provider' => 'anthropic', 'model' => 'claude-x', 'api_key' => 'enc', 'base_url' => null],
        ?string $apiKey = 'sk-test',
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

        $backends = $this->createMock(AiBackendResolver::class);
        $backends->method('defaultBackend')->willReturn($backend);
        $backends->method('backendByNdx')->willReturn($backend);
        $backends->method('apiKey')->willReturn($apiKey);

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['core.exchange.contentTags', $taxonomy],
        ]);

        return new ContentTagClassifier($llm, $backends, $config);
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

    public function testClientExceptionYieldsNull(): void
    {
        $llm = $this->createMock(LlmClient::class);
        $llm->method('streamChat')->willThrowException(new \RuntimeException('network down'));

        $this->assertNull($this->classifier('', llm: $llm)->classify($this->canonical()));
    }

    public function testMissingBackendYieldsNullWithoutLlmCall(): void
    {
        $llm = $this->createMock(LlmClient::class);
        $llm->expects($this->never())->method('streamChat');

        $backends = $this->createMock(AiBackendResolver::class);
        $backends->method('defaultBackend')->willReturn(null);
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturn(self::TAXONOMY);

        $classifier = new ContentTagClassifier($llm, $backends, $config);
        $this->assertNull($classifier->classify($this->canonical()));
    }

    public function testMissingTaxonomyYieldsNullWithoutLlmCall(): void
    {
        $llm = $this->createMock(LlmClient::class);
        $llm->expects($this->never())->method('streamChat');

        $backends = $this->createMock(AiBackendResolver::class);
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturn(null);

        $this->assertNull((new ContentTagClassifier($llm, $backends, $config))->classify($this->canonical()));
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
}
