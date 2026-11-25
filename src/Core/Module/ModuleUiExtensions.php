<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

use Shipard\Core\Form\ModuleFormTab;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Viewer\DetailExtension;
use Shipard\Core\Viewer\RowDecorator;

/**
 * Rozšíření UI cizích tabulek z modulů zdroje dat (#172 D16–D19): taby
 * a štítky detailu (`detailExtensions`), záložky formuláře (`formTabs`)
 * a štítky řádku seznamu (`rowDecorators`).
 *
 * Staví se z **resolvovaných** modulů — tedy jen z modulů aktivních ve
 * zdroji dat (stejný `ModuleResolver::resolve` jako viewery a formuláře).
 * Pořadí: `order` vzestupně, pak id modulu, pak pořadí deklarace.
 *
 * Izolace (D19) je na jednom místě — {@see self::call()}: chybějící třída,
 * třída bez správného rozhraní, výjimka poskytovatele i výsledek mimo
 * kontrakt (výjimka z předané funkce) se zaloguje a poskytovatel se
 * přeskočí; detail / formulář / seznam se vykreslí bez něj.
 */
final class ModuleUiExtensions
{
    public const string DETAIL = 'detailExtensions';
    public const string FORM_TABS = 'formTabs';
    public const string ROW_DECORATORS = 'rowDecorators';

    /** Druh (= pole `module.jsonc`) → rozhraní, které poskytovatel implementuje. */
    public const array KINDS = [
        self::DETAIL => DetailExtension::class,
        self::FORM_TABS => ModuleFormTab::class,
        self::ROW_DECORATORS => RowDecorator::class,
    ];

    /** @var array<string, array<string, list<ModuleUiExtension>>> druh → tabulka → seřazené registrace */
    private array $byKind = [];

    /** @var array<string, object> "druh|třída" → instance poskytovatele (jedna na požadavek) */
    private array $instances = [];

    /** @param array<string, array<string, list<ModuleUiExtension>>> $byKind */
    private function __construct(array $byKind)
    {
        $this->byKind = $byKind;
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param iterable<ModuleDefinition> $modules resolvované moduly zdroje dat
     */
    public static function fromModules(iterable $modules): self
    {
        /** @var array<string, array<string, list<array{0: int, 1: string, 2: int, 3: ModuleUiExtension}>>> $collected */
        $collected = [];
        foreach ($modules as $module) {
            foreach (array_keys(self::KINDS) as $kind) {
                foreach ($module->{$kind} as $idx => $reg) {
                    $collected[$kind][$reg['table']][] = [
                        $reg['order'],
                        $module->id,
                        $idx,
                        new ModuleUiExtension($kind, $module->id, $reg['table'], $reg['class'], $reg['order']),
                    ];
                }
            }
        }

        $byKind = [];
        foreach ($collected as $kind => $tables) {
            foreach ($tables as $table => $entries) {
                usort($entries, static fn (array $a, array $b): int
                    => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
                $byKind[$kind][$table] = array_map(static fn (array $e): ModuleUiExtension => $e[3], $entries);
            }
        }

        return new self($byKind);
    }

    /**
     * Registrace druhu pro tabulku v pořadí `order` → id modulu → deklarace.
     *
     * @return list<ModuleUiExtension>
     */
    public function for(string $kind, string $table): array
    {
        return $this->byKind[$kind][$table] ?? [];
    }

    /**
     * Zavolá `$call` s instancí poskytovatele a vrátí jeho výsledek; při
     * jakémkoli selhání (třída, rozhraní, výjimka poskytovatele, výjimka
     * z `$call` při kontrole výsledku) zaloguje a vrátí `null`.
     *
     * @template T
     * @param callable(object): T $call
     * @return T|null
     */
    public function call(ModuleUiExtension $extension, callable $call): mixed
    {
        try {
            return $call($this->instance($extension));
        } catch (\Throwable $e) {
            ErrorLogger::error('Module UI extension failed', [
                'module' => $extension->moduleId,
                'class' => $extension->class,
                'table' => $extension->table,
                'kind' => $extension->kind,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'at' => basename($e->getFile()) . ':' . $e->getLine(),
            ]);
            return null;
        }
    }

    private function instance(ModuleUiExtension $extension): object
    {
        $key = $extension->kind . '|' . $extension->class;
        if (isset($this->instances[$key])) {
            return $this->instances[$key];
        }

        $class = $extension->class;
        if (!class_exists($class)) {
            throw new \LogicException("Class '{$class}' does not exist");
        }
        $interface = self::KINDS[$extension->kind];
        if (!is_subclass_of($class, $interface)) {
            throw new \LogicException("Class '{$class}' does not implement {$interface}");
        }

        return $this->instances[$key] = new $class();
    }
}
