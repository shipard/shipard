<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

use Shipard\Core\Config\ServerConfigSection;

/**
 * Pravidla pro sekce `server.json` deklarované v `module.jsonc`
 * (`serverConfig`, #172 D7, D10) jako čistá funkce nad definicemi — test je
 * pouští nad skutečným stromem `modules/` i nad syntetickými moduly. Tvar
 * deklarace hlídá už `ModuleDefinition::fromArray`; tady je to, co potřebuje
 * načíst třídy nebo vidět víc modulů najednou:
 *
 *  1. Třída existuje, implementuje {@see ServerConfigSection}, leží
 *     v namespace svého modulu (`Shipard\Module\<Skupina>\<Modul>\`) a její
 *     `key()` se shoduje s deklarací.
 *  2. Žádné dvě deklarace nekolidují: stejná cesta, nebo jedna je prefixem
 *     druhé po segmentech (`ai` × `ai.analysis` kolidují, `ai.analysis`
 *     × `ai.chat` ne) — sekci tak čte vždy právě jedna třída.
 *  3. První segment cesty není klíč, který čte jádro
 *     (`ServerConfig::CORE_KEYS`).
 */
final class ModuleServerConfigRules
{
    private function __construct()
    {
    }

    /**
     * @param list<ModuleDefinition> $definitions
     * @param list<string> $coreKeys klíče nejvyšší úrovně `server.json`, které čte jádro
     * @return list<string> porušení pravidel, anglicky; prázdné = v pořádku
     */
    public static function violations(array $definitions, array $coreKeys): array
    {
        $byId = [];
        foreach ($definitions as $definition) {
            $byId[$definition->id] = $definition;
        }
        ksort($byId, SORT_STRING);

        $violations = [];
        // cesta → id modulu, který ji deklaroval jako první
        $owners = [];

        foreach ($byId as $id => $definition) {
            foreach ($definition->serverConfig as $idx => $section) {
                $key = $section['key'];
                $where = "Module '{$id}': serverConfig[{$idx}] '{$key}'";

                self::checkClass($id, $where, $key, $section['class'], $violations);

                $first = explode('.', $key, 2)[0];
                if (in_array($first, $coreKeys, true)) {
                    $violations[] = "{$where} starts with '{$first}', a server.json key read by the core";
                }

                $collision = false;
                foreach ($owners as $other => $owner) {
                    if (!self::collide($key, $other)) {
                        continue;
                    }
                    $holder = $owner === $id ? 'this module' : "module '{$owner}'";
                    $violations[] = $other === $key
                        ? "{$where} is already declared by {$holder}"
                        : "{$where} collides with '{$other}' declared by {$holder}";
                    $collision = true;
                }
                if (!$collision) {
                    $owners[$key] = $id;
                }
            }
        }

        return $violations;
    }

    /**
     * Kolidují dvě cesty? Ano, když jsou stejné, nebo je jedna prefixem
     * druhé po segmentech — `ai` × `ai.analysis` ano, `ai` × `aiChat` ne.
     */
    public static function collide(string $a, string $b): bool
    {
        return $a === $b || str_starts_with($a, $b . '.') || str_starts_with($b, $a . '.');
    }

    /**
     * Namespace modulu (s koncovým zpětným lomítkem):
     * `economy.exchangeRates` → `Shipard\Module\Economy\ExchangeRates\`.
     */
    public static function moduleNamespace(string $moduleId): string
    {
        [$group, $module] = explode('.', $moduleId, 2);
        return 'Shipard\\Module\\' . ucfirst($group) . '\\' . ucfirst($module) . '\\';
    }

    /** @param list<string> $violations */
    private static function checkClass(string $moduleId, string $where, string $key, string $class, array &$violations): void
    {
        $where .= " class '{$class}'";

        if (!str_starts_with($class, self::moduleNamespace($moduleId))) {
            $violations[] = "{$where} is outside the module namespace " . self::moduleNamespace($moduleId);
            return;
        }
        if (!class_exists($class)) {
            $violations[] = "{$where} does not exist";
            return;
        }
        if (!is_subclass_of($class, ServerConfigSection::class)) {
            $violations[] = "{$where} does not implement " . ServerConfigSection::class;
            return;
        }
        $declared = $class::key();
        if ($declared !== $key) {
            $violations[] = "{$where} reports key '{$declared}'";
        }
    }
}
