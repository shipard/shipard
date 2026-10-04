<?php

declare(strict_types=1);

namespace Shipard\Core\Mail;

/**
 * Odesílatel, kterého určuje záznam (#90 D39) — odpověď
 * `RecordSenderProvider`. Obě části jsou nepovinné: bez adresy rozhodne
 * výchozí adresa zdroje dat, bez jména název vlastní firmy.
 */
final readonly class RecordSender
{
    public function __construct(
        public ?string $email = null,
        public ?string $name = null,
    ) {}
}
