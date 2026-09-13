<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Import;

/**
 * Odmítnutí importu starého podání s kódem pro REST i CLI (#55 D38):
 * `PERIOD_NOT_FOUND` 404, `DRAFT_EXISTS` / `INVALID_KIND` /
 * `DATE_FOUND_REQUIRED` / `PREVIOUS_FILING_MISSING` / `XML_UNREADABLE` /
 * `XML_TYPE_MISMATCH` / `NOT_IMPORTED` / `INVALID_DOC_STATE` /
 * `VALIDATION_ERROR` / `FILING_IMPORT_FAILED` 422, `BAD_REQUEST` 400,
 * `CONFIG_MISSING` 500. `details` nese to, co runner potřebuje k
 * rozhodnutí (u `DRAFT_EXISTS` id konceptu a jeho `legacy`).
 */
final class FilingImportException extends \DomainException
{
    /** @param array<int|string, mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
