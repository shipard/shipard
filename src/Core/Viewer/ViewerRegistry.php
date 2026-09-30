<?php

declare(strict_types=1);

namespace Shipard\Core\Viewer;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModuleDefinition;

class ViewerRegistry
{
    /** @var ViewerDefinition[] indexed by viewer ID */
    private array $viewers = [];

    /** Předává se každému vytvořenému vieweru (`setDsConfig`). */
    private ?DataSourceConfig $dsConfig = null;

    public function setDsConfig(DataSourceConfig $dsConfig): void
    {
        $this->dsConfig = $dsConfig;
    }

    /**
     * Load viewer definitions from resolved modules.
     * Each module can have a "viewers" array in its definition.
     *
     * @param ModuleDefinition[] $modules Resolved modules
     * @param string $language For localized names
     */
    public function loadFromModules(array $modules, string $language): void
    {
        foreach ($modules as $module) {
            foreach ($module->viewers as $viewer) {
                $name = $viewer['name:' . $language]
                    ?? $viewer['name:en']
                    ?? $viewer['name']
                    ?? $viewer['id'];

                $def = new ViewerDefinition(
                    id: $viewer['id'],
                    name: $name,
                    table: $viewer['table'],
                    class: $viewer['class'] ?? null,
                    moduleId: $module->id,
                    icon: $viewer['icon'] ?? null,
                );

                $this->viewers[$def->id] = $def;
            }
        }
    }

    /**
     * Register a viewer definition directly.
     */
    public function register(ViewerDefinition $def): void
    {
        $this->viewers[$def->id] = $def;
    }

    /**
     * Get all registered viewer definitions.
     * @return ViewerDefinition[]
     */
    public function getAll(): array
    {
        return $this->viewers;
    }

    /**
     * Get a specific viewer definition by ID.
     */
    public function get(string $id): ?ViewerDefinition
    {
        return $this->viewers[$id] ?? null;
    }

    /**
     * Instantiate a TableViewer object for the given viewer ID.
     */
    public function createViewer(
        string $id,
        DataSourceConnection $db,
        ?ConfigRuntime $config = null,
        ?string $language = null,
    ): ?TableViewer {
        $def = $this->viewers[$id] ?? null;
        if ($def === null) {
            return null;
        }

        $class = $def->class;
        if ($class === null || !class_exists($class)) {
            return null;
        }

        $viewer = new $class($db, $def->table);
        if ($config !== null) {
            $viewer->setConfig($config);
        }
        if ($language !== null) {
            $viewer->setLanguage($language);
        }
        if ($this->dsConfig !== null) {
            $viewer->setDsConfig($this->dsConfig);
        }
        return $viewer;
    }
}
