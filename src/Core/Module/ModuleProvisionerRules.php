<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

/**
 * Pravidla pro provisionery deklarované v `module.jsonc` (`provisioners`,
 * #174 D28) jako čistá funkce nad definicemi — test je pouští nad skutečným
 * stromem `modules/` i nad syntetickými moduly. Tvar deklarace hlídá už
 * `ModuleDefinition::fromArray`; tady je to, co potřebuje načíst třídy:
 *
 *  1. Třída leží v namespace svého modulu
 *     (`Shipard\Module\<Skupina>\<Modul>\`) — modul zakládá jen svá data.
 *  2. Třída existuje, implementuje {@see ModuleProvisioner} a jde vytvořit
 *     bez argumentů konstruktoru.
 */
final class ModuleProvisionerRules
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
            foreach ($definition->provisioners as $provisioner) {
                $problem = self::problemOf($id, $provisioner['class']);
                if ($problem !== null) {
                    $violations[] = "Module '{$id}': provisioners class '{$provisioner['class']}' {$problem}";
                }
            }
        }
        return $violations;
    }

    /**
     * Namespace tříd modulu (s koncovým zpětným lomítkem):
     * `economy.workOrders` → `Shipard\Module\Economy\WorkOrders\`.
     */
    public static function moduleNamespace(string $moduleId): string
    {
        [$group, $module] = explode('.', $moduleId, 2);
        return 'Shipard\\Module\\' . ucfirst($group) . '\\' . ucfirst($module) . '\\';
    }

    private static function problemOf(string $moduleId, string $class): ?string
    {
        $namespace = self::moduleNamespace($moduleId);
        if (!str_starts_with($class, $namespace)) {
            return "is outside the module namespace {$namespace}";
        }
        if (!class_exists($class)) {
            return 'does not exist';
        }
        if (!is_subclass_of($class, ModuleProvisioner::class)) {
            return 'does not implement ' . ModuleProvisioner::class;
        }

        $reflection = new \ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            return 'cannot be instantiated';
        }
        if (($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
            return 'has a constructor with required arguments';
        }
        return null;
    }
}
