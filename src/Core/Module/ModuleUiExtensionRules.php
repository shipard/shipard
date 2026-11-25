<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

/**
 * Pravidla pro rozšíření UI deklarovaná v `module.jsonc`
 * (`detailExtensions`, `formTabs`, `rowDecorators`, #172 D16–D19) jako čistá
 * funkce nad definicemi — test je pouští nad skutečným stromem `modules/`
 * i nad syntetickými moduly. Tvar deklarace hlídá už
 * `ModuleDefinition::fromArray`; tady je to, co potřebuje načíst třídy nebo
 * vidět víc modulů najednou:
 *
 *  1. Třída existuje, implementuje rozhraní svého druhu
 *     ({@see ModuleUiExtensions::KINDS}), jde vytvořit bez argumentů
 *     konstruktoru a leží v namespace svého modulu
 *     (`Shipard\Module\<Skupina>\<Modul>\`).
 *  2. Tabulka je vlastní tabulka modulu, nebo tabulka modulu, na kterém
 *     modul (tranzitivně) závisí — rozšíření tabulky, kterou zdroj dat
 *     s aktivním modulem mít nemusí, by nikdy nedostalo slovo.
 *  3. Tatáž třída není pro tabulku v jednom druhu registrovaná dvakrát.
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
            foreach (ModuleUiExtensions::KINDS as $kind => $interface) {
                $seen = [];
                foreach ($definition->{$kind} as $idx => $reg) {
                    $where = "Module '{$id}': {$kind}[{$idx}]";

                    self::checkClass($id, $where, $reg['class'], $interface, $violations);

                    $reachable ??= self::reachableTables($id, $byId);
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
     * Tabulky modulu a všech modulů, na kterých tranzitivně závisí.
     * Neznámá závislost se přeskočí — hlásí ji resolver modulů.
     *
     * @param array<string, ModuleDefinition> $byId
     * @return array<string, true>
     */
    private static function reachableTables(string $moduleId, array $byId): array
    {
        $tables = [];
        $visited = [];
        $queue = [$moduleId];
        while ($queue !== []) {
            $current = array_pop($queue);
            if (isset($visited[$current]) || !isset($byId[$current])) {
                continue;
            }
            $visited[$current] = true;
            foreach ($byId[$current]->tables as $table) {
                if (is_string($table)) {
                    $tables[$table] = true;
                }
            }
            foreach ($byId[$current]->dependencies as $dependency) {
                if (is_string($dependency)) {
                    $queue[] = $dependency;
                }
            }
        }
        return $tables;
    }

    /**
     * @param class-string $interface
     * @param list<string> $violations
     */
    private static function checkClass(
        string $moduleId,
        string $where,
        string $class,
        string $interface,
        array &$violations,
    ): void {
        $where .= " class '{$class}'";
        $namespace = ModuleServerConfigRules::moduleNamespace($moduleId);

        if (!str_starts_with($class, $namespace)) {
            $violations[] = "{$where} is outside the module namespace {$namespace}";
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
