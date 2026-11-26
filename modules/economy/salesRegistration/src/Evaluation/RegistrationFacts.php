<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Evaluation;

use Shipard\Module\Economy\SalesRegistration\SalesRegistrationType;

/**
 * Registrace ve stavu V pořádku, jak ji vidí vyhodnocení dokladu: typ,
 * platnost, výchozí jednotka a jednotky (id řádku → označení u správce daně).
 */
final readonly class RegistrationFacts
{
    /** @param array<int, string> $units id jednotky → `unit_id` */
    public function __construct(
        public int $id,
        public SalesRegistrationType $type,
        public ?string $validFrom,
        public ?string $validTo,
        public ?int $defaultUnit,
        public array $units = [],
    ) {
    }

    /** Platí registrace k danému dni (`Y-m-d`)? Prázdná mez neomezuje. */
    public function isValidOn(string $date): bool
    {
        return ($this->validFrom === null || $date >= $this->validFrom)
            && ($this->validTo === null || $date <= $this->validTo);
    }
}
