<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

use Symfony\Component\Console\Command\Command;

/**
 * Pravidla pro příkazy a úlohy cronu deklarované v `module.jsonc`
 * (`commands`, `serverCommands`, `cronJobs`, #172 D2, D6) jako čistá funkce
 * nad definicemi — test je pouští nad skutečným stromem `modules/` i nad
 * syntetickými moduly. Tvar deklarace hlídá už `ModuleDefinition::fromArray`;
 * tady je to, co potřebuje načíst třídy nebo vidět víc modulů najednou:
 *
 *  1. Každá třída existuje, je `Command`, jde vytvořit bez argumentů, má
 *     jméno a leží v namespace svého modulu
 *     (`Shipard\Module\<Skupina>\<Modul>\Command\`).
 *  2. Jména příkazů (včetně aliasů) jsou unikátní v rámci binárky — mezi
 *     moduly navzájem i proti příkazům jádra. `shpd-ds` a `shpd-server` se
 *     spolu neporovnávají (`version` a `help` mají obě).
 *  3. První slovo `cronJobs[].command` je jméno příkazu z `commands` téhož
 *     modulu; tentýž řetězec příkazu není ve slotu dvakrát.
 */
final class ModuleCommandRules
{
    private function __construct()
    {
    }

    /**
     * @param list<ModuleDefinition> $definitions
     * @param list<string> $coreDsCommands jména (a aliasy) příkazů jádra `shpd-ds`
     * @param list<string> $coreServerCommands jména (a aliasy) příkazů jádra `shpd-server`
     * @return list<string> porušení pravidel, anglicky; prázdné = v pořádku
     */
    public static function violations(array $definitions, array $coreDsCommands, array $coreServerCommands): array
    {
        $byId = [];
        foreach ($definitions as $definition) {
            $byId[$definition->id] = $definition;
        }
        ksort($byId, SORT_STRING);

        $violations = [];

        // jméno → kdo ho drží („core“ nebo id modulu), zvlášť pro každou binárku
        $dsOwners = array_fill_keys($coreDsCommands, 'core');
        $serverOwners = array_fill_keys($coreServerCommands, 'core');
        // modul → primární jména jeho příkazů shpd-ds (pro vazbu úloh cronu)
        $dsNames = [];

        foreach ($byId as $id => $definition) {
            $dsNames[$id] = [];
            foreach ($definition->commands as $class) {
                $command = self::instantiate($id, 'commands', $class, $violations);
                if ($command === null) {
                    continue;
                }
                $dsNames[$id][] = (string) $command->getName();
                self::claimNames($command, $id, 'shpd-ds', $dsOwners, $violations);
            }
            foreach ($definition->serverCommands as $class) {
                $command = self::instantiate($id, 'serverCommands', $class, $violations);
                if ($command !== null) {
                    self::claimNames($command, $id, 'shpd-server', $serverOwners, $violations);
                }
            }
        }

        $slotJobs = [];
        foreach ($byId as $id => $definition) {
            foreach ($definition->cronJobs as $idx => $job) {
                $name = (string) strtok($job['command'], " \t");
                if (!in_array($name, $dsNames[$id], true)) {
                    $violations[] = "Module '{$id}': cronJobs[{$idx}] runs '{$name}',"
                        . ' which is not a command declared in commands of this module';
                }
                $key = $job['slot'] . "\n" . $job['command'];
                if (isset($slotJobs[$key])) {
                    $violations[] = "Module '{$id}': cronJobs[{$idx}] '{$job['command']}' is already declared"
                        . " in slot '{$job['slot']}' by module '{$slotJobs[$key]}'";
                    continue;
                }
                $slotJobs[$key] = $id;
            }
        }

        return $violations;
    }

    /**
     * Namespace, ve kterém leží třídy příkazů modulu (s koncovým zpětným
     * lomítkem): `economy.vat` → `Shipard\Module\Economy\Vat\Command\`.
     */
    public static function commandNamespace(string $moduleId): string
    {
        [$group, $module] = explode('.', $moduleId, 2);
        return 'Shipard\\Module\\' . ucfirst($group) . '\\' . ucfirst($module) . '\\Command\\';
    }

    /** @param list<string> $violations */
    private static function instantiate(string $moduleId, string $key, string $class, array &$violations): ?Command
    {
        $where = "Module '{$moduleId}': {$key} class '{$class}'";

        if (!str_starts_with($class, self::commandNamespace($moduleId))) {
            $violations[] = "{$where} is outside the module namespace " . self::commandNamespace($moduleId);
            return null;
        }
        if (!class_exists($class)) {
            $violations[] = "{$where} does not exist";
            return null;
        }
        if (!is_subclass_of($class, Command::class)) {
            $violations[] = "{$where} is not a " . Command::class;
            return null;
        }
        try {
            $command = new $class();
        } catch (\Throwable $e) {
            $violations[] = "{$where} cannot be created without arguments: " . $e->getMessage();
            return null;
        }
        if ((string) $command->getName() === '') {
            $violations[] = "{$where} has no command name";
            return null;
        }
        return $command;
    }

    /**
     * @param array<string, string> $owners
     * @param list<string> $violations
     */
    private static function claimNames(Command $command, string $moduleId, string $binary, array &$owners, array &$violations): void
    {
        foreach ([(string) $command->getName(), ...$command->getAliases()] as $name) {
            if (isset($owners[$name])) {
                $holder = $owners[$name] === 'core' ? 'the core' : "module '{$owners[$name]}'";
                $violations[] = "Module '{$moduleId}': {$binary} command '{$name}' is already registered by {$holder}";
                continue;
            }
            $owners[$name] = $moduleId;
        }
    }
}
