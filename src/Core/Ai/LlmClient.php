<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

/**
 * Minimal, provider-agnostic door to a streaming chat LLM.
 *
 * Deliberately tiny: a single streaming call. The stream format itself is
 * not abstracted — only the text deltas, the start of a thinking block and
 * the final result (blocks + usage) are surfaced.
 */
interface LlmClient
{
    /**
     * Streams a model response. For each text delta, calls $onTextDelta with
     * the incremental text; $onThinking, when given, is called once at the
     * start of every thinking block, without its text (tasks/chat-thinking-blocks.md
     * D56). Returns the aggregated result (full text + content blocks + usage).
     *
     * @param callable(string $text): void $onTextDelta
     * @param (callable(): void)|null       $onThinking
     *
     * @throws Exception\LlmUnsupportedProviderException when the provider is not supported
     * @throws Exception\LlmApiException on transport / API errors
     */
    public function streamChat(LlmChatParams $params, callable $onTextDelta, ?callable $onThinking = null): LlmChatResult;
}
