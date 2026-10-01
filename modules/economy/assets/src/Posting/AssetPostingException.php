<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Posting;

/**
 * Odmítnutí zaúčtování majetku s kódem pro klienta (`series_missing`,
 * `monthLocked`, `accounting_failed`, `notLastPeriod`, …) a volitelnými
 * podrobnostmi (chyby validace dokladu, zprávy účtování).
 */
final class AssetPostingException extends \DomainException
{
    /** @param list<array{code: string, message: string}> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
