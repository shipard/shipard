<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\RoundTrip;

use Shipard\Core\Database\ExtensionDefinition;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Database\TableMerger;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Tests\Integration\Exchange\RoundTrip\RoundTripColumns;

/**
 * Definice tří tabulek dokladu z repozitáře bez zdroje dat: JSONC tabulky
 * z `docs.core` sloučené s rozšířeními **všech** modulů — nadmnožina toho,
 * co vidí integrační test nad konkrétním zdrojem.
 */
final class DocTableDefinitions
{
    /** @var array<string, TableDefinition>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, TableDefinition> tabulka → definice
     */
    public static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $modules = dirname(__DIR__, 6) . '/modules';

        $tables = [];
        foreach (RoundTripColumns::TABLES as $table) {
            $raw = JsoncParser::parseFile("{$modules}/docs/core/tables/{$table}.jsonc");
            $tables[$table] = TableDefinition::fromArray(ConfigLocalizer::localize($raw, 'en'));
        }
        foreach (glob("{$modules}/*/*/extensions/*.jsonc") ?: [] as $file) {
            $extension = ExtensionDefinition::fromArray(ConfigLocalizer::localize(JsoncParser::parseFile($file), 'en'));
            if (isset($tables[$extension->table])) {
                $tables[$extension->table] = TableMerger::merge($tables[$extension->table], $extension);
            }
        }
        return self::$cache = $tables;
    }
}
