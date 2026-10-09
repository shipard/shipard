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
 * `temperature` is nullable on purpose: Opus 4.7/4.8 reject any temperature
 * (HTTP 400), so the caller passes null to omit it from the request.
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
    ) {}
}
