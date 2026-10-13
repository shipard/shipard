<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

/** Jeden řádek výsledku `DataSourceCronSwitch::switch()`. */
final readonly class CronSwitchResult
{
    public function __construct(
        public string $dsId,
        public CronSwitchOutcome $outcome,
        /** Hotový řádek pro CLI ve znění `ds-cron`: `✓` / `·` / `✗`, ID a popis; bez Symfony tagů. */
        public string $message,
    ) {}

    public function isOk(): bool
    {
        return $this->outcome->isOk();
    }
}
