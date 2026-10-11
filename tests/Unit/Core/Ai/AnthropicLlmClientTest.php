<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiPlatform;
use Shipard\Core\Ai\AnthropicLlmClient;
use Shipard\Core\Ai\Exception\LlmApiException;
use Shipard\Core\Ai\Exception\LlmUnsupportedProviderException;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\LlmChatResult;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Utils\JsoncParser;

/**
 * Parsing tests for AnthropicLlmClient. The network transport is replaced by a
 * fixture fed through the protected sendStreamingRequest() seam — no real HTTP.
 */
class AnthropicLlmClientTest extends TestCase
{
    private function params(string $provider = 'anthropic', string $dialect = AiPlatform::DIALECT_ANTHROPIC_MESSAGES): LlmChatParams
    {
        return new LlmChatParams(
            provider: $provider,
            model: 'claude-opus-4-8',
            apiKey: 'sk-test',
            baseUrl: '',
            system: 'You are a test.',
            messages: [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]],
            maxTokens: 1024,
            temperature: null,
            dialect: $dialect,
        );
    }

    /** @param string[] $chunks */
    private function client(array $chunks): AnthropicLlmClient
    {
        return new class($chunks) extends AnthropicLlmClient {
            /** @param string[] $chunks */
            public function __construct(private array $chunks) {}

            protected function sendStreamingRequest(LlmChatParams $params, string $jsonBody, callable $onChunk): void
            {
                foreach ($this->chunks as $chunk) {
                    $onChunk($chunk);
                }
            }
        };
    }

    private function sampleStream(): string
    {
        return implode("\n", [
            'event: message_start',
            'data: {"type":"message_start","message":{"id":"msg_1","type":"message","role":"assistant","model":"claude-opus-4-8","content":[],"usage":{"input_tokens":42,"output_tokens":1}}}',
            '',
            'event: content_block_start',
            'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}',
            '',
            'event: ping',
            'data: {"type":"ping"}',
            '',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Ahoj"}}',
            '',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":", světe"}}',
            '',
            'event: content_block_stop',
            'data: {"type":"content_block_stop","index":0}',
            '',
            'event: message_delta',
            'data: {"type":"message_delta","delta":{"stop_reason":"end_turn","stop_sequence":null},"usage":{"output_tokens":7}}',
            '',
            'event: message_stop',
            'data: {"type":"message_stop"}',
            '',
        ]);
    }

    public function testParsesTextDeltasUsageAndStopReason(): void
    {
        // Split into tiny chunks to force mid-line splits across the buffer.
        $chunks = str_split($this->sampleStream(), 17);

        $deltas = [];
        $result = $this->client($chunks)->streamChat(
            $this->params(),
            function (string $t) use (&$deltas): void { $deltas[] = $t; },
        );

        $this->assertSame(['Ahoj', ', světe'], $deltas);
        $this->assertSame('Ahoj, světe', $result->text);
        $this->assertSame(42, $result->inputTokens);
        $this->assertSame(7, $result->outputTokens);
        $this->assertSame('end_turn', $result->stopReason);
        $this->assertSame('claude-opus-4-8', $result->model);
    }

    public function testUnsupportedDialectThrowsWhileAnyPlatformWithTheMessagesDialectIsServed(): void
    {
        // Rozhoduje dialekt, ne platforma (tasks/ai-bedrock-eu.md D57):
        // Bedrock mluví stejným Messages API jako přímé API.
        $result = $this->client([$this->sampleStream()])->streamChat($this->params('bedrock-eu'), function (): void {});
        $this->assertSame('Ahoj, světe', $result->text);

        try {
            $this->client([])->streamChat($this->params('openai', 'openai-responses'), function (): void {});
            $this->fail('Expected LlmUnsupportedProviderException');
        } catch (LlmUnsupportedProviderException $e) {
            $this->assertSame('openai', $e->provider);
            $this->assertSame('openai-responses', $e->dialect);
            $this->assertStringContainsString('openai-responses', $e->getMessage());
        }
    }

    public function testBedrockPlatformSendsTheSameBodyAsTheDirectApi(): void
    {
        // Jiná platforma, jiné ID modelu, stejné tělo: klient na platformě nic nemění.
        $direct = $this->capturedBodyWith($this->catalog(), $this->tunedParams('claude-sonnet-5-5', null, 'adaptive', 'low'));
        $bedrock = $this->capturedBodyWith($this->catalog(), new LlmChatParams(
            provider: 'bedrock-eu', model: 'eu.anthropic.claude-sonnet-5-5', apiKey: 'bedrock-key',
            baseUrl: 'https://bedrock-runtime.eu-central-1.amazonaws.com/anthropic',
            system: 'sys', messages: [['role' => 'user', 'content' => 'hi']], maxTokens: 1024,
            thinking: 'adaptive', effort: 'low',
        ));
        $this->assertSame('eu.anthropic.claude-sonnet-5-5', $bedrock['model']);
        unset($direct['model'], $bedrock['model']);
        $this->assertSame($direct, $bedrock);
    }

    public function testInlineErrorEventThrows(): void
    {
        $stream = "event: error\n"
            . 'data: {"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}' . "\n\n";

        try {
            $this->client([$stream])->streamChat($this->params(), function (): void {});
            $this->fail('Expected LlmApiException');
        } catch (LlmApiException $e) {
            $this->assertSame('overloaded_error', $e->errorType);
            $this->assertStringContainsString('Overloaded', $e->getMessage());
        }
    }

    public function testTimeoutCurlOptionsOnlyWhenRequested(): void
    {
        $this->assertSame([], AnthropicLlmClient::timeoutCurlOptions($this->params()));

        $bounded = new LlmChatParams(
            provider: 'anthropic', model: 'm', apiKey: 'k', baseUrl: '', system: null, messages: [],
            maxTokens: 10, stallTimeoutSeconds: 180, timeoutSeconds: 840,
        );
        $this->assertSame(
            [CURLOPT_LOW_SPEED_LIMIT => 1, CURLOPT_LOW_SPEED_TIME => 180, CURLOPT_TIMEOUT => 840],
            AnthropicLlmClient::timeoutCurlOptions($bounded),
        );

        $zero = new LlmChatParams(
            provider: 'anthropic', model: 'm', apiKey: 'k', baseUrl: '', system: null, messages: [],
            maxTokens: 10, stallTimeoutSeconds: 0, timeoutSeconds: null,
        );
        $this->assertSame([], AnthropicLlmClient::timeoutCurlOptions($zero));
    }

    public function testErrorResponseCarriesTypeMessageAndErrorCode(): void
    {
        $e = AnthropicLlmClient::exceptionFromErrorResponse(429, json_encode([
            'type' => 'error',
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Your organization has reached its monthly spend limit.',
                'details' => ['error_code' => 'enforced_spend_limit_reached'],
            ],
        ]));

        $this->assertSame(429, $e->statusCode);
        $this->assertSame('rate_limit_error', $e->errorType);
        $this->assertSame('enforced_spend_limit_reached', $e->errorCode);
        $this->assertStringContainsString('spend limit', $e->getMessage());
        $this->assertFalse($e->isTransient());
        $this->assertTrue($e->isSpendLimitReached());

        $plain = AnthropicLlmClient::exceptionFromErrorResponse(429, '{"type":"error","error":{"type":"rate_limit_error","message":"slow down"}}');
        $this->assertNull($plain->errorCode);
        $this->assertTrue($plain->isTransient());

        $garbage = AnthropicLlmClient::exceptionFromErrorResponse(502, '<html>bad gateway</html>');
        $this->assertSame('api_error', $garbage->errorType);
        $this->assertSame('HTTP 502', $garbage->getMessage());
        $this->assertNull($garbage->errorCode);
    }

    public function testErrorBodyWithoutErrorObjectKeepsItsMessage(): void
    {
        // Styl AWS na Bedrocku (tasks/ai-bedrock-eu.md krok 4): `{"message": …}` / `{"Message": …}`.
        $aws = AnthropicLlmClient::exceptionFromErrorResponse(403, '{"message":"Credential should be scoped to a valid region, not \'us-east-1\'."}');
        $this->assertSame(403, $aws->statusCode);
        $this->assertSame('api_error', $aws->errorType);
        $this->assertSame("Credential should be scoped to a valid region, not 'us-east-1'.", $aws->getMessage());
        $this->assertTrue($aws->isConfigurationError());

        $capital = AnthropicLlmClient::exceptionFromErrorResponse(404, '{"Message":"Model not found"}');
        $this->assertSame('Model not found', $capital->getMessage());

        // Prázdná zpráva v obou tvarech → holé HTTP; objekt `error` má přednost.
        $this->assertSame('HTTP 500', AnthropicLlmClient::exceptionFromErrorResponse(500, '{"message":"  "}')->getMessage());
        $both = AnthropicLlmClient::exceptionFromErrorResponse(401, '{"message":"outer","error":{"type":"authentication_error","message":"inner"}}');
        $this->assertSame('inner', $both->getMessage());
        $this->assertSame('authentication_error', $both->errorType);
    }

    public function testIsTransientByStatusAndStreamErrorType(): void
    {
        $this->assertTrue(new LlmApiException(0, 'transport_error', 'curl')->isTransient());
        $this->assertTrue(new LlmApiException(408, 'api_error', 'x')->isTransient());
        $this->assertTrue(new LlmApiException(429, 'rate_limit_error', 'x')->isTransient());
        $this->assertTrue(new LlmApiException(500, 'api_error', 'x')->isTransient());
        $this->assertTrue(new LlmApiException(529, 'overloaded_error', 'x')->isTransient());
        $this->assertFalse(new LlmApiException(400, 'invalid_request_error', 'x')->isTransient());
        $this->assertFalse(new LlmApiException(401, 'authentication_error', 'x')->isTransient());
        $this->assertFalse(new LlmApiException(404, 'not_found_error', 'x')->isTransient());

        // Inline chyba streamu (HTTP 200) — jen podle typu.
        $this->assertTrue(new LlmApiException(200, 'overloaded_error', 'x')->isTransient());
        $this->assertTrue(new LlmApiException(200, 'api_error', 'x')->isTransient());
        $this->assertTrue(new LlmApiException(200, 'rate_limit_error', 'x')->isTransient());
        $this->assertFalse(new LlmApiException(200, 'invalid_request_error', 'x')->isTransient());
    }

    public function testIsConfigurationErrorByStatusAndSpendLimit(): void
    {
        $this->assertTrue(new LlmApiException(401, 'authentication_error', 'x')->isConfigurationError());
        $this->assertTrue(new LlmApiException(403, 'permission_error', 'x')->isConfigurationError());
        $this->assertTrue(new LlmApiException(404, 'not_found_error', 'x')->isConfigurationError());
        $this->assertTrue(new LlmApiException(429, 'rate_limit_error', 'x', LlmApiException::ERROR_CODE_SPEND_LIMIT)->isConfigurationError());

        $this->assertFalse(new LlmApiException(0, 'transport_error', 'curl')->isConfigurationError());
        $this->assertFalse(new LlmApiException(400, 'invalid_request_error', 'x')->isConfigurationError());
        $this->assertFalse(new LlmApiException(429, 'rate_limit_error', 'x')->isConfigurationError());
        $this->assertFalse(new LlmApiException(500, 'api_error', 'x')->isConfigurationError());
        $this->assertFalse(new LlmApiException(200, 'invalid_request_error', 'x')->isConfigurationError());
    }

    public function testEmptyToolUseInputIsSentAsJsonObject(): void
    {
        // Regrese: model zavolal nástroj bez argumentů (mail_list_pending),
        // PHP round-trip udělal z `{}` prázdné pole a další kolo padlo na
        // API s „tool_use.input: Input should be an object".
        $captured = null;
        $client = new class($this->sampleStream(), function (string $body) use (&$captured): void {
            $captured = $body;
        }) extends AnthropicLlmClient {
            /** @param callable(string): void $capture */
            public function __construct(private string $stream, private $capture) {}

            protected function sendStreamingRequest(LlmChatParams $params, string $jsonBody, callable $onChunk): void
            {
                ($this->capture)($jsonBody);
                $onChunk($this->stream);
            }
        };

        $params = new LlmChatParams(
            provider: 'anthropic',
            model: 'claude-opus-4-8',
            apiKey: 'sk-test',
            baseUrl: '',
            system: null,
            messages: [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Co čeká v poště?']]],
                ['role' => 'assistant', 'content' => [
                    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'mail_list_pending', 'input' => []],
                    ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'persons_search', 'input' => ['query' => 'Acme', 'ids' => []]],
                ]],
                ['role' => 'user', 'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '{"summary":"Žádná čekající pošta."}'],
                    ['type' => 'tool_result', 'tool_use_id' => 'toolu_2', 'content' => '[]'],
                ]],
            ],
            maxTokens: 256,
        );

        $client->streamChat($params, function (): void {});

        $this->assertNotNull($captured);
        $decoded = json_decode((string) $captured, false);
        $blocks = $decoded->messages[1]->content;
        $this->assertInstanceOf(\stdClass::class, $blocks[0]->input, 'prázdný vstup musí zůstat objektem');
        $this->assertStringContainsString('"input":{}', (string) $captured);
        // Neprázdný vstup i prázdný seznam uvnitř vstupu se nemění.
        $this->assertSame('Acme', $blocks[1]->input->query);
        $this->assertSame([], $blocks[1]->input->ids);
        // tool_result zůstává beze změny (řetězcový content).
        $this->assertSame('[]', $decoded->messages[2]->content[1]->content);
    }

    /**
     * Tělo požadavku s laděním backendu (tasks/ai-models-phase0.md F0-D2,
     * F0-D7): `thinking` a `output_config` jen při nenulové hodnotě,
     * `temperature` chybí při null. Formát hodnot se neověřuje.
     *
     * @return array<string, mixed> dekódované tělo
     */
    private function capturedBody(LlmChatParams $params): array
    {
        $captured = null;
        $client = new class($this->sampleStream(), function (string $body) use (&$captured): void {
            $captured = $body;
        }) extends AnthropicLlmClient {
            /** @param callable(string): void $capture */
            public function __construct(private string $stream, private $capture) {}

            protected function sendStreamingRequest(LlmChatParams $params, string $jsonBody, callable $onChunk): void
            {
                ($this->capture)($jsonBody);
                $onChunk($this->stream);
            }
        };
        $client->streamChat($params, static function (): void {});
        return json_decode((string) $captured, true);
    }

    public function testTuningParamsAreSentOnlyWhenSet(): void
    {
        $base = [
            'provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'apiKey' => 'sk-test', 'baseUrl' => '',
            'system' => null, 'messages' => [['role' => 'user', 'content' => 'hi']], 'maxTokens' => 1024,
        ];

        $body = $this->capturedBody(new LlmChatParams(...$base));
        $this->assertArrayNotHasKey('temperature', $body);
        $this->assertArrayNotHasKey('thinking', $body);
        $this->assertArrayNotHasKey('output_config', $body);

        $body = $this->capturedBody(new LlmChatParams(...$base, thinking: 'between_tools', effort: 'low'));
        $this->assertSame(['type' => 'between_tools'], $body['thinking']);
        $this->assertSame(['effort' => 'low'], $body['output_config']);
        $this->assertArrayNotHasKey('temperature', $body);

        // Dnešní požadavek runneru analýzy: teplota 0 odchází, ladění ne.
        $body = $this->capturedBody(new LlmChatParams(...$base, temperature: 0.0));
        $this->assertSame(0, $body['temperature']);
        $this->assertArrayNotHasKey('thinking', $body);
        $this->assertArrayNotHasKey('output_config', $body);
    }

    public function testIsCompleteByStopReason(): void
    {
        foreach (['end_turn', 'tool_use', 'stop_sequence', null] as $stop) {
            $this->assertTrue((new LlmChatResult('', 1, 1, $stop, 'm'))->isComplete(), (string) $stop);
        }
        foreach (['max_tokens', 'refusal', 'model_context_window_exceeded', 'pause_turn'] as $stop) {
            $this->assertFalse((new LlmChatResult('', 1, 1, $stop, 'm'))->isComplete(), $stop);
        }
    }

    public function testParsesToolUseBlock(): void
    {
        $stream = implode("\n", [
            'event: message_start',
            'data: {"type":"message_start","message":{"id":"msg_2","model":"claude-opus-4-8","usage":{"input_tokens":50,"output_tokens":1}}}',
            '',
            'event: content_block_start',
            'data: {"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_1","name":"persons_search","input":{}}}',
            '',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":"{\"query\":"}}',
            '',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":"\"Acme\"}"}}',
            '',
            'event: content_block_stop',
            'data: {"type":"content_block_stop","index":0}',
            '',
            'event: message_delta',
            'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":12}}',
            '',
            'event: message_stop',
            'data: {"type":"message_stop"}',
            '',
        ]);

        $result = $this->client(str_split($stream, 19))->streamChat($this->params(), function (): void {});

        $this->assertSame('tool_use', $result->stopReason);
        $this->assertCount(1, $result->toolUses);
        $this->assertSame('toolu_1', $result->toolUses[0]['id']);
        $this->assertSame('persons_search', $result->toolUses[0]['name']);
        $this->assertSame(['query' => 'Acme'], $result->toolUses[0]['input']);
        // contentBlocks carries the tool_use block for faithful replay
        $this->assertSame('tool_use', $result->contentBlocks[0]['type']);
        $this->assertSame(['query' => 'Acme'], $result->contentBlocks[0]['input']);
    }

    public function testKeepsTextAndToolUseOrdering(): void
    {
        $stream = implode("\n", [
            'event: content_block_start',
            'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}',
            '',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hledám…"}}',
            '',
            'event: content_block_stop',
            'data: {"type":"content_block_stop","index":0}',
            '',
            'event: content_block_start',
            'data: {"type":"content_block_start","index":1,"content_block":{"type":"tool_use","id":"toolu_9","name":"documents_search","input":{}}}',
            '',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":1,"delta":{"type":"input_json_delta","partial_json":"{}"}}',
            '',
            'event: content_block_stop',
            'data: {"type":"content_block_stop","index":1}',
            '',
            'event: message_delta',
            'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":5}}',
            '',
        ]);

        $result = $this->client([$stream])->streamChat($this->params(), function (): void {});

        $this->assertSame('Hledám…', $result->text);
        $this->assertCount(2, $result->contentBlocks);
        $this->assertSame('text', $result->contentBlocks[0]['type']);
        $this->assertSame('Hledám…', $result->contentBlocks[0]['text']);
        $this->assertSame('tool_use', $result->contentBlocks[1]['type']);
        $this->assertSame('documents_search', $result->contentBlocks[1]['name']);
        $this->assertCount(1, $result->toolUses);
    }

    // -------------------------------------------------------------------
    // Thinking bloky (tasks/chat-thinking-blocks.md D53, D56)
    // -------------------------------------------------------------------

    /** @param list<string> $events `data:` payloady v pořadí */
    private function stream(array $events): string
    {
        $lines = [];
        foreach ($events as $json) {
            $type = json_decode($json, true)['type'] ?? 'x';
            $lines[] = "event: {$type}";
            $lines[] = 'data: ' . $json;
            $lines[] = '';
        }
        return implode("\n", $lines) . "\n";
    }

    public function testParsesThinkingBlockWithTextAndSignature(): void
    {
        $stream = $this->stream([
            '{"type":"message_start","message":{"id":"msg_3","model":"claude-sonnet-5-5","usage":{"input_tokens":30,"output_tokens":1}}}',
            '{"type":"content_block_start","index":0,"content_block":{"type":"thinking","thinking":"","signature":""}}',
            '{"type":"content_block_delta","index":0,"delta":{"type":"thinking_delta","thinking":"Nejdřív "}}',
            '{"type":"content_block_delta","index":0,"delta":{"type":"thinking_delta","thinking":"zjistím."}}',
            '{"type":"content_block_delta","index":0,"delta":{"type":"signature_delta","signature":"EqQBCgIYAhIM"}}',
            '{"type":"content_block_stop","index":0}',
            '{"type":"content_block_start","index":1,"content_block":{"type":"text","text":""}}',
            '{"type":"content_block_delta","index":1,"delta":{"type":"text_delta","text":"Ahoj"}}',
            '{"type":"content_block_stop","index":1}',
            '{"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":40}}',
            '{"type":"message_stop"}',
        ]);

        $deltas = [];
        $thinkingStarted = 0;
        $result = $this->client(str_split($stream, 13))->streamChat(
            $this->params(),
            function (string $t) use (&$deltas): void { $deltas[] = $t; },
            function () use (&$thinkingStarted): void { $thinkingStarted++; },
        );

        // Text úvah nejde do textu odpovědi ani do textových delt.
        $this->assertSame(['Ahoj'], $deltas);
        $this->assertSame('Ahoj', $result->text);
        $this->assertSame(1, $thinkingStarted, 'onThinking jednou na blok');
        $this->assertSame([
            ['type' => 'thinking', 'thinking' => 'Nejdřív zjistím.', 'signature' => 'EqQBCgIYAhIM'],
            ['type' => 'text', 'text' => 'Ahoj'],
        ], $result->contentBlocks);
        $this->assertSame([['type' => 'text', 'text' => 'Ahoj']], $result->contentWithoutThinking());
        $this->assertSame([], $result->toolUses);
    }

    public function testEmptyThinkingBlockWithSignatureIsKept(): void
    {
        // Řada 5.5: text úvahy je ve výchozím stavu prázdný, úvahu nese jen
        // signature. Blok je platný a při vracení povinný — nesmí vypadnout.
        $stream = $this->stream([
            '{"type":"content_block_start","index":0,"content_block":{"type":"thinking","thinking":"","signature":""}}',
            '{"type":"content_block_delta","index":0,"delta":{"type":"signature_delta","signature":"ErUBCkYIChgC"}}',
            '{"type":"content_block_stop","index":0}',
            '{"type":"content_block_start","index":1,"content_block":{"type":"tool_use","id":"toolu_7","name":"persons_search","input":{}}}',
            '{"type":"content_block_delta","index":1,"delta":{"type":"input_json_delta","partial_json":"{\"query\":\"Acme\"}"}}',
            '{"type":"content_block_stop","index":1}',
            '{"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":90}}',
        ]);

        $thinkingStarted = 0;
        $result = $this->client([$stream])->streamChat(
            $this->params(),
            static function (): void {},
            function () use (&$thinkingStarted): void { $thinkingStarted++; },
        );

        $this->assertSame(1, $thinkingStarted);
        $this->assertSame('', $result->text);
        $this->assertSame([
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'ErUBCkYIChgC'],
            ['type' => 'tool_use', 'id' => 'toolu_7', 'name' => 'persons_search', 'input' => ['query' => 'Acme']],
        ], $result->contentBlocks);
        $this->assertCount(1, $result->toolUses);
        $this->assertSame([
            ['type' => 'tool_use', 'id' => 'toolu_7', 'name' => 'persons_search', 'input' => ['query' => 'Acme']],
        ], $result->contentWithoutThinking());
    }

    public function testRedactedThinkingBlockIsKeptAsReceived(): void
    {
        $stream = $this->stream([
            '{"type":"content_block_start","index":0,"content_block":{"type":"redacted_thinking","data":"EmwKAhgBEgy3va3pzix/LafPsn4aDFIT2Xlxh0L5L8rLIyIw"}}',
            '{"type":"content_block_stop","index":0}',
            '{"type":"content_block_start","index":1,"content_block":{"type":"text","text":""}}',
            '{"type":"content_block_delta","index":1,"delta":{"type":"text_delta","text":"Hotovo."}}',
            '{"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":9}}',
        ]);

        $thinkingStarted = 0;
        $result = $this->client(str_split($stream, 23))->streamChat(
            $this->params(),
            static function (): void {},
            function () use (&$thinkingStarted): void { $thinkingStarted++; },
        );

        $this->assertSame(1, $thinkingStarted);
        $this->assertSame([
            ['type' => 'redacted_thinking', 'data' => 'EmwKAhgBEgy3va3pzix/LafPsn4aDFIT2Xlxh0L5L8rLIyIw'],
            ['type' => 'text', 'text' => 'Hotovo.'],
        ], $result->contentBlocks);
        $this->assertSame([['type' => 'text', 'text' => 'Hotovo.']], $result->contentWithoutThinking());
    }

    public function testKeepsThinkingTextAndToolUseOrderingAcrossBlocks(): void
    {
        // Dva thinking bloky v jednom tahu (mezi textem a nástrojem) — pořadí
        // podle indexu zůstává, onThinking jednou na každý blok.
        $stream = $this->stream([
            '{"type":"content_block_start","index":0,"content_block":{"type":"thinking","thinking":"","signature":""}}',
            '{"type":"content_block_delta","index":0,"delta":{"type":"signature_delta","signature":"sigA"}}',
            '{"type":"content_block_start","index":1,"content_block":{"type":"text","text":""}}',
            '{"type":"content_block_delta","index":1,"delta":{"type":"text_delta","text":"Hledám…"}}',
            '{"type":"content_block_start","index":2,"content_block":{"type":"thinking","thinking":"","signature":""}}',
            '{"type":"content_block_delta","index":2,"delta":{"type":"thinking_delta","thinking":"Zkusím nástroj."}}',
            '{"type":"content_block_delta","index":2,"delta":{"type":"signature_delta","signature":"sigB"}}',
            '{"type":"content_block_start","index":3,"content_block":{"type":"tool_use","id":"toolu_9","name":"documents_search","input":{}}}',
            '{"type":"content_block_delta","index":3,"delta":{"type":"input_json_delta","partial_json":"{}"}}',
            '{"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":5}}',
        ]);

        $thinkingStarted = 0;
        $result = $this->client([$stream])->streamChat(
            $this->params(),
            static function (): void {},
            function () use (&$thinkingStarted): void { $thinkingStarted++; },
        );

        $this->assertSame(2, $thinkingStarted);
        $this->assertSame(['thinking', 'text', 'thinking', 'tool_use'], array_column($result->contentBlocks, 'type'));
        $this->assertSame('sigA', $result->contentBlocks[0]['signature']);
        $this->assertSame('Zkusím nástroj.', $result->contentBlocks[2]['thinking']);
        $this->assertSame('Hledám…', $result->text);
        $this->assertSame(['text', 'tool_use'], array_column($result->contentWithoutThinking(), 'type'));
    }

    public function testResponseWithoutThinkingIsUnchangedAndOnThinkingIsOptional(): void
    {
        $thinkingStarted = 0;
        $result = $this->client([$this->sampleStream()])->streamChat(
            $this->params(),
            static function (): void {},
            function () use (&$thinkingStarted): void { $thinkingStarted++; },
        );
        $this->assertSame(0, $thinkingStarted);
        $this->assertSame([['type' => 'text', 'text' => 'Ahoj, světe']], $result->contentBlocks);
        $this->assertSame($result->contentBlocks, $result->contentWithoutThinking());

        // Bez onThinking (dnešní volající) thinking blok projde bez zavolání čehokoli.
        $stream = $this->stream([
            '{"type":"content_block_start","index":0,"content_block":{"type":"thinking","thinking":"","signature":""}}',
            '{"type":"content_block_delta","index":0,"delta":{"type":"signature_delta","signature":"sig"}}',
            '{"type":"content_block_start","index":1,"content_block":{"type":"text","text":""}}',
            '{"type":"content_block_delta","index":1,"delta":{"type":"text_delta","text":"Ok"}}',
            '{"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}',
        ]);
        $result = $this->client([$stream])->streamChat($this->params(), static function (): void {});
        $this->assertSame('Ok', $result->text);
        $this->assertSame('thinking', $result->contentBlocks[0]['type']);
    }

    public function testThinkingBlocksAreSentBackVerbatim(): void
    {
        // Druhý krok tool smyčky: asistentův tah s prázdným thinking blokem
        // a redacted_thinking jde do požadavku přesně tak, jak přišel —
        // žádné přetypování, žádné vynechání prázdných polí, stejné pořadí.
        $params = new LlmChatParams(
            provider: 'anthropic',
            model: 'claude-sonnet-5-5',
            apiKey: 'sk-test',
            baseUrl: '',
            system: null,
            messages: [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Najdi Acme']]],
                ['role' => 'assistant', 'content' => [
                    ['type' => 'thinking', 'thinking' => '', 'signature' => 'ErUBCkYIChgC'],
                    ['type' => 'redacted_thinking', 'data' => 'EmwKAhgB'],
                    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'persons_search', 'input' => []],
                ]],
                ['role' => 'user', 'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '[]'],
                ]],
            ],
            maxTokens: 256,
        );

        $body = $this->capturedBody($params);

        $this->assertSame([
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'ErUBCkYIChgC'],
            ['type' => 'redacted_thinking', 'data' => 'EmwKAhgB'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'persons_search', 'input' => []],
        ], $body['messages'][1]['content']);
        $this->assertStringContainsString('"thinking":""', json_encode($body['messages'][1]));
    }

    public function testUnknownBlockTypeIsDroppedAndWarnedOncePerType(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'shpd-llm-');
        ErrorLogger::resetForTesting();
        ErrorLogger::setLogPath($log);
        // ErrorLogger každý zápis zrcadlí přes error_log() — v CLI na stderr.
        $previousErrorLog = ini_set('error_log', '/dev/null');
        AnthropicLlmClient::resetUnknownBlockTypeWarningsForTesting();
        try {
            $stream = $this->stream([
                '{"type":"content_block_start","index":0,"content_block":{"type":"server_tool_use","id":"srvtoolu_1","name":"web_search","input":{}}}',
                '{"type":"content_block_stop","index":0}',
                '{"type":"content_block_start","index":1,"content_block":{"type":"server_tool_use","id":"srvtoolu_2","name":"web_search","input":{}}}',
                '{"type":"content_block_stop","index":1}',
                '{"type":"content_block_start","index":2,"content_block":{"type":"text","text":""}}',
                '{"type":"content_block_delta","index":2,"delta":{"type":"text_delta","text":"Ok"}}',
                '{"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}',
            ]);
            $result = $this->client([$stream])->streamChat($this->params(), static function (): void {});

            $this->assertSame([['type' => 'text', 'text' => 'Ok']], $result->contentBlocks);
            $written = (string) file_get_contents($log);
            $this->assertSame(1, substr_count($written, 'unknown content block type'), 'varování jednou za proces per typ');
            $this->assertStringContainsString('server_tool_use', $written);
        } finally {
            AnthropicLlmClient::resetUnknownBlockTypeWarningsForTesting();
            ErrorLogger::resetForTesting();
            ini_set('error_log', (string) $previousErrorLog);
            @unlink($log);
        }
    }

    // -------------------------------------------------------------------
    // Tvarování podle katalogu modelů (tasks/ai-model-catalog.md D32)
    // -------------------------------------------------------------------

    /** Katalog ze skutečného souboru v repozitáři. */
    private function catalog(): ModelCatalog
    {
        return ModelCatalog::fromArray(JsoncParser::parseFile(dirname(__DIR__, 4) . '/modules/core/ai/config/models.jsonc'));
    }

    /** @return array<string, mixed> dekódované tělo odeslané klientem s katalogem */
    private function capturedBodyWith(?ModelCatalog $catalog, LlmChatParams $params): array
    {
        $captured = null;
        $client = new class($catalog, $this->sampleStream(), function (string $body) use (&$captured): void {
            $captured = $body;
        }) extends AnthropicLlmClient {
            /** @param callable(string): void $capture */
            public function __construct(?ModelCatalog $catalog, private string $stream, private $capture)
            {
                parent::__construct($catalog);
            }

            protected function sendStreamingRequest(LlmChatParams $params, string $jsonBody, callable $onChunk): void
            {
                ($this->capture)($jsonBody);
                $onChunk($this->stream);
            }
        };
        $client->streamChat($params, static function (): void {});
        return json_decode((string) $captured, true);
    }

    /** @return array<string, mixed> */
    private function tunedParams(string $model, ?float $temperature, ?string $thinking, ?string $effort, int $maxTokens = 1024): LlmChatParams
    {
        return new LlmChatParams(
            provider: 'anthropic', model: $model, apiKey: 'sk-test', baseUrl: '',
            system: 'sys', messages: [['role' => 'user', 'content' => 'hi']], maxTokens: $maxTokens,
            temperature: $temperature, thinking: $thinking, effort: $effort,
        );
    }

    public function testCatalogShapesTheRequestForTheModel(): void
    {
        ErrorLogger::setLogLevel('error');
        try {
            // Backend se Sonnetem 5.5, kde zůstala teplota 0 a thinking disabled
            // (dřív HTTP 400): teplota se nepošle, disabled odejde jako between_tools.
            $body = $this->capturedBodyWith($this->catalog(), $this->tunedParams('claude-sonnet-5-5', 0.0, 'disabled', 'xhigh'));
            $this->assertArrayNotHasKey('temperature', $body);
            $this->assertSame(['type' => 'between_tools'], $body['thinking']);
            $this->assertSame(['effort' => 'high'], $body['output_config'], 'between_tools nejvýš high');

            // xhigh na Sonnetu 4.6 → high; teplota zůstává.
            $body = $this->capturedBodyWith($this->catalog(), $this->tunedParams('claude-sonnet-4-6', 0.0, null, 'xhigh'));
            $this->assertSame(0, $body['temperature']);
            $this->assertSame(['effort' => 'high'], $body['output_config']);

            // Strop max_tokens: Sonnet 4.5 má 64 000.
            $body = $this->capturedBodyWith($this->catalog(), $this->tunedParams('claude-sonnet-4-5-20250929', null, null, null, 100_000));
            $this->assertSame(64000, $body['max_tokens']);
        } finally {
            ErrorLogger::resetForTesting();
        }
    }

    /** Hotovo když: tělo pro výchozí backend (Sonnet 4.6, teplota 0, `auto`) je identické s katalogem i bez něj. */
    public function testDefaultBackendRequestIsIdenticalWithAndWithoutCatalog(): void
    {
        $params = $this->tunedParams('claude-sonnet-4-6', 0.0, null, null, 32768);

        $without = $this->capturedBodyWith(null, $params);
        $with = $this->capturedBodyWith($this->catalog(), $params);

        $this->assertSame($without, $with);
        $this->assertSame(0, $with['temperature']);
        $this->assertArrayNotHasKey('thinking', $with);
        $this->assertArrayNotHasKey('output_config', $with);
        $this->assertSame(32768, $with['max_tokens']);
    }

    public function testWithoutCatalogTheBodyIsUnchanged(): void
    {
        // Bez katalogu (null i prázdný) odejde kombinace, kterou model nepřijímá, beze změny.
        foreach ([null, ModelCatalog::empty()] as $catalog) {
            $body = $this->capturedBodyWith($catalog, $this->tunedParams('claude-sonnet-5-5', 0.0, 'disabled', 'xhigh'));
            $this->assertSame(0, $body['temperature']);
            $this->assertSame(['type' => 'disabled'], $body['thinking']);
            $this->assertSame(['effort' => 'xhigh'], $body['output_config']);
        }
    }

    public function testShapeAsDrivesShapingWhileTheModelGoesOutVerbatim(): void
    {
        // Vlastní ID modelu (tasks/ai-functions.md D43): katalog ho nezná,
        // tvaruje se podle `shapeAs` (Sonnet 5.5 odmítá teplotu), ID odchází beze změny.
        $params = $this->tunedParams('claude-sonnet-6', 0.0, 'disabled', 'xhigh');
        $custom = new LlmChatParams(
            provider: $params->provider, model: $params->model, apiKey: $params->apiKey, baseUrl: $params->baseUrl,
            system: $params->system, messages: $params->messages, maxTokens: $params->maxTokens,
            temperature: $params->temperature, thinking: $params->thinking, effort: $params->effort,
            shapeAs: 'claude-sonnet-5-5',
        );
        ErrorLogger::setLogLevel('error');
        try {
            $body = $this->capturedBodyWith($this->catalog(), $custom);
        } finally {
            ErrorLogger::resetForTesting();
        }
        $this->assertSame('claude-sonnet-6', $body['model']);
        $this->assertArrayNotHasKey('temperature', $body);
        $this->assertSame(['type' => 'between_tools'], $body['thinking']);
        $this->assertSame(['effort' => 'high'], $body['output_config']);
    }

    public function testModelOutsideTheCatalogIsSentUnshaped(): void
    {
        ErrorLogger::setLogLevel('error');
        AnthropicLlmClient::resetUnknownModelWarningsForTesting();
        try {
            $body = $this->capturedBodyWith($this->catalog(), $this->tunedParams('claude-test-model', 0.0, 'disabled', 'xhigh'));
            $this->assertSame(0, $body['temperature']);
            $this->assertSame(['type' => 'disabled'], $body['thinking']);
            $this->assertSame(['effort' => 'xhigh'], $body['output_config']);
        } finally {
            AnthropicLlmClient::resetUnknownModelWarningsForTesting();
            ErrorLogger::resetForTesting();
        }
    }
}
