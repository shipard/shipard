<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates;

use Shipard\Module\Economy\ExchangeRates\Download\ListDownloadConfig;

/**
 * Typ kurzovního lístku z `world.<země>.exchangeRateLists` (#121 D1).
 *
 * `maxAgeDays` = nejvyšší stáří lístku bez `valid_to`, do kterého se ještě
 * použije (U1); typ bez něj musí nést platnost na každém lístku.
 * `download` = stahování ze služby (task B, U2); chybí = typ se nestahuje,
 * neplatná konfigurace = `download` null a důvod v `downloadIssue`.
 */
final readonly class ExchangeRateListType
{
    public function __construct(
        public string $id,
        public string $name,
        public string $homeCurrency,
        public ?int $maxAgeDays,
        public ?ListDownloadConfig $download = null,
        public ?string $downloadIssue = null,
    ) {
    }

    public function requiresValidTo(): bool
    {
        return $this->maxAgeDays === null;
    }

    public function isDownloadable(): bool
    {
        return $this->download !== null;
    }
}
