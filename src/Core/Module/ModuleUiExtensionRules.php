<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

/**
 * Pravidla pro rozšíření UI deklarovaná v `module.jsonc`
 * (`detailExtensions`, `formTabs`, `rowDecorators`, #172 D16–D19,
 * a `recordActions`, #172 D12) jako čistá
 * funkce nad definicemi — test je pouští nad skutečným stromem `modules/`
 * i nad syntetickými moduly. Tvar deklarace hlídá už
 * `ModuleDefinition::fromArray`; tady je to, co potřebuje načíst třídy nebo
 * vidět víc modulů najednou:
 *
 *  1. Třída existuje, implementuje rozhraní svého druhu
 *     ({@see ModuleUiExtensions::KINDS}), jde vytvořit bez argumentů
 *     konstruktoru a leží v namespace svého modulu
 *     (`Shipard\Module\<Skupina>\<Modul>\`), nebo modulu, na kterém
 *     modul (tranzitivně) závisí (#175) — společnou třídu tak modul
 *     deklaruje nad svou tabulkou (akce periodické fakturace nad tabulkou
 *     zdroje). Třída závislosti je ve zdroji dat vždy načtená s modulem,
 *     který ji deklaruje; třída cizího modulu bez závislosti ne.
 *  2. Tabulka je vlastní tabulka modulu, nebo tabulka modulu, na kterém
 *     modul (tranzitivně) závisí — rozšíření tabulky, kterou zdroj dat
 *     s aktivním modulem mít nemusí, by nikdy nedostalo slovo.
 *  3. Tatáž třída není pro tabulku v jednom druhu registrovaná dvakrát.
 *  4. Akce záznamu má `id` jedinečné v modulu — globální id akce
 *     `<id modulu>.<id>` je tím jedinečné ve stromu.
 */
final class ModuleUiExtensionRules
{
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
            $reachable = null;
            $reachableModules = null;
            foreach (ModuleUiExtensions::KINDS as $kind => $interface) {
                $seen = [];
                $seenIds = [];
                foreach ($definition->{$kind} as $idx => $reg) {
                    $where = "Module '{$id}': {$kind}[{$idx}]";

                    if (isset($reg['id'])) {
                        if (isset($seenIds[$reg['id']])) {
                            $violations[] = "{$where} id '{$reg['id']}' is already used in the module";
                        }
                        $seenIds[$reg['id']] = true;
                    }

                    $reachableModules ??= self::reachableModules($id, $byId);
                    self::checkClass($id, $reachableModules, $where, $reg['class'], $interface, $violations);

                    $reachable ??= self::reachableTables($reachableModules, $byId);
                    if (!isset($reachable[$reg['table']])) {
                        $violations[] = "{$where} table '{$reg['table']}' is neither a table of the module"
                            . ' nor of a module it depends on';
                    }

                    $pair = $reg['table'] . '|' . $reg['class'];
                    if (isset($seen[$pair])) {
                        $violations[] = "{$where} class '{$reg['class']}' is already registered for table '{$reg['table']}'";
                    }
                    $seen[$pair] = true;
                }
            }
        }

        return $violations;
    }

    /**
     * Modul a všechny moduly, na kterých tranzitivně závisí. Neznámá
     * závislost se přeskočí — hlásí ji resolver modulů.
     *
     * @param array<string, ModuleDefinition> $byId
     * @return list<string> id modulů, deklarující modul první
     */
    private static function reachableModules(string $moduleId, array $byId): array
    {
        $visited = [];
        $queue = [$moduleId];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($visited[$current]) || !isset($byId[$current])) {
                continue;
            }
            $visited[$current] = true;
            foreach ($byId[$current]->dependencies as $dependency) {
                if (is_string($dependency)) {
                    $queue[] = $dependency;
                }
            }
        }
        return array_keys($visited);
    }

    /**
     * Tabulky modulu a všech modulů, na kterých tranzitivně závisí.
     *
     * @param list<string> $moduleIds výsledek reachableModules()
     * @param array<string, ModuleDefinition> $byId
     * @return array<string, true>
     */
    private static function reachableTables(array $moduleIds, array $byId): array
    {
        $tables = [];
        foreach ($moduleIds as $moduleId) {
            foreach ($byId[$moduleId]->tables as $table) {
                if (is_string($table)) {
                    $tables[$table] = true;
                }
            }
        }
        return $tables;
    }

    /**
     * @param list<string> $reachableModules modul a jeho tranzitivní závislosti
     * @param class-string $interface
     * @param list<string> $violations
     */
    private static function checkClass(
        string $moduleId,
        array $reachableModules,
        string $where,
        string $class,
        string $interface,
        array &$violations,
    ): void {
        $where .= " class '{$class}'";
        $inNamespace = false;
        foreach ($reachableModules ?: [$moduleId] as $reachableId) {
            if (str_starts_with($class, ModuleServerConfigRules::moduleNamespace($reachableId))) {
                $inNamespace = true;
                break;
            }
        }
        if (!$inNamespace) {
            $violations[] = "{$where} is outside the module namespace "
                . ModuleServerConfigRules::moduleNamespace($moduleId)
                . ' and the namespaces of the modules it depends on';
            return;
        }
        if (!class_exists($class)) {
            $violations[] = "{$where} does not exist";
            return;
        }
        if (!is_subclass_of($class, $interface)) {
            $violations[] = "{$where} does not implement {$interface}";
            return;
        }
        $reflection = new \ReflectionClass($class);
        if (!$reflection->isInstantiable()
            || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0
        ) {
            $violations[] = "{$where} cannot be created without constructor arguments";
        }
    }
}
