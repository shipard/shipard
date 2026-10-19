<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates;

/**
 * Výsledek dohledání kurzu včetně důvodů per typ lístku — pro CLI
 * `exchange-rate` a diagnostiku (§4 bod 6). `rate` null = nenalezeno.
 */
final readonly class ExchangeRateLookup
{
    public const string REASON_INVALID_DATE = 'invalid_date';
    public const string REASON_NO_TYPES = 'no_types';
    public const string REASON_NO_LIST = 'no_list';
    /** Denní lístek starší než maxAgeDays typu (U1). */
    public const string REASON_LIST_STALE = 'list_stale';
    /** Lístek s omezenou platností po validTo (U1). */
    public const string REASON_LIST_EXPIRED = 'list_expired';
    /** Poslední lístek typu měnu neobsahuje — starší se nezkouší (U2). */
    public const string REASON_CURRENCY_MISSING = 'currency_missing';

    /**
     * @param list<array{listType: ?string, reason: string}> $reasons
     */
    public function __construct(
        public string $currency,
        public string $date,
        public ?ExchangeRate $rate,
        public array $reasons = [],
    ) {
    }

    public function found(): bool
    {
        return $this->rate !== null;
    }
}
