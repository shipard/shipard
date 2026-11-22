<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

/**
 * Věcné odmítnutí uvnitř transakční části `DocumentApplier::apply()`
 * (zámek cílového konceptu u `replaceConcept`, #110 Q2; kontrolní součet
 * `totals` v pokynu, #166 D27). NestedTransaction vrátí vlastní práci
 * a applier výjimku přemapuje na čistý
 * `ApplyResult::error($errorCode, …, $statusCode)` — na rozdíl od
 * neočekávané výjimky, která končí jako `internal_error` 500.
 * `$issues` jdou do `_resolve.issues` odpovědi.
 */
final class ApplyAbortedException extends \RuntimeException
{
    /**
     * @param list<array<string, mixed>> $issues nálezy k odmítnutí (cesta v canonicalu, kód, zpráva)
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode = 422,
        public readonly array $issues = [],
    ) {
        parent::__construct($message);
    }
}
