<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Viewer;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Viewer\TableViewer;

/**
 * Defaulty grid metod na TableVieweru (docs/viewer-grid.md §3.1):
 * existující list-only viewery musí fungovat beze změny — grid
 * nepodporují, default layout je list, footer žádný.
 */
class TableViewerGridDefaultsTest extends TestCase
{
    private function makeListOnlyViewer(): TableViewer
    {
        $ref = new \ReflectionClass(DataSourceConnection::class);
        $db  = $ref->newInstanceWithoutConstructor();

        return new class ($db, 'test_table') extends TableViewer {
            public function selectRows(?string $search, array $filters, int $pageNumber): array
            {
                return [];
            }

            public function renderRow(array $rowData): array
            {
                return ['id' => (int) $rowData['id'], 't1' => 'x'];
            }
        };
    }

    public function testListOnlyViewerHasNoGridColumns(): void
    {
        $this->assertNull($this->makeListOnlyViewer()->getGridColumns());
    }

    public function testDefaultLayoutIsList(): void
    {
        $this->assertSame('list', $this->makeListOnlyViewer()->getDefaultLayout());
    }

    public function testGridOptionsDefaultToEmpty(): void
    {
        $this->assertSame([], $this->makeListOnlyViewer()->getGridOptions());
    }

    public function testRenderGridRowDefaultsToEmpty(): void
    {
        $this->assertSame([], $this->makeListOnlyViewer()->renderGridRow(['id' => 1]));
    }

    public function testGridFooterDefaultsToNull(): void
    {
        $this->assertNull($this->makeListOnlyViewer()->renderGridFooter(null, []));
    }

    // Stromový layout (docs/viewer-tree.md) — stejné pravidlo: viewer bez
    // stromu nic neimplementuje.

    public function testViewerWithoutTreeHasNoTreeOptions(): void
    {
        $this->assertNull($this->makeListOnlyViewer()->getTreeOptions());
    }

    public function testTreeMethodsDefaultToEmpty(): void
    {
        $viewer = $this->makeListOnlyViewer();

        $this->assertSame([], $viewer->selectTreeRows(null, []));
        $this->assertSame([], $viewer->selectTreeRowsByIds([1, 2]));
        $this->assertSame([], $viewer->renderTreeRow(['id' => 1]));
    }

    public function testTreeSiblingsAreComparedByIdByDefault(): void
    {
        $viewer = $this->makeListOnlyViewer();

        $this->assertLessThan(0, $viewer->compareTreeRows(['id' => 2], ['id' => 10]));
        $this->assertSame(0, $viewer->compareTreeRows(['id' => 5], ['id' => 5]));
    }
}
