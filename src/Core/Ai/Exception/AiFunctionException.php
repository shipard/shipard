<?php

declare(strict_types=1);

namespace Shipard\Core\Ai\Exception;

/**
 * Volání AI funkce nejde sestavit (tasks/ai-functions.md D40): chybí
 * připojení nebo klíč, model není na platformě, vlastní ID modelu bez
 * `model_like`.
 * Všechno jsou chyby nastavení zdroje dat, ne běhu — volající je mapují
 * na své kódy (chat `NO_BACKEND`, claim `BACKEND_KEY_MISSING`, …)
 * a opakování bez zásahu správce nepomůže.
 */
final class AiFunctionException extends LlmException
{
    public const string UNKNOWN_FUNCTION = 'UNKNOWN_FUNCTION';
    public const string NO_CONNECTION = 'NO_CONNECTION';
    public const string UNKNOWN_PLATFORM = 'UNKNOWN_PLATFORM';
    public const string KEY_MISSING = 'KEY_MISSING';
    public const string SECRETS_UNAVAILABLE = 'SECRETS_UNAVAILABLE';
    public const string KEY_CORRUPTED = 'KEY_CORRUPTED';
    public const string NO_MODEL = 'NO_MODEL';
    public const string UNKNOWN_MODEL = 'UNKNOWN_MODEL';
    public const string MODEL_NOT_ON_PLATFORM = 'MODEL_NOT_ON_PLATFORM';

    /** Kódy, za kterými je chybějící nebo nečitelný klíč připojení (dnes `BACKEND_KEY_*`). */
    public const array KEY_CODES = [self::KEY_MISSING, self::SECRETS_UNAVAILABLE, self::KEY_CORRUPTED];

    /** Kódy, za kterými je špatně zvolený model (D43). */
    public const array MODEL_CODES = [
        self::NO_MODEL,
        self::UNKNOWN_MODEL,
        self::MODEL_NOT_ON_PLATFORM,
    ];

    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isKeyError(): bool
    {
        return in_array($this->errorCode, self::KEY_CODES, true);
    }

    public function isModelError(): bool
    {
        return in_array($this->errorCode, self::MODEL_CODES, true);
    }
}
