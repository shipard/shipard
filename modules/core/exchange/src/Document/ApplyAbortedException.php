<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

/**
 * Věcné odmítnutí uvnitř transakční části `DocumentApplier::apply()`
 * (zámek cílového konceptu u `replaceConcept`, #110 Q2). NestedTransaction
 * vrátí vlastní práci a applier výjimku přemapuje na čistý
 * `ApplyResult::error($errorCode, …, $statusCode)` — na rozdíl od
 * neočekávané výjimky, která končí jako `internal_error` 500.
 */
final class ApplyAbortedException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode = 422,
    ) {
        parent::__construct($message);
    }
}
