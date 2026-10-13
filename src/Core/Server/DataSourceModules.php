<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;

/**
 * Aktivní moduly zdroje dat pro server-level nástroje, které procházejí
 * víc zdrojů dat bez přechodu do jejich adresáře (dispatcher periodických
 * úloh, #115 D15). Stejné zjišťování jako zbytek aplikace: přímé moduly
 * z `config/main.json` (`DataSourceConfig::getModules()`) rozvinuté
 * přes `ModuleResolver::resolve()` o tranzitivní závislosti.
 *
 * Definice modulů (`ModuleLoader::loadAllModules`) se načítají **jednou
 * na instanci** — volající drží jednu instanci na celý běh; selhání
 * načtení si instance pamatuje a neopakuje ho pro každý zdroj dat.
 *
 * Nikdy nehází. `null` = moduly nelze určit (nečitelný nebo neúplný
 * `main.json`, prázdný seznam modulů, definice nelze načíst, chyba
 * resolveru — neznámý modul nebo cyklus); důvod jde jako warning do logu
 * a volající se zachová fail-loud (dispatcher pustí všechny úlohy, chyba
 * se ukáže jako selhaná úloha místo tichého vynechání).
 *
 * `HostingSyncRunner::isModuleActiveForDs()` dělá totéž zjišťování
 * samostatně — čte surový JSON bez validace, chyby resolveru ignoruje
 * a loguje do výstupu `hosting-sync`; na tuto třídu se nepřevádí, aby se
 * mu chování v těchto okrajích nezměnilo.
 */
class DataSourceModules
{
    /** @var list<ModuleDefinition>|null */
    private ?array $definitions = null;

    private ?string $definitionsError = null;

    public function __construct(
        private readonly ?ModulePathResolver $pathResolver = null,
    ) {}

    /**
     * Aktivní moduly zdroje dat včetně tranzitivních závislostí.
     *
     * @return list<string>|null null = nelze určit (viz docblock třídy)
     */
    public function activeIds(string $dsDir): ?array
    {
        try {
            $direct = array_values(array_map(strval(...), (new DataSourceConfig($dsDir))->getModules()));
        } catch (\Throwable $e) {
            return $this->unknown($dsDir, 'main.json unusable: ' . $e->getMessage());
        }
        if ($direct === []) {
            return $this->unknown($dsDir, 'main.json lists no modules');
        }

        $definitions = $this->definitions();
        if ($definitions === null) {
            return $this->unknown($dsDir, 'module definitions unavailable: ' . (string) $this->definitionsError);
        }

        $errors = [];
        try {
            $resolved = ModuleResolver::resolve($definitions, $direct, $errors);
        } catch (\Throwable $e) {
            return $this->unknown($dsDir, 'module resolution failed: ' . $e->getMessage());
        }
        if ($errors !== []) {
            return $this->unknown($dsDir, 'module resolution: ' . implode('; ', $errors));
        }

        return array_values(array_map(static fn(ModuleDefinition $m): string => $m->id, $resolved));
    }

    /** Modul aktivní na zdroji dat; nelze-li moduly určit, false. */
    public function isActive(string $dsDir, string $moduleId): bool
    {
        $ids = $this->activeIds($dsDir);
        return $ids !== null && in_array($moduleId, $ids, true);
    }

    /**
     * Definice všech dostupných modulů — šev pro testy. Výchozí: cesty
     * k modulům ze server.json (`extraModulesPath`), bez čitelného
     * server.json jen vestavěný adresář `modules/` (vzor bin/shpd-server).
     *
     * @return list<ModuleDefinition>
     */
    protected function loadDefinitions(): array
    {
        $resolver = $this->pathResolver;
        if ($resolver === null) {
            $mainModulesPath = dirname(__DIR__, 3) . '/modules';
            try {
                $sc = new ServerConfig();
                $sc->load();
                $resolver = ModulePathResolver::fromServerConfig($sc, $mainModulesPath);
            } catch (\Throwable) {
                $resolver = new ModulePathResolver([$mainModulesPath]);
            }
        }
        return array_values(ModuleLoader::loadAllModules($resolver));
    }

    /** @return list<ModuleDefinition>|null null = načtení selhalo (jednou za instanci) */
    private function definitions(): ?array
    {
        if ($this->definitions !== null || $this->definitionsError !== null) {
            return $this->definitions;
        }
        try {
            $this->definitions = $this->loadDefinitions();
        } catch (\Throwable $e) {
            $this->definitionsError = $e->getMessage();
            ErrorLogger::warn('module definitions cannot be loaded', ['error' => $e->getMessage()]);
        }
        return $this->definitions;
    }

    private function unknown(string $dsDir, string $reason): null
    {
        ErrorLogger::warn('active modules unknown', ['ds' => basename($dsDir), 'reason' => $reason]);
        return null;
    }
}
