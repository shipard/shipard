<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Database;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\ColumnDefinition;
use Shipard\Core\Database\IndexDefinition;
use Shipard\Core\Database\TableDefinition;

class TableDefinitionTest extends TestCase
{
    private function coreSystemUsersData(): array
    {
        return [
            'tableId' => 1,
            'name' => 'Users',
            'displayPattern' => '{full_name} ({login})',
            'name:cs' => 'Uživatelé',
            'name:en' => 'Users',
            'columnGroups' => [
                [
                    'id' => 'credentials',
                    'name' => 'Credentials',
                    'name:cs' => 'Přihlašovací údaje',
                    'name:en' => 'Credentials',
                ],
                [
                    'id' => 'personal',
                    'name' => 'Personal information',
                    'name:cs' => 'Osobní údaje',
                    'name:en' => 'Personal information',
                ],
            ],
            'columns' => [
                [
                    'id' => 'id',
                    'name' => 'ID',
                    'type' => 'int',
                    'autoIncrement' => true,
                    'primaryKey' => true,
                ],
                [
                    'id' => 'login',
                    'name' => 'Login',
                    'name:cs' => 'Přihlašovací jméno',
                    'name:en' => 'Login',
                    'type' => 'varchar',
                    'length' => 100,
                    'nullable' => false,
                    'group' => 'credentials',
                ],
                [
                    'id' => 'password_hash',
                    'name' => 'Password hash',
                    'name:cs' => 'Hash hesla',
                    'name:en' => 'Password hash',
                    'type' => 'varchar',
                    'length' => 255,
                    'nullable' => false,
                    'group' => 'credentials',
                ],
                [
                    'id' => 'full_name',
                    'name' => 'Full name',
                    'name:cs' => 'Celé jméno',
                    'name:en' => 'Full name',
                    'type' => 'varchar',
                    'length' => 200,
                    'nullable' => false,
                    'group' => 'personal',
                ],
                [
                    'id' => 'email',
                    'name' => 'E-mail',
                    'type' => 'varchar',
                    'length' => 200,
                    'nullable' => true,
                    'group' => 'personal',
                ],
                [
                    'id' => 'is_active',
                    'name' => 'Active',
                    'name:cs' => 'Aktivní',
                    'name:en' => 'Active',
                    'type' => 'boolean',
                    'default' => 1,
                ],
            ],
            'indexes' => [
                [
                    'id' => 'unq_login',
                    'type' => 'unique',
                    'columns' => [
                        ['column' => 'login'],
                    ],
                ],
                [
                    'id' => 'idx_email',
                    'type' => 'index',
                    'columns' => [
                        ['column' => 'email'],
                    ],
                ],
            ],
        ];
    }

    public function testCoreSystemUsersTable(): void
    {
        $table = TableDefinition::fromArray($this->coreSystemUsersData());

        $this->assertSame(1, $table->tableId);
        $this->assertSame('Users', $table->name);
        $this->assertSame('{full_name} ({login})', $table->displayPattern);
        $this->assertCount(6, $table->columns);
        $this->assertCount(2, $table->indexes);
        $this->assertCount(2, $table->columnGroups);

        $primaryCols = array_filter($table->columns, fn(ColumnDefinition $c) => $c->primaryKey);
        $this->assertCount(1, $primaryCols);

        $pk = array_values($primaryCols)[0];
        $this->assertSame('id', $pk->id);
        $this->assertTrue($pk->autoIncrement);
    }

    public function testDisplayPatternDefaultsToNull(): void
    {
        $data = $this->coreSystemUsersData();
        unset($data['displayPattern']);
        $table = TableDefinition::fromArray($data);
        $this->assertNull($table->displayPattern);
    }

    public function testMissingTableId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TableDefinition::fromArray([]);
    }

    public function testMissingName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TableDefinition::fromArray(['tableId' => 1]);
    }

    public function testMissingPrimaryKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TableDefinition::fromArray([
            'tableId' => 1,
            'name' => 'Test',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int'],
            ],
        ]);
    }

    public function testMultiplePrimaryKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TableDefinition::fromArray([
            'tableId' => 1,
            'name' => 'Test',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true],
                ['id' => 'id2', 'name' => 'ID2', 'type' => 'int', 'primaryKey' => true],
            ],
        ]);
    }

    public function testVarcharWithoutLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ColumnDefinition::fromArray(['id' => 'x', 'name' => 'X', 'type' => 'varchar']);
    }

    public function testNumericWithoutPrecision(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ColumnDefinition::fromArray(['id' => 'x', 'name' => 'X', 'type' => 'numeric']);
    }

    public function testNumericWithoutScale(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ColumnDefinition::fromArray(['id' => 'x', 'name' => 'X', 'type' => 'numeric', 'precision' => 12]);
    }

    public function testEnumIntWithoutCfgItem(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ColumnDefinition::fromArray(['id' => 'x', 'name' => 'X', 'type' => 'enumInt']);
    }

    public function testEnumStringWithoutLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ColumnDefinition::fromArray(['id' => 'x', 'name' => 'X', 'type' => 'enumString', 'cfgItem' => 'some.item']);
    }

    public function testIndexMultiColumnWithOrder(): void
    {
        $index = IndexDefinition::fromArray([
            'id' => 'idx_test',
            'type' => 'index',
            'columns' => [
                ['column' => 'issue_date', 'order' => 'ASC'],
                ['column' => 'doc_state', 'order' => 'DESC'],
            ],
        ]);

        $this->assertCount(2, $index->columns);
        $this->assertSame('issue_date', $index->columns[0]['column']);
        $this->assertSame('ASC', $index->columns[0]['order']);
        $this->assertSame('doc_state', $index->columns[1]['column']);
        $this->assertSame('DESC', $index->columns[1]['order']);
    }

    public function testInvalidIndexType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IndexDefinition::fromArray([
            'id' => 'idx_test',
            'type' => 'badtype',
            'columns' => [['column' => 'foo']],
        ]);
    }

    public function testEmptyIndexColumns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IndexDefinition::fromArray([
            'id' => 'idx_test',
            'type' => 'index',
            'columns' => [],
        ]);
    }

    public function testChildTablesPresent(): void
    {
        $data = $this->coreSystemUsersData();
        $data['childTables'] = [
            ['table' => 'economy_docs_rows', 'foreignKey' => 'head_id', 'dataKey' => 'rows'],
        ];

        $table = TableDefinition::fromArray($data);

        $this->assertCount(1, $table->childTables);
        $this->assertSame('economy_docs_rows', $table->childTables[0]['table']);
        $this->assertSame('head_id', $table->childTables[0]['foreignKey']);
        $this->assertSame('rows', $table->childTables[0]['dataKey']);
    }

    public function testWithoutChildTablesDefaultsToEmpty(): void
    {
        $table = TableDefinition::fromArray($this->coreSystemUsersData());

        $this->assertSame([], $table->childTables);
    }

    public function testDocStatesPresent(): void
    {
        $data = $this->coreSystemUsersData();
        $data['docStates'] = [
            'stateColumn' => 'docState',
            'mainColumn'  => 'docStateMain',
            'cfgItem'     => 'core.system.docStatesArchive',
        ];

        $table = TableDefinition::fromArray($data);

        $this->assertNotNull($table->docStates);
        $this->assertSame('docState', $table->docStates->stateColumn);
        $this->assertSame('docStateMain', $table->docStates->mainColumn);
        $this->assertSame('core.system.docStatesArchive', $table->docStates->cfgItem);
    }

    public function testDocStatesAbsentDefaultsToNull(): void
    {
        $table = TableDefinition::fromArray($this->coreSystemUsersData());

        $this->assertNull($table->docStates);
    }

    public function testSystemColumnFlagTrue(): void
    {
        $col = ColumnDefinition::fromArray([
            'id' => 'docState', 'name' => 'Doc State', 'type' => 'tinyint', 'system' => true,
        ]);

        $this->assertTrue($col->system);
    }

    public function testSystemColumnFlagDefaultsFalse(): void
    {
        $col = ColumnDefinition::fromArray([
            'id' => 'x', 'name' => 'X', 'type' => 'tinyint',
        ]);

        $this->assertFalse($col->system);
    }

    public function testSensitiveColumnFlagParsed(): void
    {
        $col = ColumnDefinition::fromArray([
            'id' => 'password_hash', 'name' => 'Hash', 'type' => 'varchar', 'length' => 255, 'sensitive' => true,
        ]);

        $this->assertTrue($col->sensitive);
    }

    public function testSensitiveColumnFlagDefaultsFalse(): void
    {
        $col = ColumnDefinition::fromArray([
            'id' => 'x', 'name' => 'X', 'type' => 'tinyint',
        ]);

        $this->assertFalse($col->sensitive);
    }

    public function testGetSensitiveColumns(): void
    {
        $table = TableDefinition::fromArray([
            'tableId' => 5,
            'name'    => 'secrets',
            'columns' => [
                ['id' => 'id',       'name' => 'ID',   'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
                ['id' => 'name',     'name' => 'Name', 'type' => 'varchar', 'length' => 100],
                ['id' => 'key_hash', 'name' => 'Hash', 'type' => 'varchar', 'length' => 64, 'sensitive' => true],
            ],
        ]);

        $this->assertSame(['key_hash'], $table->getSensitiveColumns());
    }

    public function testGetSensitiveColumnsEmptyWhenNoneFlagged(): void
    {
        $table = TableDefinition::fromArray($this->coreSystemUsersData());

        $this->assertSame([], $table->getSensitiveColumns());
    }

    public function testAdminOnlyFlagParsed(): void
    {
        $data = $this->coreSystemUsersData();
        $data['adminOnly'] = true;

        $this->assertTrue(TableDefinition::fromArray($data)->adminOnly);
    }

    public function testSystemManagedFlagParsedAndDefaultsFalse(): void
    {
        $data = $this->coreSystemUsersData();

        $this->assertFalse(TableDefinition::fromArray($data)->systemManaged);
        $this->assertTrue(TableDefinition::fromArray(['systemManaged' => true] + $data)->systemManaged);
    }

    public function testAdminOnlyFlagDefaultsFalse(): void
    {
        $table = TableDefinition::fromArray($this->coreSystemUsersData());

        $this->assertFalse($table->adminOnly);
    }

    public function testStructuredColumnsCollected(): void
    {
        $data = $this->coreSystemUsersData();
        $data['columns'][] = [
            'id' => 'filing_profile',
            'name' => 'Filing profile',
            'type' => 'json',
            'nullable' => true,
            'schema' => 'economy.vat.filingProfileCz',
        ];
        $data['columns'][] = [
            'id' => 'snapshot',
            'name' => 'Snapshot',
            'type' => 'json',
            'nullable' => true,
        ];

        $this->assertSame(
            ['filing_profile' => 'economy.vat.filingProfileCz'],
            TableDefinition::fromArray($data)->getStructuredColumns(),
        );
    }

    public function testStructuredColumnsEmptyWithoutSchema(): void
    {
        $this->assertSame([], TableDefinition::fromArray($this->coreSystemUsersData())->getStructuredColumns());
    }
}
