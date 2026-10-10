<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

/**
 * Input parameters for a single streaming chat turn.
 *
 * `messages` are already in Anthropic Messages API shape — a list of
 * `{role: 'user'|'assistant', content: <blocks>}` where content blocks match
 * the persisted core_chat_messages format.
 *
 * `temperature` is nullable on purpose: models from the 4.7 family on
 * (Opus 4.7/4.8, the whole 5 series) reject any non-default temperature
 * with HTTP 400, so null means "omit the parameter". Only the mail
 * analysis runner reads it from the backend row (tasks/ai-models-phase0.md
 * F0-D1); every other caller passes null.
 *
 * `thinking` / `effort` are the backend's tuning values after
 * `AiBackendResolver::tuning()` (`auto` → null = omit). Non-null values go
 * out verbatim as `thinking: {type}` and `output_config: {effort}`; whether
 * the model accepts them is not validated here (F0-D2).
 *
 * `stallTimeoutSeconds` / `timeoutSeconds` bound the HTTP call (no bytes
 * received for N seconds / whole request longer than N seconds → transport
 * error, status 0). Null = no limit, today's behaviour; the mail analysis
 * runner sets both so a hung call can never outlive its claim lease
 * (tasks/mail-analysis-inprocess.md D18).
 */
final readonly class LlmChatParams
{
    /**
     * @param array<int, array{role: string, content: mixed}>                       $messages
     * @param array<int, array{name: string, description: string, input_schema: array}>|null $tools
     *        Anthropic tool definitions; null = no tools offered (plain chat).
     */
    public function __construct(
        public string $provider,
        public string $model,
        public ?string $apiKey,
        public string $baseUrl,
        public ?string $system,
        public array $messages,
        public int $maxTokens,
        public ?float $temperature = null,
        public ?array $tools = null,
        public ?int $stallTimeoutSeconds = null,
        public ?int $timeoutSeconds = null,
        public ?string $thinking = null,
        public ?string $effort = null,
    ) {}
}
