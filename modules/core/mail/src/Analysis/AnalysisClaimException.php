<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

/**
 * Claim zprávy neproběhl. `errorCode` nese dnešní kódy pull protokolu
 * (`NOT_FOUND`, `INVALID_STATE`, `ALREADY_CLAIMED`, `NO_PROFILE`,
 * `NO_BACKEND`, `SECRETS_UNAVAILABLE`, `BACKEND_KEY_CORRUPTED`,
 * `BACKEND_KEY_MISSING`, `INTERNAL_ERROR`), `httpStatus` stav, na který ho
 * `AnalysisController::claim()` mapuje. Runner podle kódu rozhoduje, zda
 * zpráva zůstává ve frontě (konfigurace) nebo jde o chybu běhu.
 */
final class AnalysisClaimException extends \RuntimeException
{
    public const NOT_FOUND = 'NOT_FOUND';
    public const INVALID_STATE = 'INVALID_STATE';
    public const ALREADY_CLAIMED = 'ALREADY_CLAIMED';
    public const NO_PROFILE = 'NO_PROFILE';
    public const NO_BACKEND = 'NO_BACKEND';
    public const SECRETS_UNAVAILABLE = 'SECRETS_UNAVAILABLE';
    public const BACKEND_KEY_CORRUPTED = 'BACKEND_KEY_CORRUPTED';
    public const BACKEND_KEY_MISSING = 'BACKEND_KEY_MISSING';
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    /** Kódy, u kterých chybí konfigurace DS — zpráva zůstává ve frontě. */
    public const CONFIGURATION_CODES = [
        self::NO_PROFILE,
        self::NO_BACKEND,
        self::SECRETS_UNAVAILABLE,
        self::BACKEND_KEY_CORRUPTED,
        self::BACKEND_KEY_MISSING,
    ];

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isConfigurationError(): bool
    {
        return in_array($this->errorCode, self::CONFIGURATION_CODES, true);
    }
}
