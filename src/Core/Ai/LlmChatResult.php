<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

/**
 * Aggregated result of a streaming chat turn: the full text plus usage
 * telemetry collected from the stream's message_start / message_delta events.
 *
 * `contentBlocks` is the assistant turn's full block list in order (text,
 * tool_use, thinking / redacted_thinking) — for feeding the turn back to the
 * model unchanged within a tool loop. {@see contentWithoutThinking()} is the
 * shape to persist (tasks/chat-thinking-blocks.md D53). `toolUses` is the
 * tool_use subset (`[{id, name, input}]`); empty means the model produced a
 * final answer.
 */
final readonly class LlmChatResult
{
    /** Block types that carry the model's reasoning — never stored, never shown. */
    public const array THINKING_BLOCK_TYPES = ['thinking', 'redacted_thinking'];

    /**
     * @param array<int, array{id: string, name: string, input: array}> $toolUses
     * @param array<int, array<string, mixed>>                           $contentBlocks
     */
    public function __construct(
        public string $text,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?string $stopReason,
        public ?string $model,
        public array $toolUses = [],
        public array $contentBlocks = [],
    ) {}

    /** Stop reasons after which the model's output is whole and usable. */
    private const COMPLETE_STOP_REASONS = ['end_turn', 'tool_use', 'stop_sequence'];

    /**
     * Whether the turn finished on its own terms (tasks/ai-models-phase0.md
     * F0-D4): `end_turn`, `tool_use`, `stop_sequence`, or no stop reason at
     * all (mocks, streams cut before `message_delta`). False for
     * `max_tokens` (truncated), `refusal` (safety classifier, may fire on
     * harmless content), `model_context_window_exceeded`, `pause_turn` and
     * anything new — callers must not parse such text as a finished answer.
     */
    public function isComplete(): bool
    {
        return $this->stopReason === null || in_array($this->stopReason, self::COMPLETE_STOP_REASONS, true);
    }

    /**
     * Content blocks without `thinking` / `redacted_thinking` — what the chat
     * stores and sends with later questions (D53). `text` and `toolUses` are
     * unaffected by thinking blocks either way.
     *
     * @return array<int, array<string, mixed>>
     */
    public function contentWithoutThinking(): array
    {
        return self::stripThinking($this->contentBlocks);
    }

    /**
     * @param array<int, mixed> $blocks
     * @return array<int, array<string, mixed>>
     */
    public static function stripThinking(array $blocks): array
    {
        return array_values(array_filter(
            $blocks,
            static fn ($block): bool => is_array($block)
                && !in_array($block['type'] ?? '', self::THINKING_BLOCK_TYPES, true),
        ));
    }
}
