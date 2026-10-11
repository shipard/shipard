<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

use Shipard\Core\Ai\Exception\LlmApiException;
use Shipard\Core\Ai\Exception\LlmUnsupportedProviderException;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Anthropic Messages API client, streaming, with tool-use and thinking blocks.
 *
 * Raw HTTP (curl) on purpose — the project has no PHP Anthropic SDK dependency,
 * and the task specifies a thin streamed path.
 *
 * SSE parsing is split from the network transport: `sendStreamingRequest()` is
 * the only method that touches curl and is `protected` so tests can override it
 * and feed fixture chunks (including chunks split mid-line). Everything else is
 * pure and unit-tested without a network.
 *
 * Request shaping (tasks/ai-model-catalog.md D32): with a {@see ModelCatalog}
 * the params are passed through {@see RequestShaper} before the body is
 * built, so every caller gets a request the model accepts without touching
 * its own code. Adjustments are logged once per call; a model outside the
 * catalog is sent as-is and warned about once per process. Without a
 * catalog (null, or an empty one before `ds-upgrade`) nothing is shaped.
 *
 * Platforms (tasks/ai-bedrock-eu.md D57): the client serves every platform
 * with the `anthropic-messages` dialect — the direct API and Amazon Bedrock
 * (`{baseUrl}/v1/messages`, key in `x-api-key`, same request body and SSE
 * stream). Only the dialect is checked; the platform id in `provider` is
 * informational.
 *
 * Thinking blocks (tasks/chat-thinking-blocks.md D53, D56): `thinking` and
 * `redacted_thinking` blocks are collected from the stream exactly as they
 * arrive (empty text and the opaque signature included) and returned in
 * `contentBlocks` at their position, so a tool loop can send the turn back
 * unchanged. Their text is never surfaced; `$onThinking` only signals that a
 * block started. The signature is opaque — never log, store or display it.
 */
class AnthropicLlmClient implements LlmClient
{
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const DEFAULT_BASE_URL  = 'https://api.anthropic.com';

    /** Declared with a default so test subclasses that skip the constructor keep working. */
    private ?ModelCatalog $catalog = null;

    /** @var array<string, true> models already reported as missing from the catalog */
    private static array $unknownModelsWarned = [];

    /** @var array<string, true> content block types already reported as unknown */
    private static array $unknownBlockTypesWarned = [];

    public function __construct(?ModelCatalog $catalog = null)
    {
        $this->catalog = $catalog;
    }

    public function streamChat(LlmChatParams $params, callable $onTextDelta, ?callable $onThinking = null): LlmChatResult
    {
        // The platform (`provider`) may be the direct API or Amazon Bedrock;
        // both speak the Messages dialect, so the dialect decides (D57).
        if ($params->dialect !== AiPlatform::DIALECT_ANTHROPIC_MESSAGES) {
            throw new LlmUnsupportedProviderException($params->provider, $params->dialect);
        }

        $params = $this->shapeForModel($params);

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
        // nothing sent. Values the model does not accept were already
        // replaced or dropped by shapeForModel() when the catalog knows
        // the model; otherwise they go out verbatim (HTTP 400 from the API).
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
        // keyed by their stream index (text, tool_use, thinking — finalized
        // below); `error` records an inline SSE error event (thrown after the
        // stream drains, not from the callback).
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

        $this->sendStreamingRequest($params, $json, function (string $chunk) use (&$acc, $onTextDelta, $onThinking): void {
            $this->feedSse($chunk, $acc, $onTextDelta, $onThinking);
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
     * Shapes the params to the model's capabilities (D32). No catalog or an
     * empty one = untouched; a model the catalog does not know = untouched
     * plus one warning per process (the hosting gateway passes bare model
     * IDs through, so a backend pointed at it still resolves). With
     * `shapeAs` (custom model ID, tasks/ai-functions.md D43) the catalog
     * entry is looked up by that ID while `model` goes out verbatim.
     */
    private function shapeForModel(LlmChatParams $params): LlmChatParams
    {
        if ($this->catalog === null || $this->catalog->isEmpty()) {
            return $params;
        }
        $lookup = $params->shapeAs !== null && $params->shapeAs !== '' ? $params->shapeAs : $params->model;
        $spec = $this->catalog->find($lookup);
        if ($spec === null) {
            if (!isset(self::$unknownModelsWarned[$lookup])) {
                self::$unknownModelsWarned[$lookup] = true;
                ErrorLogger::warn('AnthropicLlmClient: model is not in the model catalog — request sent unshaped', [
                    'model' => $params->model,
                    'shapeAs' => $params->shapeAs,
                ]);
            }
            return $params;
        }
        [$shaped, $changes] = RequestShaper::shape($params, $spec);
        if ($changes !== []) {
            ErrorLogger::info('AnthropicLlmClient: request shaped to the model capabilities', [
                'model' => $params->model,
                'catalogModel' => $spec->id,
                'changes' => array_map('strval', $changes),
            ]);
        }
        return $shaped;
    }

    /** Forgets which unknown models were already warned about (tests). */
    public static function resetUnknownModelWarningsForTesting(): void
    {
        self::$unknownModelsWarned = [];
    }

    /**
     * A content block type the parser does not know is dropped — and said so
     * once per process per type, instead of silently (tasks/chat-thinking-blocks.md
     * step 1). A new block type the API introduces thus shows up in the log.
     */
    private static function warnUnknownBlockType(string $type): void
    {
        $key = $type !== '' ? $type : '(none)';
        if (isset(self::$unknownBlockTypesWarned[$key])) {
            return;
        }
        self::$unknownBlockTypesWarned[$key] = true;
        ErrorLogger::warn('AnthropicLlmClient: unknown content block type in the stream — block dropped', ['type' => $key]);
    }

    /** Forgets which unknown block types were already warned about (tests). */
    public static function resetUnknownBlockTypeWarningsForTesting(): void
    {
        self::$unknownBlockTypesWarned = [];
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
     * Every other block — thinking blocks above all — passes through exactly
     * as given: no re-typing, no dropping of empty fields. The API verifies
     * a returned thinking block byte for byte (D53).
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
     * and the tool_use subset. Thinking blocks keep their position; a thinking
     * block with empty text is a valid block and stays (the signature carries
     * the reasoning on the 5.5 series).
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
            } elseif (($block['type'] ?? '') === 'thinking') {
                $contentBlocks[] = [
                    'type'      => 'thinking',
                    'thinking'  => (string) ($block['thinking'] ?? ''),
                    'signature' => (string) ($block['signature'] ?? ''),
                ];
            } elseif (($block['type'] ?? '') === 'redacted_thinking') {
                $contentBlocks[] = $block; // opaque, returned exactly as received
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
     * "details":{"error_code"}}}`) → exception. A body without the `error`
     * object but with a top-level `message` / `Message` (AWS style on
     * Amazon Bedrock — tasks/ai-bedrock-eu.md step 4) keeps that message;
     * a non-JSON body degrades to `api_error` / `HTTP <status>`.
     * `error_code` is carried when present (exhausted spend limit —
     * tasks/mail-analysis-inprocess.md D18).
     */
    public static function exceptionFromErrorResponse(int $status, string $body): LlmApiException
    {
        $decoded = json_decode($body, true);
        $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $type = (string) ($error['type'] ?? 'api_error');
        $msg = trim((string) ($error['message'] ?? ''));
        if ($msg === '' && is_array($decoded)) {
            foreach (['message', 'Message'] as $key) {
                if (is_string($decoded[$key] ?? null) && trim($decoded[$key]) !== '') {
                    $msg = trim($decoded[$key]);
                    break;
                }
            }
        }
        if ($msg === '') {
            $msg = "HTTP {$status}";
        }
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
     * @param (callable(): void)|null       $onThinking
     */
    private function feedSse(string $chunk, array &$acc, callable $onTextDelta, ?callable $onThinking): void
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
                $this->dispatchEvent($data, $acc, $onTextDelta, $onThinking);
            }
        }
    }

    /**
     * @param array<string, mixed>          $data
     * @param array<string, mixed>          $acc
     * @param callable(string $text): void  $onTextDelta
     * @param (callable(): void)|null       $onThinking
     */
    private function dispatchEvent(array $data, array &$acc, callable $onTextDelta, ?callable $onThinking): void
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
                $cb = is_array($data['content_block'] ?? null) ? $data['content_block'] : [];
                $type = (string) ($cb['type'] ?? '');
                if ($type === 'text') {
                    $acc['blocks'][$index] = ['type' => 'text', 'text' => (string) ($cb['text'] ?? '')];
                } elseif ($type === 'tool_use') {
                    $acc['blocks'][$index] = [
                        'type' => 'tool_use',
                        'id'   => (string) ($cb['id'] ?? ''),
                        'name' => (string) ($cb['name'] ?? ''),
                        'json' => '',
                    ];
                } elseif ($type === 'thinking') {
                    // Text is empty by default on the 5.5 series; the signature
                    // comes through signature_delta. Both are kept verbatim.
                    $acc['blocks'][$index] = [
                        'type'      => 'thinking',
                        'thinking'  => (string) ($cb['thinking'] ?? ''),
                        'signature' => (string) ($cb['signature'] ?? ''),
                    ];
                    if ($onThinking !== null) {
                        $onThinking();
                    }
                } elseif ($type === 'redacted_thinking') {
                    $acc['blocks'][$index] = $cb; // opaque `data`, returned as received
                    if ($onThinking !== null) {
                        $onThinking();
                    }
                } else {
                    self::warnUnknownBlockType($type);
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
                } elseif ($deltaType === 'thinking_delta') {
                    if (isset($acc['blocks'][$index]) && $acc['blocks'][$index]['type'] === 'thinking') {
                        $acc['blocks'][$index]['thinking'] .= (string) ($data['delta']['thinking'] ?? '');
                    }
                } elseif ($deltaType === 'signature_delta') {
                    if (isset($acc['blocks'][$index]) && $acc['blocks'][$index]['type'] === 'thinking') {
                        $acc['blocks'][$index]['signature'] .= (string) ($data['delta']['signature'] ?? '');
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
