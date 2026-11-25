<?php

declare(strict_types=1);

namespace Shipard\Cli;

use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Server\DataSourceModules;
use Symfony\Component\Console\Command\Command;

/**
 * Příkazy deklarované v `module.jsonc` (`commands`, `serverCommands`,
 * #172 D2) nad definicemi modulů. Factory z nich registruje příkazy modulů
 * aktivních ve zdroji dat; mapa jméno → modul ze **všech** modulů slouží
 * chybě pro příkaz neaktivního modulu (D4) a staví se až při prvním dotazu
 * — běžný běh instancuje jen příkazy, které registruje.
 *
 * Vadnou deklaraci (třída chybí, není `Command`) hlídá `ModuleCommandRules`
 * testem nad stromem; tady registrace skončí výjimkou, která modul a třídu
 * jmenuje, a mapa pro D4 takovou třídu přeskočí.
 */
final class ModuleCommands
{
    /** @var array<string, ModuleDefinition>|null id → definice, seřazeno podle id; null = ještě nenačteno */
    private ?array $definitions = null;

    /** @var array<string, string>|null jméno nebo alias příkazu shpd-ds → id modulu */
    private ?array $dsOwners = null;

    /**
     * @param DataSourceModules $modules zdroj definic modulů — čtou se až
     *     při prvním dotazu (mimo zdroj dat se strom modulů vůbec nenačte,
     *     dokud někdo nezadá příkaz, který jádro nezná)
     */
    public function __construct(private readonly DataSourceModules $modules)
    {
    }

    /** @return array<string, ModuleDefinition> */
    private function definitions(): array
    {
        if ($this->definitions === null) {
            $byId = [];
            foreach ($this->modules->definitions() ?? [] as $definition) {
                $byId[$definition->id] = $definition;
            }
            ksort($byId, SORT_STRING);
            $this->definitions = $byId;
        }
        return $this->definitions;
    }

    /**
     * Příkazy `shpd-ds` po modulech — jen moduly s příkazy, v pořadí id.
     *
     * @param list<string>|null $activeIds aktivní moduly zdroje dat; null = všechny moduly
     * @return list<ModuleCommandGroup>
     */
    public function dsGroups(?array $activeIds): array
    {
        $groups = [];
        foreach ($this->definitions() as $id => $definition) {
            if ($definition->commands === [] || ($activeIds !== null && !in_array($id, $activeIds, true))) {
                continue;
            }
            $groups[] = self::group($definition, 'commands', $definition->commands);
        }
        return $groups;
    }

    /**
     * Příkazy `shpd-server` po modulech — všechny moduly ve stromu.
     *
     * @return list<ModuleCommandGroup>
     */
    public function serverGroups(): array
    {
        $groups = [];
        foreach ($this->definitions() as $definition) {
            if ($definition->serverCommands !== []) {
                $groups[] = self::group($definition, 'serverCommands', $definition->serverCommands);
            }
        }
        return $groups;
    }

    /** Modul, kterému patří příkaz `shpd-ds` tohoto jména (nebo aliasu); null = žádnému. */
    public function dsOwner(string $name): ?string
    {
        if ($this->dsOwners === null) {
            $this->dsOwners = [];
            foreach ($this->definitions() as $id => $definition) {
                foreach ($definition->commands as $class) {
                    try {
                        $command = self::instantiate($id, 'commands', $class);
                    } catch (\Throwable) {
                        continue;
                    }
                    foreach ([(string) $command->getName(), ...$command->getAliases()] as $commandName) {
                        $this->dsOwners[$commandName] ??= $id;
                    }
                }
            }
        }
        return $this->dsOwners[$name] ?? null;
    }

    /** @param list<class-string> $classes */
    private static function group(ModuleDefinition $definition, string $key, array $classes): ModuleCommandGroup
    {
        $commands = [];
        foreach ($classes as $class) {
            $commands[] = self::instantiate($definition->id, $key, $class);
        }
        return new ModuleCommandGroup($definition->id, $definition->name, $commands);
    }

    private static function instantiate(string $moduleId, string $key, string $class): Command
    {
        if (!class_exists($class) || !is_subclass_of($class, Command::class)) {
            throw new \LogicException(
                "Module '{$moduleId}': {$key} class '{$class}' does not exist or is not a console command",
            );
        }
        return new $class();
    }
}
