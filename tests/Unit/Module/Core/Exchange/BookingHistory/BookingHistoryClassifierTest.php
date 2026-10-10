<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\BookingHistory;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiBackendResolver;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\LlmChatResult;
use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryClassifier;
use Shipard\Module\Core\Exchange\BookingHistory\TagCache;
use Shipard\Module\Core\Exchange\BookingHistory\TextCluster;

/** Dávková LLM klasifikace distinct textů + sidecar cache (D33). */
class BookingHistoryClassifierTest extends TestCase
{
    private const TAXONOMY = [
        'it.internet'     => ['name' => 'Internet connectivity'],
        'office.supplies' => ['name' => 'Office supplies'],
        'vehicle.fuel'    => ['name' => 'Fuel'],
    ];

    /** @var list<string> */
    private array $prompts = [];

    /** @var list<string> */
    private array $tempFiles = [];

    /** @param list<string|\Throwable> $responses odpověď (nebo výjimka) per volání */
    private function classifier(
        array $responses,
        ?TagCache $cache = null,
        int $batchSize = 50,
        ?array $backend = ['provider' => 'anthropic', 'model' => 'claude-x', 'api_key' => 'enc', 'base_url' => null],
        ?string $apiKey = 'sk-test',
        array $taxonomy = self::TAXONOMY,
    ): BookingHistoryClassifier {
        $calls = 0;
        $prompts = &$this->prompts;
        $llm = $this->createMock(LlmClient::class);
        $llm->method('streamChat')->willReturnCallback(
            static function (LlmChatParams $params, callable $onDelta) use (&$calls, $responses, &$prompts): LlmChatResult {
                $prompts[] = (string) $params->messages[0]['content'];
                $response = $responses[$calls++] ?? '[]';
                if ($response instanceof \Throwable) {
                    throw $response;
                }
                return new LlmChatResult($response, 100, 40, 'end_turn', 'claude-x');
            },
        );

        $backends = $this->createMock(AiBackendResolver::class);
        $backends->method('defaultBackend')->willReturn($backend);
        $backends->method('backendByNdx')->willReturn($backend);
        $backends->method('apiKey')->willReturn($apiKey);

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([['core.exchange.contentTags', $taxonomy]]);

        return new BookingHistoryClassifier($llm, $backends, $config, null, $cache, $batchSize);
    }

    /** @param list<string> $texts */
    private function clusters(array $texts): array
    {
        $out = [];
        foreach ($texts as $text) {
            $cluster = new TextCluster(mb_strtolower($text), $text);
            $cluster->rows = 10;
            $out[] = $cluster;
        }
        return $out;
    }

    public function testBatchOutputIsParsedAndUnknownTagsDropped(): void
    {
        $response = json_encode([
            ['i' => 0, 'tag' => 'it.internet'],
            ['i' => 1, 'tag' => 'nonsense.tag'],   // mimo enum → null
            ['i' => 2, 'tag' => null],             // legitimní „nic"
        ]);
        $clusters = $this->clusters(['Paušál internet', 'Toner', 'Různé']);

        $result = $this->classifier([$response])->classify($clusters);

        $this->assertSame(1, $result['calls']);
        $this->assertSame(3, $result['classified']);
        $this->assertSame(0, $result['failedBatches']);
        $this->assertSame([
            'paušál internet' => 'it.internet',
            'toner'           => null,
            'různé'           => null,
        ], $result['tags']);
    }

    public function testTextsAreSplitIntoBatches(): void
    {
        $clusters = $this->clusters(['a', 'b', 'c', 'd', 'e']);
        $responses = [
            json_encode([['i' => 0, 'tag' => 'it.internet'], ['i' => 1, 'tag' => 'it.internet']]),
            json_encode([['i' => 0, 'tag' => 'office.supplies'], ['i' => 1, 'tag' => null]]),
            json_encode([['i' => 0, 'tag' => 'vehicle.fuel']]),
        ];

        $progress = [];
        $result = $this->classifier($responses, batchSize: 2)->classify(
            $clusters,
            static function (int $done, int $total) use (&$progress): void {
                $progress[] = [$done, $total];
            },
        );

        $this->assertSame(3, $result['calls']);
        $this->assertSame(5, $result['classified']);
        $this->assertSame([[2, 5], [4, 5], [5, 5]], $progress);
        // Index v odpovědi je index **v dávce**, ne globální.
        $this->assertSame('office.supplies', $result['tags']['c']);
        $this->assertSame('vehicle.fuel', $result['tags']['e']);
        $this->assertCount(3, $this->prompts);
        $this->assertStringContainsString('[0] c', $this->prompts[1]);
        $this->assertStringContainsString('[1] d', $this->prompts[1]);
    }

    public function testFailedBatchDoesNotAbortRunNorPoisonCache(): void
    {
        $cache = $this->cache();
        $clusters = $this->clusters(['a', 'b']);
        $responses = [
            new \RuntimeException('network down'),
            json_encode([['i' => 0, 'tag' => 'it.internet']]),
        ];

        $result = $this->classifier($responses, cache: $cache, batchSize: 1)->classify($clusters);

        $this->assertSame(1, $result['failedBatches']);
        $this->assertSame(['b' => 'it.internet'], $result['tags']);
        $this->assertSame(['b' => 'it.internet'], $cache->load(BookingHistoryClassifier::PROMPT_VERSION));
    }

    /** Useknutá / odmítnutá dávka (F0-D4) selže jako dávka a neotráví cache. */
    public function testIncompleteOutputFailsTheBatchWithoutPoisoningCache(): void
    {
        foreach (['max_tokens', 'refusal'] as $stop) {
            $cache = $this->cache();
            $llm = $this->createMock(LlmClient::class);
            $llm->method('streamChat')->willReturn(
                new LlmChatResult(json_encode([['i' => 0, 'tag' => 'it.internet']]), 100, 40, $stop, 'claude-x'),
            );
            $backends = $this->createMock(AiBackendResolver::class);
            $backends->method('defaultBackend')->willReturn(['provider' => 'anthropic', 'model' => 'claude-x', 'api_key' => 'enc', 'base_url' => null]);
            $backends->method('apiKey')->willReturn('sk-test');
            $config = $this->createMock(ConfigRuntime::class);
            $config->method('cfgItem')->willReturnMap([['core.exchange.contentTags', self::TAXONOMY]]);

            $result = (new BookingHistoryClassifier($llm, $backends, $config, null, $cache))->classify($this->clusters(['a']));

            $this->assertSame(1, $result['failedBatches'], $stop);
            $this->assertSame([], $result['tags'], $stop);
            $this->assertSame([], $cache->load(BookingHistoryClassifier::PROMPT_VERSION), $stop);
        }
    }

    /** Teplota se neposílá ani při `0` na backendu (F0-D1); thinking / effort z backendu (F0-D7). */
    public function testTemperatureIsNeverSentAndTuningIsPassed(): void
    {
        $captured = null;
        $llm = $this->createMock(LlmClient::class);
        $llm->method('streamChat')->willReturnCallback(
            static function (LlmChatParams $params, callable $onDelta) use (&$captured): LlmChatResult {
                $captured = $params;
                return new LlmChatResult('[]', 100, 40, 'end_turn', 'claude-x');
            },
        );
        $backends = $this->createMock(AiBackendResolver::class);
        $backends->method('defaultBackend')->willReturn([
            'provider' => 'anthropic', 'model' => 'claude-x', 'api_key' => 'enc', 'base_url' => null,
            'temperature' => 0, 'thinking' => 'between_tools', 'effort' => 'medium',
        ]);
        $backends->method('apiKey')->willReturn('sk-test');
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([['core.exchange.contentTags', self::TAXONOMY]]);

        (new BookingHistoryClassifier($llm, $backends, $config))->classify($this->clusters(['a']));

        $this->assertInstanceOf(LlmChatParams::class, $captured);
        $this->assertNull($captured->temperature);
        $this->assertSame('between_tools', $captured->thinking);
        $this->assertSame('medium', $captured->effort);
        $this->assertSame(16000, $captured->maxTokens);
    }

    public function testNonJsonOutputFailsTheBatch(): void
    {
        $result = $this->classifier(['Sorry, I cannot do that.'])->classify($this->clusters(['a']));

        $this->assertSame(1, $result['failedBatches']);
        $this->assertSame([], $result['tags']);
    }

    public function testCodeFencedAndWrappedOutputTolerated(): void
    {
        $fenced = "```json\n[{\"i\": 0, \"tag\": \"it.internet\"}]\n```";
        $this->assertSame(
            ['a' => 'it.internet'],
            $this->classifier([$fenced])->classify($this->clusters(['a']))['tags'],
        );

        $wrapped = json_encode(['results' => [['i' => '0', 'tag' => 'vehicle.fuel']]]);
        $this->assertSame(
            ['a' => 'vehicle.fuel'],
            $this->classifier([$wrapped])->classify($this->clusters(['a']))['tags'],
        );
    }

    public function testSecondRunHitsCacheWithoutLlmCall(): void
    {
        $cache = $this->cache();
        $clusters = $this->clusters(['Paušál internet', 'Různé']);
        $response = json_encode([['i' => 0, 'tag' => 'it.internet'], ['i' => 1, 'tag' => null]]);

        $first = $this->classifier([$response], cache: $cache)->classify($clusters);
        $this->assertSame(1, $first['calls']);
        $this->assertSame(0, $first['cached']);

        // Druhý běh: klient by na volání hodil výjimku — nesmí být zavolán.
        $second = $this->classifier([new \RuntimeException('must not be called')], cache: $cache)
            ->classify($clusters);

        $this->assertSame(0, $second['calls']);
        $this->assertSame(2, $second['cached']);
        $this->assertSame(['paušál internet' => 'it.internet', 'různé' => null], $second['tags']);
    }

    public function testCacheIsInvalidatedByPromptVersion(): void
    {
        $cache = $this->cache();
        $cache->append(['a' => 'it.internet'], 'bh-tag-v0.0.1');

        $result = $this->classifier([json_encode([['i' => 0, 'tag' => 'vehicle.fuel']])], cache: $cache)
            ->classify($this->clusters(['a']));

        $this->assertSame(1, $result['calls'], 'jiná verze promptu = cache miss');
        $this->assertSame(['a' => 'vehicle.fuel'], $result['tags']);
    }

    public function testEmptyInputMakesNoCall(): void
    {
        $result = $this->classifier([new \RuntimeException('must not be called')])->classify([]);
        $this->assertSame(0, $result['calls']);
        $this->assertFalse($result['unavailable']);
    }

    public function testMissingBackendOrTaxonomyMeansUnavailableNotFailure(): void
    {
        $noBackend = $this->classifier([], backend: null)->classify($this->clusters(['a']));
        $this->assertTrue($noBackend['unavailable']);
        $this->assertSame(0, $noBackend['calls']);

        $noKey = $this->classifier([], apiKey: null)->classify($this->clusters(['a']));
        $this->assertTrue($noKey['unavailable']);

        $noTaxonomy = $this->classifier([], taxonomy: [])->classify($this->clusters(['a']));
        $this->assertTrue($noTaxonomy['unavailable']);
    }

    public function testCorruptCacheLinesAreIgnored(): void
    {
        $cache = $this->cache();
        file_put_contents($cache->path, "not json\n{\"tag\":\"x\"}\n", FILE_APPEND);
        $cache->append(['a' => 'it.internet'], BookingHistoryClassifier::PROMPT_VERSION);

        $this->assertSame(['a' => 'it.internet'], $cache->load(BookingHistoryClassifier::PROMPT_VERSION));
    }

    public function testInMemoryCacheNeitherReadsNorWrites(): void
    {
        $cache = TagCache::inMemory();
        $cache->append(['a' => 'it.internet'], BookingHistoryClassifier::PROMPT_VERSION);
        $this->assertSame([], $cache->load(BookingHistoryClassifier::PROMPT_VERSION));
    }

    private function cache(): TagCache
    {
        $input = sys_get_temp_dir() . '/bh-cache-' . uniqid() . '.jsonl';
        $this->tempFiles[] = $input;
        $this->tempFiles[] = $input . '.tags.jsonl';
        return TagCache::forInput($input);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];
        $this->prompts = [];
    }
}
