<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Module\Economy\ExchangeRates;

use Shipard\Module\Economy\ExchangeRates\ExchangeRate;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListType;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListTypes;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateLookup;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateResolver;

/**
 * Resolver bez databáze pro testy formulářů, applieru a banky: kurzy
 * z mapy `"měna|datum" => ExchangeRate` (nebo `"měna" => ExchangeRate` pro
 * každé datum), domácí měna → 1, jinak nenalezeno. Eviduje dotazy.
 */
final class FakeExchangeRateResolver extends ExchangeRateResolver
{
    /** @var list<array{0: string, 1: string}> */
    public array $lookups = [];

    /**
     * @param array<string, ExchangeRate|float|null> $rates
     */
    public function __construct(
        private readonly array $rates = [],
        private readonly string $home = 'czk',
        private readonly ?ExchangeRateListTypes $listTypes = null,
    ) {
        // Záměrně bez rodiče — žádná databáze.
    }

    public static function withDailyRate(string $currency, float $rate, string $validFrom = '2026-10-16', string $home = 'czk'): self
    {
        return new self([strtolower($currency) => new ExchangeRate($rate, 'cz.cnb.daily', $validFrom, '201', 1)], $home);
    }

    public function homeCurrency(): string
    {
        return $this->home;
    }

    public function types(): ExchangeRateListTypes
    {
        return $this->listTypes ?? new ExchangeRateListTypes([
            new ExchangeRateListType('cz.cnb.daily', 'ČNB — kurzy devizového trhu', $this->home, 7),
            new ExchangeRateListType('cz.cnb.other', 'ČNB — kurzy ostatních měn', $this->home, null),
        ]);
    }

    public function lookup(string $currency, string|\DateTimeInterface $date): ExchangeRateLookup
    {
        $currency = strtolower(trim($currency));
        $date = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : trim($date);
        $this->lookups[] = [$currency, $date];

        if ($currency === $this->home) {
            return new ExchangeRateLookup($currency, $date, ExchangeRate::home());
        }
        $hit = $this->rates[$currency . '|' . $date] ?? $this->rates[$currency] ?? null;
        if (is_float($hit) || is_int($hit)) {
            $hit = new ExchangeRate((float) $hit, 'cz.cnb.daily', $date, null, 1);
        }
        if ($hit instanceof ExchangeRate) {
            return new ExchangeRateLookup($currency, $date, $hit);
        }
        return new ExchangeRateLookup($currency, $date, null, [['listType' => 'cz.cnb.daily', 'reason' => ExchangeRateLookup::REASON_NO_LIST]]);
    }
}
