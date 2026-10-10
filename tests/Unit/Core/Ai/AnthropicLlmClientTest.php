<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AnthropicLlmClient;
use Shipard\Core\Ai\Exception\LlmApiException;
use Shipard\Core\Ai\Exception\LlmUnsupportedProviderException;
use Shipard\Core\Ai\LlmChatParams;

/**
 * Parsing tests for AnthropicLlmClient. The network transport is replaced by a
 * fixture fed through the protected sendStreamingRequest() seam — no real HTTP.
 */
class AnthropicLlmClientTest extends TestCase
{
    private function params(string $provider = 'anthropic'): LlmChatParams
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

    public function testUnsupportedProviderThrows(): void
    {
        $this->expectException(LlmUnsupportedProviderException::class);
        $this->client([])->streamChat($this->params('openai'), function (): void {});
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
}
