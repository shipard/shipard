<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates;

/**
 * Dohledaný kurz (#121 D4): `rate` je kurz ZA JEDNOTKU měny zaokrouhlený
 * na 6 desetinných míst (jako `exchange_rate` dokladu a transakce).
 * Domácí měna = kurz 1 bez lístku (`listType` null).
 */
final readonly class ExchangeRate
{
    public function __construct(
        public float $rate,
        public ?string $listType = null,
        public ?string $validFrom = null,
        public ?string $listNumber = null,
        public ?int $listId = null,
    ) {
    }

    public static function home(): self
    {
        return new self(1.0);
    }

    public function isHome(): bool
    {
        return $this->listType === null;
    }

    /** Kurz za jednotku z vyhlášeného kurzu za `amount` jednotek. */
    public static function perUnit(float|int|string $rate, int $amount): float
    {
        return round((float) $rate / max(1, $amount), 6);
    }
}
