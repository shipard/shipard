<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates\Download;

/** Co jeden typ v okně očekává, má a postrádá (#121 task B §4.2). */
final readonly class TypeDownloadPlan
{
    /** @param list<string> $missing data Y-m-d vzestupně */
    public function __construct(
        public string $listType,
        public string $name,
        public int $expected,
        public int $present,
        public array $missing,
        public string $today,
        public bool $todayPublished,
        public bool $workingDays,
    ) {
    }
}
