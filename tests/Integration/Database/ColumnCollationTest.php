<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Database;

use Shipard\Core\Database\ColumnDefinition;
use Shipard\Core\Database\SqlGenerator;
use Shipard\Core\Database\TableDefinition;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Collation sloupce z definice tabulky proti skutečné databázi: DDL, které
 * `SqlGenerator` složí, musí MariaDB přijmout a sloupec musí collation mít.
 * Generátor dřív z pole `collation` skládal `CHARACTER SET <collation>`,
 * tedy neplatné SQL — unit test nad textem příkazu to neodhalil.
 *
 * Tabulka je dočasná (`CREATE TEMPORARY TABLE`): žije jen ve spojení testu
 * a ve zdroji dat po ní nic nezůstane.
 */
class ColumnCollationTest extends IntegrationTestCase
{
    private const TABLE = 'it_column_collation';

    protected function onTearDown(): void
    {
        $this->db->execute('DROP TEMPORARY TABLE IF EXISTS [' . self::TABLE . ']');
    }

    public function testColumnWithCollationIsCreatedAndComparesByIt(): void
    {
        $definition = TableDefinition::fromArray([
            'tableId' => 1,
            'name' => 'Test',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
                ['id' => 'plain', 'name' => 'Plain', 'type' => 'varchar', 'length' => 60, 'nullable' => true],
                ['id' => 'exact', 'name' => 'Exact', 'type' => 'varchar', 'length' => 60, 'nullable' => true, 'collation' => 'utf8mb4_bin'],
            ],
        ]);

        $create = SqlGenerator::generateCreateTable(self::TABLE, $definition);
        $this->assertStringStartsWith('CREATE TABLE ', $create);
        $this->db->execute('%sql', 'CREATE TEMPORARY TABLE ' . substr($create, strlen('CREATE TABLE ')));

        $collations = [];
        foreach ($this->db->fetchAll('SHOW FULL COLUMNS FROM [' . self::TABLE . ']') as $column) {
            $collations[(string) $column['Field']] = $column['Collation'];
        }
        $this->assertSame('utf8mb4_bin', $collations['exact']);
        $this->assertSame('utf8mb4_czech_ci', $collations['plain'], 'sloupec bez přetížení má collation tabulky');

        // Přidání sloupce a MODIFY (delší varchar) collation nesou také.
        $added = [
            ColumnDefinition::fromArray(['id' => 'added', 'name' => 'Added', 'type' => 'varchar', 'length' => 20, 'nullable' => true, 'collation' => 'utf8mb4_bin']),
            ColumnDefinition::fromArray(['id' => 'exact', 'name' => 'Exact', 'type' => 'varchar', 'length' => 80, 'nullable' => true, 'collation' => 'utf8mb4_bin']),
        ];
        $this->db->execute('%sql', SqlGenerator::generateAddColumn(self::TABLE, $added[0]));
        $this->db->execute('%sql', SqlGenerator::generateModifyColumn(self::TABLE, $added[1]));
        $after = [];
        foreach ($this->db->fetchAll('SHOW FULL COLUMNS FROM [' . self::TABLE . ']') as $column) {
            $after[(string) $column['Field']] = [$column['Type'], $column['Collation']];
        }
        $this->assertSame(['varchar(20)', 'utf8mb4_bin'], $after['added']);
        $this->assertSame(['varchar(80)', 'utf8mb4_bin'], $after['exact']);

        // Binární collation rozlišuje velikost písmen, collation tabulky ne.
        $this->db->execute('INSERT INTO [' . self::TABLE . '] ([plain], [exact]) VALUES (%s, %s)', 'INV-1', 'INV-1');
        $count = fn (string $column): int => (int) ($this->db->fetchRow(
            'SELECT COUNT(*) AS [n] FROM [' . self::TABLE . "] WHERE [{$column}] = %s",
            'inv-1',
        )['n'] ?? 0);
        $this->assertSame(1, $count('plain'));
        $this->assertSame(0, $count('exact'));
    }
}
