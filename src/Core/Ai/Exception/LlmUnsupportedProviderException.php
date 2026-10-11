<?php

declare(strict_types=1);

namespace Shipard\Core\Ai\Exception;

/**
 * Raised when the platform's API dialect has no client implementation
 * (today only `anthropic-messages`, shared by the direct API and Amazon
 * Bedrock — tasks/ai-bedrock-eu.md D57).
 */
class LlmUnsupportedProviderException extends LlmException
{
    public function __construct(public readonly string $provider, public readonly ?string $dialect = null)
    {
        parent::__construct(
            $dialect !== null
                ? "Unsupported LLM dialect '{$dialect}' of provider '{$provider}'"
                : "Unsupported LLM provider: {$provider}",
        );
    }
}
