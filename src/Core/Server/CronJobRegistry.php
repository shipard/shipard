<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

use Shipard\Core\Module\ModuleDefinition;

/**
 * Úlohy cronu všech modulů ve stromu (#172 D6) — dispatcher z nich pro
 * každý zdroj dat vybírá podle stavu a aktivních modulů. Staví se
 * z definic modulů (`DataSourceModules::definitions()`, jednou za běh).
 *
 * Pořadí ve slotu: `order` vzestupně, pak id modulu, pak pořadí deklarace
 * v `module.jsonc`.
 */
final class CronJobRegistry
{
    /** @var array<string, list<CronJob>> slot → seřazené úlohy */
    private array $bySlot = [];

    /** @param list<ModuleDefinition> $definitions */
    public function __construct(array $definitions)
    {
        $byId = [];
        foreach ($definitions as $definition) {
            $byId[$definition->id] = $definition;
        }
        // Řazení podle id modulu a pořadí deklarace dá průchod; usort je
        // stabilní, takže stačí srovnat `order`.
        ksort($byId, SORT_STRING);

        foreach ($byId as $id => $definition) {
            foreach ($definition->cronJobs as $job) {
                $this->bySlot[$job['slot']][] = new CronJob(
                    $job['command'],
                    $id,
                    $job['slot'],
                    $job['order'],
                    $job['states'],
                );
            }
        }
        foreach ($this->bySlot as &$jobs) {
            usort($jobs, static fn(CronJob $a, CronJob $b): int => $a->order <=> $b->order);
        }
        unset($jobs);
    }

    /** @return list<CronJob> */
    public function forSlot(string $slot): array
    {
        return $this->bySlot[$slot] ?? [];
    }
}
