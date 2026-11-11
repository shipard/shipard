<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Viewer;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Viewer\TableViewer;

/**
 * Popisek sloupce z definice tabulky v `TableViewer::columnLabel()`
 * (#133 D66): `formLabel ?? name` jako formulář, fallback bez definice
 * tabulky i sloupce, cizí tabulka přes `$table`.
 */
class TableViewerColumnLabelTest extends TestCase
{
    private function viewer(bool $withTables = true): TableViewer
    {
        $viewer = new class($this->createMock(DataSourceConnection::class), 'test_table') extends TableViewer {
            public function selectRows(?string $search, array $filters, int $pageNumber): array
            {
                return [];
            }

            public function renderRow(array $rowData): array
            {
                return [];
            }

            public function label(string $column, string $fallback, ?string $table = null): string
            {
                return $this->columnLabel($column, $fallback, $table);
            }
        };

        if ($withTables) {
            $viewer->setTables([
                'test_table' => TableDefinition::fromArray([
                    'name'    => 'test_table',
                    'tableId' => 999,
                    'columns' => [
                        ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true],
                        ['id' => 'name', 'name' => 'Název', 'formLabel' => 'Titulek', 'type' => 'varchar', 'length' => 100],
                        ['id' => 'note', 'name' => 'Poznámka', 'type' => 'text', 'nullable' => true],
                    ],
                ]),
                'test_kinds' => TableDefinition::fromArray([
                    'name'    => 'test_kinds',
                    'tableId' => 998,
                    'columns' => [
                        ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true],
                        ['id' => 'name', 'name' => 'Druh', 'type' => 'varchar', 'length' => 100],
                    ],
                ]),
            ]);
        }
        return $viewer;
    }

    public function testFormLabelWinsOverName(): void
    {
        $this->assertSame('Titulek', $this->viewer()->label('name', 'Name'));
    }

    public function testNameWithoutFormLabel(): void
    {
        $this->assertSame('Poznámka', $this->viewer()->label('note', 'Note'));
    }

    public function testMissingColumnGivesFallback(): void
    {
        $this->assertSame('Valid from', $this->viewer()->label('valid_from', 'Valid from'));
    }

    public function testMissingTableDefinitionsGiveFallback(): void
    {
        $this->assertSame('Name', $this->viewer(false)->label('name', 'Name'));
    }

    public function testForeignTable(): void
    {
        $this->assertSame('Druh', $this->viewer()->label('name', 'Kind', 'test_kinds'));
    }

    public function testMissingForeignTableGivesFallback(): void
    {
        $this->assertSame('Kind', $this->viewer()->label('name', 'Kind', 'test_missing'));
    }
}
