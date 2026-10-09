<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

/**
 * Zápis výsledku nebo selhání analýzy neproběhl: `VALIDATION_ERROR` (422,
 * tvar těla neodpovídá kontraktu v4 — `details` nese pole `field`), nebo
 * `INTERNAL_ERROR` (500, transakce selhala a byla odvolána).
 * `AnalysisController` výjimku mapuje 1:1 na `Response::error`.
 */
final class AnalysisResultException extends \RuntimeException
{
    public const VALIDATION_ERROR = 'VALIDATION_ERROR';
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    /**
     * @param list<array<string, mixed>> $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
