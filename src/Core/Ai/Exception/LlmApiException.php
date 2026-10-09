<?php

declare(strict_types=1);

namespace Shipard\Core\Ai\Exception;

/**
 * Raised on transport failure or an API error response (HTTP 4xx/5xx, or an
 * inline SSE `error` event). Carries the HTTP status (0 for transport-level
 * failures, 200 for an inline stream error), the provider's error type
 * string and — when the error body names one — the provider's
 * `error.details.error_code`.
 */
class LlmApiException extends LlmException
{
    /**
     * Anthropic: the organisation's monthly spend limit is exhausted. Comes
     * back as 429 `rate_limit_error` without `retry-after` — the API stays
     * closed until the next month, so retrying is pointless
     * (tasks/mail-analysis-inprocess.md D18).
     */
    public const ERROR_CODE_SPEND_LIMIT = 'enforced_spend_limit_reached';

    /** Inline stream error types worth a retry (the stream itself was 200). */
    private const TRANSIENT_STREAM_TYPES = ['overloaded_error', 'api_error', 'rate_limit_error'];

    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorType,
        string $message,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Worth retrying: transport failure (status 0), 408, 429, 5xx, or an
     * inline stream error of an overload / rate-limit / server kind.
     * Exception: the exhausted spend limit — also a 429 — is not.
     */
    public function isTransient(): bool
    {
        if ($this->errorCode === self::ERROR_CODE_SPEND_LIMIT) {
            return false;
        }
        if ($this->statusCode === 0 || $this->statusCode === 408 || $this->statusCode === 429 || $this->statusCode >= 500) {
            return true;
        }
        if ($this->statusCode === 200) {
            return in_array($this->errorType, self::TRANSIENT_STREAM_TYPES, true);
        }
        return false;
    }

    public function isSpendLimitReached(): bool
    {
        return $this->errorCode === self::ERROR_CODE_SPEND_LIMIT;
    }
}
