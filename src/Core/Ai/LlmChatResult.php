<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

/**
 * Aggregated result of a streaming chat turn: the full text plus usage
 * telemetry collected from the stream's message_start / message_delta events.
 *
 * `contentBlocks` is the assistant turn's full block list in order (text +
 * tool_use) — for faithful persistence and feeding the turn back to the model.
 * `toolUses` is the tool_use subset (`[{id, name, input}]`); empty means the
 * model produced a final answer.
 */
final readonly class LlmChatResult
{
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
}
