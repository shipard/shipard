<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Core\Database;

use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Utils\JsoncParser;

/**
 * Skutečné definice tabulek z `modules/` pro unit testy — bez extensions
 * a bez lokalizace. Test, který hledá záznam přirozeným klíčem
 * (`exchangeKey`, #166), tak čte týž klíč jako produkce.
 */
final class ModuleTables
{
    /** @var array<string, TableDefinition> */
    private static array $cache = [];

    /**
     * @return array<string, TableDefinition> název tabulky → definice
     */
    public static function load(string ...$tables): array
    {
        $out = [];
        foreach ($tables as $table) {
            $out[$table] = self::$cache[$table] ??= self::read($table);
        }
        return $out;
    }

    private static function read(string $table): TableDefinition
    {
        $paths = glob(dirname(__DIR__, 4) . "/modules/*/*/tables/{$table}.jsonc") ?: [];
        if (count($paths) !== 1) {
            throw new \RuntimeException("Table definition '{$table}' not found in modules/");
        }
        return TableDefinition::fromArray(JsoncParser::parseFile($paths[0]));
    }
}
