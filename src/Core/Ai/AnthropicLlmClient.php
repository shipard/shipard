<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

use Shipard\Core\Ai\Exception\LlmApiException;
use Shipard\Core\Ai\Exception\LlmUnsupportedProviderException;

/**
 * Anthropic Messages API client, streaming, no tool-use (Phase 2a).
 *
 * Raw HTTP (curl) on purpose — the project has no PHP Anthropic SDK dependency
 * (the analyzer side is Python), and the task specifies a thin streamed path.
 *
 * SSE parsing is split from the network transport: `sendStreamingRequest()` is
 * the only method that touches curl and is `protected` so tests can override it
 * and feed fixture chunks (including chunks split mid-line). Everything else is
 * pure and unit-tested without a network.
 */
class AnthropicLlmClient implements LlmClient
{
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const DEFAULT_BASE_URL  = 'https://api.anthropic.com';

    public function streamChat(LlmChatParams $params, callable $onTextDelta): LlmChatResult
    {
        if ($params->provider !== 'anthropic') {
            throw new LlmUnsupportedProviderException($params->provider);
        }

        $body = [
            'model'      => $params->model,
            'max_tokens' => $params->maxTokens,
            'stream'     => true,
            'messages'   => self::normalizeMessages($params->messages),
        ];
        if ($params->system !== null && $params->system !== '') {
            $body['system'] = $params->system;
        }
        // Omitted when null: the 4.7 family and the 5 series reject
        // `temperature` with HTTP 400 (tasks/ai-models-phase0.md F0-D1).
        if ($params->temperature !== null) {
            $body['temperature'] = $params->temperature;
        }
        // Tuning from the backend row (F0-D2, F0-D7): null = model default,
        // nothing sent. Values are not validated against the model — an
        // unsupported combination comes back as HTTP 400 from the API.
        if ($params->thinking !== null && $params->thinking !== '') {
            $body['thinking'] = ['type' => $params->thinking];
        }
        if ($params->effort !== null && $params->effort !== '') {
            $body['output_config'] = ['effort' => $params->effort];
        }
        if ($params->tools !== null && $params->tools !== []) {
            $body['tools'] = $params->tools;
        }

        // Accumulator threaded through the SSE parser. `buf` holds the partial
        // line carried across chunk boundaries; `blocks` collects content blocks
        // keyed by their stream index (text + tool_use, finalized below);
        // `error` records an inline SSE error event (thrown after the stream
        // drains, not from the callback).
        $acc = [
            'buf'    => '',
            'text'   => '',
            'in'     => null,
            'out'    => null,
            'stop'   => null,
            'model'  => null,
            'error'  => null,
            'blocks' => [],
        ];

        $json = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->sendStreamingRequest($params, $json, function (string $chunk) use (&$acc, $onTextDelta): void {
            $this->feedSse($chunk, $acc, $onTextDelta);
        });

        if ($acc['error'] !== null) {
            throw new LlmApiException(200, $acc['error']['type'], $acc['error']['message']);
        }

        [$contentBlocks, $toolUses] = $this->finalizeBlocks($acc['blocks']);

        return new LlmChatResult(
            $acc['text'],
            $acc['in'],
            $acc['out'],
            $acc['stop'],
            $acc['model'],
            $toolUses,
            $contentBlocks,
        );
    }

    /**
     * Anthropic requires `tool_use.input` to be a JSON object. The PHP
     * round-trip (json_decode assoc in finalizeBlocks → persistence in
     * ChatController → json_encode here) turns an empty object `{}` into
     * `[]`, so a model calling a tool without arguments (e.g.
     * `mail_list_pending`) got HTTP 400 „input: Input should be an object"
     * on the next turn. Empty inputs are re-typed to stdClass right before
     * encoding; non-empty associative arrays already encode as objects.
     * Only the top-level `input` is known to be an object — nested empty
     * arrays are left alone (a JSON list `[]` inside input is legitimate).
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeMessages(array $messages): array
    {
        foreach ($messages as &$message) {
            if (!is_array($message['content'] ?? null)) {
                continue;
            }
            foreach ($message['content'] as &$block) {
                if (is_array($block) && ($block['type'] ?? '') === 'tool_use' && ($block['input'] ?? null) === []) {
                    $block['input'] = new \stdClass();
                }
            }
            unset($block);
        }
        unset($message);

        return $messages;
    }

    /**
     * Turns the index-keyed accumulator into ordered Anthropic content blocks
     * and the tool_use subset.
     *
     * @param array<int, array<string, mixed>> $rawBlocks
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array{id: string, name: string, input: array}>}
     */
    private function finalizeBlocks(array $rawBlocks): array
    {
        ksort($rawBlocks);
        $contentBlocks = [];
        $toolUses = [];

        foreach ($rawBlocks as $block) {
            if (($block['type'] ?? '') === 'text') {
                $contentBlocks[] = ['type' => 'text', 'text' => (string) ($block['text'] ?? '')];
            } elseif (($block['type'] ?? '') === 'tool_use') {
                $decoded = ($block['json'] ?? '') !== '' ? json_decode((string) $block['json'], true) : [];
                $input = is_array($decoded) ? $decoded : [];
                $contentBlocks[] = [
                    'type'  => 'tool_use',
                    'id'    => (string) ($block['id'] ?? ''),
                    'name'  => (string) ($block['name'] ?? ''),
                    'input' => $input,
                ];
                $toolUses[] = [
                    'id'    => (string) ($block['id'] ?? ''),
                    'name'  => (string) ($block['name'] ?? ''),
                    'input' => $input,
                ];
            }
        }

        return [$contentBlocks, $toolUses];
    }

    /**
     * Performs the streaming POST and invokes $onChunk for every received body
     * chunk (status < 400). On an error response the body is buffered and turned
     * into an LlmApiException once complete. Overridable for tests.
     *
     * @param callable(string $chunk): void $onChunk
     */
    protected function sendStreamingRequest(LlmChatParams $params, string $jsonBody, callable $onChunk): void
    {
        $baseUrl = rtrim($params->baseUrl !== '' ? $params->baseUrl : self::DEFAULT_BASE_URL, '/');
        $url = $baseUrl . '/v1/messages';

        $headers = [
            'content-type: application/json',
            'anthropic-version: ' . self::ANTHROPIC_VERSION,
        ];
        if ($params->apiKey !== null && $params->apiKey !== '') {
            $headers[] = 'x-api-key: ' . $params->apiKey;
        }

        $status = 0;
        $errorBody = '';

        $ch = curl_init($url);
        curl_setopt_array($ch, self::timeoutCurlOptions($params) + [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$status): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int) $m[1];
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$status, &$errorBody, $onChunk): int {
                if ($status >= 400) {
                    $errorBody .= $chunk;       // error response body, not SSE
                } else {
                    $onChunk($chunk);
                }
                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        curl_close($ch);

        if ($status >= 400) {
            throw self::exceptionFromErrorResponse($status, $errorBody);
        }
        if ($ok === false || $errno !== 0) {
            throw new LlmApiException(0, 'transport_error', $errmsg !== '' ? $errmsg : 'curl transport error');
        }
    }

    /**
     * curl options bounding the call: no bytes for `stallTimeoutSeconds`
     * (CURLOPT_LOW_SPEED_*) or more than `timeoutSeconds` in total
     * (CURLOPT_TIMEOUT) ends the request with a transport error (status 0).
     * Null params = no option set (today's unbounded behaviour).
     *
     * @return array<int, int>
     */
    public static function timeoutCurlOptions(LlmChatParams $params): array
    {
        $options = [];
        if ($params->stallTimeoutSeconds !== null && $params->stallTimeoutSeconds > 0) {
            $options[CURLOPT_LOW_SPEED_LIMIT] = 1;
            $options[CURLOPT_LOW_SPEED_TIME] = $params->stallTimeoutSeconds;
        }
        if ($params->timeoutSeconds !== null && $params->timeoutSeconds > 0) {
            $options[CURLOPT_TIMEOUT] = $params->timeoutSeconds;
        }
        return $options;
    }

    /**
     * Error response body (`{"type":"error","error":{"type","message",
     * "details":{"error_code"}}}`) → exception. A non-JSON body degrades to
     * `api_error` / `HTTP <status>`; `error_code` is carried when present
     * (exhausted spend limit — tasks/mail-analysis-inprocess.md D18).
     */
    public static function exceptionFromErrorResponse(int $status, string $body): LlmApiException
    {
        $decoded = json_decode($body, true);
        $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $type = (string) ($error['type'] ?? 'api_error');
        $msg = (string) ($error['message'] ?? "HTTP {$status}");
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];
        $code = isset($details['error_code']) && is_string($details['error_code']) && $details['error_code'] !== ''
            ? $details['error_code']
            : null;

        return new LlmApiException($status, $type, $msg, $code);
    }

    /**
     * Feeds a raw chunk into the SSE line buffer, dispatching every complete
     * `data:` line. Robust to chunks that split a line mid-way.
     *
     * @param array<string, mixed>          $acc
     * @param callable(string $text): void  $onTextDelta
     */
    private function feedSse(string $chunk, array &$acc, callable $onTextDelta): void
    {
        $acc['buf'] .= $chunk;
        while (($nl = strpos($acc['buf'], "\n")) !== false) {
            $line = rtrim(substr($acc['buf'], 0, $nl), "\r");
            $acc['buf'] = substr($acc['buf'], $nl + 1);

            if (!str_starts_with($line, 'data:')) {
                continue; // `event:` lines, blank separators, comments — ignored
            }
            $payload = trim(substr($line, 5));
            if ($payload === '') {
                continue;
            }
            $data = json_decode($payload, true);
            if (is_array($data)) {
                $this->dispatchEvent($data, $acc, $onTextDelta);
            }
        }
    }

    /**
     * @param array<string, mixed>          $data
     * @param array<string, mixed>          $acc
     * @param callable(string $text): void  $onTextDelta
     */
    private function dispatchEvent(array $data, array &$acc, callable $onTextDelta): void
    {
        switch ($data['type'] ?? '') {
            case 'message_start':
                if (isset($data['message']['usage']['input_tokens'])) {
                    $acc['in'] = (int) $data['message']['usage']['input_tokens'];
                }
                if (isset($data['message']['model'])) {
                    $acc['model'] = (string) $data['message']['model'];
                }
                break;

            case 'content_block_start':
                $index = (int) ($data['index'] ?? 0);
                $cb = $data['content_block'] ?? [];
                if (($cb['type'] ?? '') === 'text') {
                    $acc['blocks'][$index] = ['type' => 'text', 'text' => (string) ($cb['text'] ?? '')];
                } elseif (($cb['type'] ?? '') === 'tool_use') {
                    $acc['blocks'][$index] = [
                        'type' => 'tool_use',
                        'id'   => (string) ($cb['id'] ?? ''),
                        'name' => (string) ($cb['name'] ?? ''),
                        'json' => '',
                    ];
                }
                break;

            case 'content_block_delta':
                $index = (int) ($data['index'] ?? 0);
                $deltaType = $data['delta']['type'] ?? '';
                if ($deltaType === 'text_delta') {
                    $text = (string) ($data['delta']['text'] ?? '');
                    if ($text !== '') {
                        $acc['text'] .= $text;
                        if (isset($acc['blocks'][$index]) && $acc['blocks'][$index]['type'] === 'text') {
                            $acc['blocks'][$index]['text'] .= $text;
                        }
                        $onTextDelta($text);
                    }
                } elseif ($deltaType === 'input_json_delta') {
                    if (isset($acc['blocks'][$index]) && $acc['blocks'][$index]['type'] === 'tool_use') {
                        $acc['blocks'][$index]['json'] .= (string) ($data['delta']['partial_json'] ?? '');
                    }
                }
                break;

            case 'message_delta':
                if (isset($data['usage']['output_tokens'])) {
                    $acc['out'] = (int) $data['usage']['output_tokens'];
                }
                if (isset($data['delta']['stop_reason'])) {
                    $acc['stop'] = (string) $data['delta']['stop_reason'];
                }
                break;

            case 'error':
                $acc['error'] = [
                    'type'    => (string) ($data['error']['type'] ?? 'api_error'),
                    'message' => (string) ($data['error']['message'] ?? 'stream error'),
                ];
                break;

            // message_stop, content_block_stop, ping → ignored (blocks finalized post-stream)
        }
    }
}
