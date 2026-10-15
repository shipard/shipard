<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

/** Chyba vstupu nebo preflightu evaluace (`ai-eval` exit 2) — nic se nezměnilo. */
final class EvalException extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
