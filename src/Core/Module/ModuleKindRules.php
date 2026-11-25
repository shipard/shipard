<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

/**
 * Pravidla mezi druhy modulů (#170 D4) jako čistá funkce nad definicemi —
 * test je pouští nad skutečným stromem `modules/` i nad syntetickými moduly.
 *
 *  1. Modul jádra nezávisí, ani tranzitivně, na doplňku — jádro o doplňku
 *     neví (D6).
 *  2. `install.base` nezávisí, ani tranzitivně, na doplňku. Jiný instalační
 *     modul smí: dedikovaný zdroj dat může doplněk přinést rovnou (D1).
 *  3. Každý modul je dosažitelný z některého instalačního modulu, nebo je
 *     doplněk, nebo závislost doplňku. Co nesplní nic z toho, nejde ve
 *     zdroji dat zapnout jinak než ruční úpravou `main.json`.
 *
 * Závislost na modulu, který mezi definicemi není, se tu neposuzuje — hlásí
 * ji {@see ModuleResolver}. Cyklus výpočet nezastaví.
 */
final class ModuleKindRules
{
    /** Instalační modul, který doplněk nést nesmí. */
    public const string BASE_INSTALL_MODULE = 'install.base';

    private function __construct()
    {
    }

    /**
     * @param list<ModuleDefinition> $definitions
     * @return list<string> porušení pravidel, anglicky; prázdné = v pořádku
     */
    public static function violations(array $definitions): array
    {
        $byId = [];
        foreach ($definitions as $definition) {
            $byId[$definition->id] = $definition;
        }
        ksort($byId, SORT_STRING);

        $violations = [];

        foreach ($byId as $id => $definition) {
            $addons = self::addonsAmong(self::closure($id, $byId), $byId);
            if ($addons === []) {
                continue;
            }
            if ($definition->kind === ModuleKind::Core) {
                $violations[] = "Core module '{$id}' depends on add-on " . self::quoted($addons)
                    . ' — the core must not know about add-ons';
            } elseif ($id === self::BASE_INSTALL_MODULE) {
                $violations[] = "Install module '{$id}' depends on add-on " . self::quoted($addons)
                    . ' — an add-on is enabled per data source, not shipped with the base installation';
            }
        }

        $reachable = [];
        foreach ($byId as $id => $definition) {
            if ($definition->kind === ModuleKind::Core) {
                continue;
            }
            $reachable[$id] = true;
            foreach (self::closure($id, $byId) as $dependencyId) {
                $reachable[$dependencyId] = true;
            }
        }
        foreach ($byId as $id => $definition) {
            if (!isset($reachable[$id])) {
                $violations[] = "Module '{$id}' is not reachable from any install module"
                    . ' and is neither an add-on nor a dependency of one';
            }
        }

        return $violations;
    }

    /**
     * Tranzitivní závislosti modulu (bez modulu samotného), jen známé moduly.
     *
     * @param array<string, ModuleDefinition> $byId
     * @return list<string>
     */
    private static function closure(string $id, array $byId): array
    {
        $seen = [];
        $stack = $byId[$id]->dependencies;
        while ($stack !== []) {
            $dependencyId = (string) array_pop($stack);
            if ($dependencyId === $id || isset($seen[$dependencyId]) || !isset($byId[$dependencyId])) {
                continue;
            }
            $seen[$dependencyId] = true;
            foreach ($byId[$dependencyId]->dependencies as $next) {
                $stack[] = $next;
            }
        }
        $ids = array_keys($seen);
        sort($ids, SORT_STRING);
        return $ids;
    }

    /**
     * @param list<string> $ids
     * @param array<string, ModuleDefinition> $byId
     * @return list<string>
     */
    private static function addonsAmong(array $ids, array $byId): array
    {
        return array_values(array_filter(
            $ids,
            static fn (string $id): bool => $byId[$id]->kind === ModuleKind::Addon,
        ));
    }

    /** @param list<string> $ids */
    private static function quoted(array $ids): string
    {
        return implode(', ', array_map(static fn (string $id): string => "'{$id}'", $ids));
    }
}
