<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates\Download;

/**
 * Jeden dotaz na službu = chybějící data jednoho typu v jednom
 * kalendářním měsíci (#121 task B §4.3). `todayPoll` = jen dnešní lístek
 * po vyhlášení (zkouší se každý běh, pokus se nezapisuje);
 * `pausedReason` = dotaz se v tomto běhu neposílá.
 */
final readonly class DownloadQuery
{
    public function __construct(
        public string $listType,
        public int $serviceListType,
        public string $month,
        public string $dateFrom,
        public string $dateTo,
        public bool $todayPoll = false,
        public ?string $pausedReason = null,
    ) {
    }

    public function paused(): bool
    {
        return $this->pausedReason !== null;
    }

    public function label(): string
    {
        return "{$this->listType} {$this->dateFrom}..{$this->dateTo}";
    }
}
