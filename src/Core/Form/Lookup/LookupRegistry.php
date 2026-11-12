<?php

declare(strict_types=1);

namespace Shipard\Core\Form\Lookup;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Module\ModuleDefinition;

class LookupRegistry
{
    /** @var array<string, class-string<TableLookup>> table => class */
    private array $map = [];

    public function register(string $table, string $class): void
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException(sprintf(
                'TableLookup class %s does not exist',
                $class,
            ));
        }
        if (!is_subclass_of($class, TableLookup::class)) {
            throw new \InvalidArgumentException(sprintf(
                'Class %s is not a TableLookup',
                $class,
            ));
        }
        $this->map[$table] = $class;
    }

    /** Jazyk požadavku — předává se každému vytvořenému lookupu (`setLanguage`). */
    private ?string $language = null;

    public function setLanguage(string $language): void
    {
        $this->language = $language;
    }

    public function has(string $table): bool
    {
        return isset($this->map[$table]);
    }

    public function create(
        string $table,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        ?TableDefinition $tableDef,
    ): ?TableLookup {
        $class = $this->map[$table] ?? null;
        if ($class === null) {
            return null;
        }
        $instance = new $class();
        $instance->setDb($db);
        $instance->setConfig($config);
        if ($this->language !== null) {
            $instance->setLanguage($this->language);
        }
        if ($tableDef !== null) {
            $instance->setTableDef($tableDef);
        }
        return $instance;
    }

    /**
     * @param ModuleDefinition[] $modules
     */
    public function loadFromModules(array $modules): void
    {
        foreach ($modules as $module) {
            foreach ($module->lookups as $lookup) {
                if (!isset($lookup['table'], $lookup['class'])) {
                    continue;
                }
                $this->register($lookup['table'], $lookup['class']);
            }
        }
    }
}
