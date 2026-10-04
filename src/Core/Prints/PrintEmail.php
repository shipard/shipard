<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

/** Předmět a tělo e-mailu, kterým se tisk odesílá (#90 D37) — prostý text. */
final readonly class PrintEmail
{
    public function __construct(
        public string $subject,
        public string $body,
    ) {}
}
