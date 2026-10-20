<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Module\Economy\ExchangeRates\Download;

use Shipard\Module\Economy\ExchangeRates\Download\ExchangeRateListPresence;

/** Přítomné lístky jako pole `typ → data`; `ranges` zaznamenává dotazované rozsahy. */
final class ArrayExchangeRateListPresence extends ExchangeRateListPresence
{
    /** @var list<array{0: string, 1: string, 2: string}> */
    public array $ranges = [];

    /** @param array<string, list<string>> $dates */
    public function __construct(public array $dates = [])
    {
    }

    public function datesFor(string $listType, string $from, string $to): array
    {
        $this->ranges[] = [$listType, $from, $to];
        $out = [];
        foreach ($this->dates[$listType] ?? [] as $date) {
            if ($date >= $from && $date <= $to) {
                $out[$date] = true;
            }
        }
        return $out;
    }

    public function hasAny(string $listType): bool
    {
        return ($this->dates[$listType] ?? []) !== [];
    }
}
