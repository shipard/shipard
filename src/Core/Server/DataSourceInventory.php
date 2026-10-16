<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

use Shipard\Core\Config\DataClass;
use Shipard\Core\Config\DataSourceState;

/**
 * Sdílený čtenář seznamu zdrojů dat na stroji (#126 D10): adresáře
 * `data-sources/*` s `config/main.json`, z každého allowlist polí
 * ({@see DataSourceInfo}) + stav ze `state.json`. Jediné místo, které
 * `main.json` parsuje mimo {@see \Shipard\Core\Config\DataSourceConfig}
 * a {@see MainConfigFile} — používá ho `shpd-server ds-list`, vývojářský
 * dashboard, `DataSourceCronSwitch`, `DomainListCommand` i `hosting-sync`.
 *
 * Čte **shovívavě**: nečitelný nebo nevalidní `main.json` dá záznam
 * s `readable = false` a názvem `?`, nikdy nevyhodí (0600 soubor pod jiným
 * uživatelem, rozbitý JSON). Do databáze se nepřipojuje. `state.json`
 * přes `DataSourceState::load` — fail-closed soubor se hlásí jako
 * `corrupted` (a jako vždy zapíše varování do logu).
 */
final class DataSourceInventory
{
    public function __construct(
        private readonly string $dataSourcesDir,
    ) {}

    /** @return list<DataSourceInfo> seřazené podle ID (název adresáře); jen adresáře s config/main.json */
    public function scan(): array
    {
        if (!is_dir($this->dataSourcesDir)) {
            return [];
        }
        $dirs = glob($this->dataSourcesDir . '/*', GLOB_ONLYDIR) ?: [];
        sort($dirs);

        $list = [];
        foreach ($dirs as $dir) {
            $info = $this->read($dir);
            if ($info !== null) {
                $list[] = $info;
            }
        }
        return $list;
    }

    /** Jeden zdroj dat podle adresáře; null bez `config/main.json`. */
    public function read(string $dsDir): ?DataSourceInfo
    {
        $dsDir = rtrim($dsDir, '/');
        $file = $dsDir . '/config/main.json';
        if (!is_file($file)) {
            return null;
        }
        $id = basename($dsDir);

        $state = DataSourceState::load($dsDir);
        $stateLabel = $state->isCorrupted() ? 'corrupted' : $state->getEffectiveState();
        $cronEnabled = $state->isCronEnabled();

        $raw = @file_get_contents($file);
        $config = $raw === false ? null : json_decode($raw, true);
        if (!is_array($config)) {
            return new DataSourceInfo(
                id: $id,
                name: '?',
                created: null,
                country: null,
                defaultLanguage: null,
                modules: [],
                databaseName: null,
                dataClass: DataClass::RealCopy,
                dataClassExplicit: false,
                dataClassInvalid: false,
                purpose: null,
                origin: null,
                dataClassChanged: null,
                state: $stateLabel,
                cronEnabled: $cronEnabled,
                legacyServerAiFlag: false,
                readable: false,
            );
        }

        $classRaw = $config['dataClass'] ?? null;
        $modules = is_array($config['modules'] ?? null)
            ? array_values(array_map(strval(...), array_filter($config['modules'], is_scalar(...))))
            : [];
        $ai = $config['ai'] ?? null;

        return new DataSourceInfo(
            id: $id,
            name: self::text($config, 'name') ?? '?',
            created: self::text($config, 'created'),
            country: self::text($config, 'country'),
            defaultLanguage: self::text($config, 'defaultLanguage'),
            modules: $modules,
            databaseName: self::text($config, 'database_name'),
            dataClass: DataClass::fromConfig($classRaw),
            dataClassExplicit: DataClass::isValidConfigValue($classRaw),
            dataClassInvalid: $classRaw !== null && !DataClass::isValidConfigValue($classRaw),
            purpose: self::text($config, 'purpose'),
            origin: self::text($config, 'origin'),
            dataClassChanged: self::text($config, 'dataClassChanged'),
            state: $stateLabel,
            cronEnabled: $cronEnabled,
            legacyServerAiFlag: is_array($ai) && ($ai['useServerConnection'] ?? false) === true,
            readable: true,
        );
    }

    /** @param array<string, mixed> $config */
    private static function text(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
