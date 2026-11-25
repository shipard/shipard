<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

/**
 * Jedna úloha cronu deklarovaná modulem (`cronJobs` v `module.jsonc`,
 * #172 D6): příkaz `shpd-ds` s volbami, modul, který ji nese, slot, pořadí
 * ve slotu a lifecycle stavy zdroje dat, ve kterých běží.
 */
final readonly class CronJob
{
    /** @param list<string> $states */
    public function __construct(
        public string $command,
        public string $module,
        public string $slot,
        public int $order,
        public array $states,
    ) {}

    /** Úloha běží v tomto efektivním stavu zdroje dat. */
    public function runsIn(string $effectiveState): bool
    {
        return in_array($effectiveState, $this->states, true);
    }
}
