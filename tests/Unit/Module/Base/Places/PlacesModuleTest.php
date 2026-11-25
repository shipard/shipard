<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Places;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Utils\JsoncParser;

/**
 * Deklarace modulu base.places nad skutečnými soubory (#169 D1, D6, D14):
 * tabulky s unikátními tableId, modul v install.base, ikony typů i druhů
 * v registru ikon, kód místa bez unikátního indexu a bez přirozeného klíče.
 */
class PlacesModuleTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../../..';
    private const MODULES = self::ROOT . '/modules';
    private const MODULE = self::MODULES . '/base/places';

    /** @return array<string, mixed> */
    private static function module(): array
    {
        return JsoncParser::parseFile(self::MODULE . '/module.jsonc');
    }

    /** @return array<string, mixed> */
    private static function table(string $table): array
    {
        return JsoncParser::parseFile(self::MODULE . '/tables/' . $table . '.jsonc');
    }

    public function testModuleDefinitionParsesAndDeclaresTables(): void
    {
        $module = ModuleDefinition::fromArray(self::module());

        $this->assertSame('base.places', $module->id);
        foreach (['core.system', 'core.attachments', 'base.persons', 'world.validations'] as $dependency) {
            $this->assertContains($dependency, $module->dependencies, $dependency);
        }

        $ids = [];
        foreach ($module->tables as $table) {
            $ids[$table] = TableDefinition::fromArray(self::table($table))->tableId;
        }
        $this->assertSame(['base_places_kinds' => 466, 'base_places_places' => 467], $ids);

        $install = JsoncParser::parseFile(self::MODULES . '/install/base/module.jsonc');
        $this->assertContains('base.places', $install['dependencies']);
    }

    public function testTableIdsAreUniqueAcrossAllModules(): void
    {
        $seen = [];
        foreach (glob(self::MODULES . '/*/*/tables/*.jsonc') as $file) {
            $id = (int) (JsoncParser::parseFile($file)['tableId'] ?? 0);
            if ($id === 0) {
                continue;
            }
            $this->assertArrayNotHasKey($id, $seen, basename($file) . ' × ' . ($seen[$id] ?? ''));
            $seen[$id] = basename($file);
        }
    }

    public function testPlaceCodeIsNotUniqueAndTableHasNoExchangeKey(): void
    {
        $places = self::table('base_places_places');

        // D6: žádný přirozený klíč, žádná denormalizovaná cesta.
        $this->assertArrayNotHasKey('exchangeKey', $places);
        $columns = array_column($places['columns'], 'id');
        foreach (['path', 'level', 'full_name'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, $forbidden);
        }

        // Kód je jedinečný jen mezi sourozenci — unikátní index by to rozbil.
        foreach ($places['indexes'] as $index) {
            if (in_array('code', array_column($index['columns'], 'column'), true)) {
                $this->assertNotSame('unique', $index['type'], $index['id']);
            }
        }

        $kinds = self::table('base_places_kinds');
        $this->assertArrayNotHasKey('exchangeKey', $kinds);
        $unique = array_filter($kinds['indexes'], static fn (array $i): bool => $i['type'] === 'unique');
        $this->assertSame([['column' => 'system_code']], array_values($unique)[0]['columns']);
    }

    public function testStateTransitionsRunThroughTheDocuments(): void
    {
        // Bez příznaku jde změna stavu z formuláře přímým UPDATE mimo
        // dokument — kontroly koše a archivu (D7, D18) by se nespustily.
        foreach (['base_places_places', 'base_places_kinds'] as $table) {
            $this->assertTrue(
                TableDefinition::fromArray(self::table($table))->stateTransitionsRunDocumentHooks,
                $table,
            );
        }
    }

    public function testAddressColumnsMatchPersonAddresses(): void
    {
        $shape = static function (array $table): array {
            $out = [];
            foreach ($table['columns'] as $column) {
                $out[$column['id']] = array_intersect_key(
                    $column,
                    array_flip(['type', 'length', 'precision', 'scale', 'cfgItem', 'nullable']),
                );
            }
            return $out;
        };
        $places = $shape(self::table('base_places_places'));
        $persons = $shape(JsoncParser::parseFile(self::MODULES . '/base/persons/tables/base_persons_addresses.jsonc'));

        foreach (['street', 'house_number', 'orientation_number', 'city', 'zip', 'country', 'latitude', 'longitude'] as $column) {
            $this->assertSame($persons[$column], $places[$column], $column);
        }
    }

    public function testTypeAndKindIconsAreRegisteredInTheFrontend(): void
    {
        $iconsJs = (string) file_get_contents(self::ROOT . '/frontend/src/icons.js');
        $types = JsoncParser::parseFile(self::MODULE . '/config/types.jsonc');
        $icons = JsoncParser::parseFile(self::MODULE . '/config/icons.jsonc');

        $this->assertCount(15, $icons);
        foreach ($types as $key => $type) {
            $this->assertArrayHasKey($type['icon'], $icons, "ikona typu {$key} není v sadě ikon míst");
        }
        foreach (array_keys($icons) as $icon) {
            $this->assertStringContainsString("'" . $icon . "':", $iconsJs, $icon);
        }
    }
}
