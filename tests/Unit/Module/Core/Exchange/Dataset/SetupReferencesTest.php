<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\SetupReferences;

/**
 * FK sady přes přirozený klíč (#131 fáze 2b, D29, D37): co je přenosné,
 * jak se hodnota vypisuje a jak přísně se hledá zpět.
 */
class SetupReferencesTest extends TestCase
{
    private function tableDef(string $relPath): TableDefinition
    {
        $path = dirname(__DIR__, 6) . '/modules/' . $relPath;
        return TableDefinition::fromArray(JsoncParser::parseFile($path));
    }

    /** @return array<string, TableDefinition> */
    private function tables(): array
    {
        return [
            'economy_work_orders_kinds'       => $this->tableDef('economy/workOrders/tables/economy_work_orders_kinds.jsonc'),
            'economy_codebooks_bank_accounts' => $this->tableDef('economy/codebooks/tables/economy_codebooks_bank_accounts.jsonc'),
            'docs_core_number_series'         => $this->tableDef('docs/core/tables/docs_core_number_series.jsonc'),
            'economy_assets_accounting_groups' => $this->tableDef('economy/assets/tables/economy_assets_accounting_groups.jsonc'),
            'economy_assets_types'             => $this->tableDef('economy/assets/tables/economy_assets_types.jsonc'),
        ];
    }

    public function testPortabilityFollowsSetupTablesAndSpecialCases(): void
    {
        $this->assertTrue(SetupReferences::isPortable('economy_accounting_accounts'), 'účet osnovy číslem');
        $this->assertSame('number', SetupReferences::keyColumn('economy_accounting_accounts'));
        $this->assertTrue(SetupReferences::isPortable('docs_core_number_series'), 'řada dokladů {docType, code} (D37)');
        $this->assertNull(SetupReferences::keyColumn('docs_core_number_series'), 'složený klíč nemá jeden sloupec');
        $this->assertSame('name', SetupReferences::keyColumn('economy_work_orders_kinds'));
        $this->assertSame('code', SetupReferences::keyColumn('economy_codebooks_bank_accounts'));
        $this->assertSame('code', SetupReferences::keyColumn('economy_codebooks_cost_centers'));
        $this->assertSame('number_code', SetupReferences::keyColumn('economy_work_orders_number_series'));
        // #131 fáze 2c (D39): číselníky majetku — sekce assets/ odkazuje typ názvem a skupinu kódem.
        $this->assertSame('code', SetupReferences::keyColumn('economy_assets_accounting_groups'));
        $this->assertSame('name', SetupReferences::keyColumn('economy_assets_type_groups'));
        $this->assertSame('name', SetupReferences::keyColumn('economy_assets_types'));
        $this->assertFalse(SetupReferences::isPortable('economy_codebooks_vat_registrations'), 'dvousloupcový klíč');
        $this->assertFalse(SetupReferences::isPortable('base_persons_persons'), 'osoby sada FK nenese');
        $this->assertFalse(SetupReferences::isPortable('core_system_settings'), 'pseudo-tabulka settings');
    }

    public function testExportGivesNaturalKeyOrDocSeriesObject(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(static function (string $sql, int $id): ?Row {
            if (str_contains($sql, '[economy_work_orders_kinds]')) {
                return $id === 3 ? new Row(['name' => 'Správa IT — paušál']) : null;
            }
            if (str_contains($sql, '[economy_codebooks_bank_accounts]')) {
                return $id === 4 ? new Row(['code' => 'BU1']) : null;
            }
            if (str_contains($sql, '[docs_core_number_series]')) {
                return match ($id) {
                    7 => new Row(['doc_type' => 'invno', 'doc_number_code' => null]),
                    8 => new Row(['doc_type' => 'cash', 'doc_number_code' => 'POK']),
                    default => null,
                };
            }
            if (str_contains($sql, '[economy_assets_accounting_groups]')) {
                return $id === 2 ? new Row(['code' => '022']) : null;
            }
            return null;
        });
        $refs = new SetupReferences($db, $this->tables());

        $this->assertSame('Správa IT — paušál', $refs->export('economy_work_orders_kinds', 3));
        $this->assertSame('Správa IT — paušál', $refs->export('economy_work_orders_kinds', '3'), 'cache a int normalizace');
        $this->assertSame('BU1', $refs->export('economy_codebooks_bank_accounts', 4));
        $this->assertSame(['docType' => 'invno'], $refs->export('docs_core_number_series', 7), 'prázdný kód se vynechá');
        $this->assertSame(['docType' => 'cash', 'code' => 'POK'], $refs->export('docs_core_number_series', 8));
        $this->assertSame('022', $refs->export('economy_assets_accounting_groups', 2), 'účetní skupina majetku kódem (D39)');
        $this->assertNull($refs->export('economy_work_orders_kinds', 99), 'neexistující řádek');
        $this->assertNull($refs->export('economy_work_orders_kinds', null));
        $this->assertNull($refs->export('base_persons_persons', 5), 'nepřenosná tabulka');
    }

    public function testResolveFindsExactlyOneActiveRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturnCallback(function (string $sql, mixed ...$args): array {
            if (str_contains($sql, '[economy_work_orders_kinds]')) {
                $this->assertStringContainsString('WHERE [name] = %s AND [docState] <> 90 ORDER BY [id]', $sql);
                return match ($args[0]) {
                    'Správa IT — paušál' => [new Row(['id' => 3])],
                    'Projekty'           => [new Row(['id' => 5]), new Row(['id' => 6])],
                    default              => [],
                };
            }
            if (str_contains($sql, '[economy_assets_types]')) {
                $this->assertStringContainsString('WHERE [name] = %s AND [docState] <> 90 ORDER BY [id]', $sql);
                return $args[0] === 'Výpočetní technika' ? [new Row(['id' => 5])] : [];
            }
            if (str_contains($sql, '[docs_core_number_series]')) {
                $this->assertStringContainsString('[docState] <> 90', $sql);
                if (str_contains($sql, '[doc_number_code] = %s')) {
                    return $args === ['cash', 'POK'] ? [new Row(['id' => 8])] : [];
                }
                $this->assertStringContainsString("([doc_number_code] IS NULL OR [doc_number_code] = '')", $sql);
                return match ($args[0]) {
                    'invno' => [new Row(['id' => 7])],
                    'invni' => [new Row(['id' => 9]), new Row(['id' => 10])],
                    default => [],
                };
            }
            $this->fail("unexpected query: {$sql}");
        });
        $refs = new SetupReferences($db, $this->tables());

        $this->assertSame(3, $refs->resolve('economy_work_orders_kinds', 'Správa IT — paušál'));
        $this->assertSame(5, $refs->resolve('economy_assets_types', 'Výpočetní technika'), 'typ majetku názvem (D39)');
        $this->assertSame(7, $refs->resolve('docs_core_number_series', ['docType' => 'invno']));
        $this->assertSame(7, $refs->resolve('docs_core_number_series', ['docType' => 'invno', 'code' => null]));
        $this->assertSame(8, $refs->resolve('docs_core_number_series', ['docType' => 'cash', 'code' => 'POK']));

        foreach ([
            ['economy_work_orders_kinds', 'Neznámý', 'na tomto zdroji dat není'],
            ['economy_work_orders_kinds', 'Projekty', 'není jednoznačná'],
            ['economy_assets_types', 'Nábytek', "economy_assets_types name 'Nábytek': na tomto zdroji dat není"],
            ['docs_core_number_series', ['docType' => 'invpo'], "typu 'invpo' bez kódu: na tomto zdroji dat není"],
            ['docs_core_number_series', ['docType' => 'invni'], 'není jednoznačná (2 záznamů'],
            ['docs_core_number_series', ['docType' => 'cash', 'code' => 'XYZ'], "s kódem 'XYZ': na tomto zdroji dat není"],
            ['docs_core_number_series', 'invno', 'musí být objekt'],
            ['economy_work_orders_kinds', '', 'neprázdný řetězec'],
            ['base_persons_persons', 'x', 'nepřenáší'],
        ] as [$table, $value, $fragment]) {
            try {
                $refs->resolve($table, $value);
                $this->fail("{$table}: očekávána DatasetException");
            } catch (DatasetException $e) {
                $this->assertStringContainsString($fragment, $e->getMessage());
            }
        }
    }
}
