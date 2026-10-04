<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Database;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\ColumnDefinition;
use Shipard\Core\Database\ExtensionDefinition;
use Shipard\Core\Database\IndexDefinition;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Database\TableMerger;

class TableMergerTest extends TestCase
{
    private function baseTable(): TableDefinition
    {
        return TableDefinition::fromArray([
            'tableId' => 1,
            'name' => 'core_test_items',
            'columnGroups' => [
                ['id' => 'basic', 'name' => 'Basic'],
            ],
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true, 'autoIncrement' => true],
                ['id' => 'title', 'name' => 'Title', 'type' => 'varchar', 'length' => 255],
            ],
            'indexes' => [
                ['id' => 'idx_title', 'type' => 'index', 'columns' => [['column' => 'title']]],
            ],
        ]);
    }

    public function testAddColumnsAndIndexes(): void
    {
        $ext = ExtensionDefinition::fromArray([
            'table' => 'core_test_items',
            'columns' => [
                ['id' => 'note', 'name' => 'Note', 'type' => 'text'],
            ],
            'indexes' => [
                ['id' => 'idx_note', 'type' => 'fulltext', 'columns' => [['column' => 'note']]],
            ],
        ]);

        $merged = TableMerger::merge($this->baseTable(), $ext);

        $this->assertCount(3, $merged->columns);
        $this->assertCount(2, $merged->indexes);
        $this->assertSame('note', $merged->columns[2]->id);
        $this->assertSame('idx_note', $merged->indexes[1]->id);
    }

    public function testTableFlagsSurviveExtension(): void
    {
        $base = TableDefinition::fromArray([
            'tableId'       => 1,
            'name'          => 'core_test_items',
            'adminOnly'     => true,
            'systemManaged' => true,
            'columns'       => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true, 'autoIncrement' => true],
            ],
        ]);
        $ext = ExtensionDefinition::fromArray([
            'table'   => 'core_test_items',
            'columns' => [['id' => 'note', 'name' => 'Note', 'type' => 'text']],
        ]);

        $merged = TableMerger::merge($base, $ext);

        // Rozšíření cizím modulem nesmí tabulku odemknout.
        $this->assertTrue($merged->adminOnly);
        $this->assertTrue($merged->systemManaged);
    }

    public function testAddNewColumnGroup(): void
    {
        $ext = ExtensionDefinition::fromArray([
            'table' => 'core_test_items',
            'columnGroups' => [
                ['id' => 'extra', 'name' => 'Extra'],
            ],
        ]);

        $merged = TableMerger::merge($this->baseTable(), $ext);

        $this->assertCount(2, $merged->columnGroups);
        $this->assertSame('extra', $merged->columnGroups[1]['id']);
    }

    public function testDuplicateColumnGroupIgnored(): void
    {
        $ext = ExtensionDefinition::fromArray([
            'table' => 'core_test_items',
            'columnGroups' => [
                ['id' => 'basic', 'name' => 'Basic (duplicate)'],
            ],
        ]);

        $merged = TableMerger::merge($this->baseTable(), $ext);

        $this->assertCount(1, $merged->columnGroups);
        $this->assertSame('Basic', $merged->columnGroups[0]['name']);
    }

    public function testColumnIdCollisionThrows(): void
    {
        $ext = ExtensionDefinition::fromArray([
            'table' => 'core_test_items',
            'columns' => [
                ['id' => 'title', 'name' => 'Title Duplicate', 'type' => 'varchar', 'length' => 100],
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Extension column 'title' already exists in table 'core_test_items'");

        TableMerger::merge($this->baseTable(), $ext);
    }

    public function testIndexIdCollisionThrows(): void
    {
        $ext = ExtensionDefinition::fromArray([
            'table' => 'core_test_items',
            'indexes' => [
                ['id' => 'idx_title', 'type' => 'unique', 'columns' => [['column' => 'title']]],
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Extension index 'idx_title' already exists in table 'core_test_items'");

        TableMerger::merge($this->baseTable(), $ext);
    }

    public function testExtensionColumnsOnly(): void
    {
        $ext = ExtensionDefinition::fromArray([
            'table' => 'core_test_items',
            'columns' => [
                ['id' => 'extra_col', 'name' => 'Extra', 'type' => 'int'],
            ],
        ]);

        $merged = TableMerger::merge($this->baseTable(), $ext);

        $this->assertCount(3, $merged->columns);
        $this->assertCount(1, $merged->indexes);
    }

    public function testExtensionIndexesOnly(): void
    {
        $ext = ExtensionDefinition::fromArray([
            'table' => 'core_test_items',
            'indexes' => [
                ['id' => 'idx_extra', 'type' => 'unique', 'columns' => [['column' => 'title']]],
            ],
        ]);

        $merged = TableMerger::merge($this->baseTable(), $ext);

        $this->assertCount(2, $merged->columns);
        $this->assertCount(2, $merged->indexes);
    }
}
