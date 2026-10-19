<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates;

/**
 * Typ kurzovního lístku z `world.<země>.exchangeRateLists` (#121 D1).
 *
 * `maxAgeDays` = nejvyšší stáří lístku bez `valid_to`, do kterého se ještě
 * použije (U1); typ bez něj musí nést platnost na každém lístku.
 */
final readonly class ExchangeRateListType
{
    public function __construct(
        public string $id,
        public string $name,
        public string $homeCurrency,
        public ?int $maxAgeDays,
    ) {
    }

    public function requiresValidTo(): bool
    {
        return $this->maxAgeDays === null;
    }
}
