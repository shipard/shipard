<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Queue;

use Shipard\Module\Economy\SalesRegistration\Engine\SendResult;

/** Výsledek jednoho pokusu fronty: nový stav tržby a výsledek odeslání. */
final readonly class AttemptResult
{
    public function __construct(
        public int $saleId,
        public int $attemptId,
        public string $state,
        public SendResult $result,
        public ?string $nextAttempt = null,
    ) {
    }
}
