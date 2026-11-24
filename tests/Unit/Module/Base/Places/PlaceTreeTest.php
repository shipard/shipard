<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Places;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Base\Places\PlaceTree;

/**
 * Čtení stromu míst (#169 D6): cesta ze zkrácených názvů, podstrom, pořadí
 * dětí, jeden dotaz a pojistka proti cyklu v datech.
 */
class PlaceTreeTest extends TestCase
{
    /**
     * Areál(1, „Areál“) › Budova A(2, „A“) › 2. NP(3) › 210(6), 22(5), Zasedačka(4)
     *                   › Budova B(7, „B“)
     * Sklad(8) mimo areál.
     */
    private const ROWS = [
        ['id' => 1, 'parent' => null, 'name' => 'Areál firmy', 'short_name' => 'Areál', 'code' => null, 'order_pos' => 1, 'docState' => 40, 'kind' => 1, 'kind_name' => 'Areál', 'kind_type' => 'site', 'kind_icon' => null],
        ['id' => 7, 'parent' => 1, 'name' => 'Budova B', 'short_name' => 'B', 'code' => 'B', 'order_pos' => 2, 'docState' => 40, 'kind' => 2, 'kind_name' => 'Budova', 'kind_type' => 'building', 'kind_icon' => null],
        ['id' => 2, 'parent' => 1, 'name' => 'Budova A', 'short_name' => 'A', 'code' => 'A', 'order_pos' => 1, 'docState' => 40, 'kind' => 2, 'kind_name' => 'Budova', 'kind_type' => 'building', 'kind_icon' => null],
        ['id' => 3, 'parent' => 2, 'name' => '2. NP', 'short_name' => null, 'code' => '2', 'order_pos' => 1, 'docState' => 40, 'kind' => 4, 'kind_name' => 'Podlaží', 'kind_type' => 'floor', 'kind_icon' => null],
        ['id' => 4, 'parent' => 3, 'name' => 'Zasedačka', 'short_name' => '  ', 'code' => null, 'order_pos' => 5, 'docState' => 70, 'kind' => 9, 'kind_name' => 'Zasedací místnost', 'kind_type' => 'room', 'kind_icon' => 'place-meeting'],
        ['id' => 5, 'parent' => 3, 'name' => 'Kancelář', 'short_name' => null, 'code' => '22', 'order_pos' => 5, 'docState' => 40, 'kind' => 5, 'kind_name' => 'Místnost', 'kind_type' => 'room', 'kind_icon' => null],
        ['id' => 6, 'parent' => 3, 'name' => 'Kancelář', 'short_name' => null, 'code' => '210', 'order_pos' => 2, 'docState' => 40, 'kind' => 5, 'kind_name' => 'Místnost', 'kind_type' => 'room', 'kind_icon' => null],
        ['id' => 8, 'parent' => '0', 'name' => 'Sklad', 'short_name' => null, 'code' => null, 'order_pos' => 2, 'docState' => 40, 'kind' => 8, 'kind_name' => 'Ostatní', 'kind_type' => 'other', 'kind_icon' => null],
    ];

    /** @param list<array<string, mixed>> $rows */
    private function tree(array $rows = self::ROWS): PlaceTree
    {
        return new class($rows) extends PlaceTree {
            public int $loads = 0;

            /** @param list<array<string, mixed>> $rows */
            public function __construct(private readonly array $rows)
            {
                parent::__construct(null);
            }

            protected function loadAll(): array
            {
                $this->loads++;
                return $this->rows;
            }
        };
    }

    public function testPathUsesShortNamesAndExcludesThePlaceItself(): void
    {
        $tree = $this->tree();

        $this->assertSame(['Areál', 'A', '2. NP'], $tree->pathLabels(6));
        $this->assertSame('Areál › A › 2. NP', $tree->pathText(6));
        $this->assertSame([1, 2, 3], array_column($tree->ancestors(6), 'id'));
        $this->assertSame('Areál', $tree->pathText(2));
    }

    public function testTopLevelPlaceHasEmptyPath(): void
    {
        $tree = $this->tree();

        $this->assertSame('', $tree->pathText(1));
        $this->assertSame([], $tree->ancestors(8));
        $this->assertNull($tree->node(8)['parent']);
        $this->assertSame('', $tree->pathText(999));
    }

    public function testChildrenAreSortedByOrderThenCodeThenName(): void
    {
        $tree = $this->tree();

        // Pořadí 2 (210) před pořadím 5; mezi pořadím 5 prázdný kód
        // (Zasedačka) před kódem 22.
        $this->assertSame([6, 4, 5], array_column($tree->children(3), 'id'));
        $this->assertSame([2, 7], array_column($tree->children(1), 'id'));
        $this->assertSame([1, 8], array_column($tree->children(null), 'id'));
        $this->assertSame([], $tree->children(6));
    }

    public function testCodesSortNumericallyAndNamesInCzech(): void
    {
        $row = static fn (int $id, ?string $code, string $name): array => [
            'id' => $id, 'parent' => null, 'name' => $name, 'short_name' => null, 'code' => $code,
            'order_pos' => 0, 'docState' => 40, 'kind' => 5, 'kind_name' => 'Místnost', 'kind_type' => 'room', 'kind_icon' => null,
        ];

        $tree = $this->tree([$row(1, '10', 'x'), $row(2, '9', 'x'), $row(3, '101', 'x')]);
        $this->assertSame([2, 1, 3], array_column($tree->children(null), 'id'));

        $tree = $this->tree([$row(1, null, 'Dílna'), $row(2, null, 'Čekárna'), $row(3, null, 'Archiv')]);
        $this->assertSame([3, 2, 1], array_column($tree->children(null), 'id'));
    }

    public function testSubtreeContainsThePlaceAndAllDescendants(): void
    {
        $tree = $this->tree();

        $subtree = $tree->subtreeIds(2);
        sort($subtree);
        $this->assertSame([2, 3, 4, 5, 6], $subtree);
        $this->assertSame([6], $tree->subtreeIds(6));

        $all = $tree->subtreeIds(1);
        sort($all);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $all);
    }

    public function testTypeAndKindOfANode(): void
    {
        $tree = $this->tree();

        $this->assertSame('floor', $tree->typeOf(3));
        $this->assertNull($tree->typeOf(999));
        $this->assertSame('place-meeting', $tree->node(4)['kind_icon']);
        $this->assertSame('Zasedačka', PlaceTree::labelOf($tree->node(4)), 'prázdný zkrácený název = název');
    }

    public function testRowsAreLoadedOnce(): void
    {
        $tree = $this->tree();

        $tree->pathText(6);
        $tree->children(3);
        $tree->subtreeIds(1);
        $tree->typeOf(2);

        $this->assertSame(1, $tree->loads);
    }

    public function testCycleInDataDoesNotLoopForever(): void
    {
        $row = static fn (int $id, int $parent): array => [
            'id' => $id, 'parent' => $parent, 'name' => 'M' . $id, 'short_name' => null, 'code' => null,
            'order_pos' => 0, 'docState' => 40, 'kind' => 8, 'kind_name' => 'Ostatní', 'kind_type' => 'other', 'kind_icon' => null,
        ];
        // 1 → 2 → 3 → 1
        $tree = $this->tree([$row(1, 3), $row(2, 1), $row(3, 2)]);

        $this->assertSame([2, 3], array_column($tree->ancestors(1), 'id'));
        $subtree = $tree->subtreeIds(1);
        sort($subtree);
        $this->assertSame([1, 2, 3], $subtree);
    }

    public function testDepthIsCapped(): void
    {
        $rows = [];
        for ($id = 1; $id <= PlaceTree::MAX_DEPTH + 50; $id++) {
            $rows[] = [
                'id' => $id, 'parent' => $id > 1 ? $id - 1 : null, 'name' => 'M' . $id, 'short_name' => null,
                'code' => null, 'order_pos' => 0, 'docState' => 40, 'kind' => 8, 'kind_name' => 'Ostatní',
                'kind_type' => 'other', 'kind_icon' => null,
            ];
        }
        $tree = $this->tree($rows);

        $this->assertCount(PlaceTree::MAX_DEPTH, $tree->ancestors(PlaceTree::MAX_DEPTH + 50));
        $this->assertCount(PlaceTree::MAX_DEPTH + 1, $tree->subtreeIds(1));
    }

    public function testPlaceWhoseParentIsNotInTheTreeReadsAsTopLevel(): void
    {
        // Rodič v koši se nenačte — místo se pro čtení chová jako kořen.
        $rows = self::ROWS;
        unset($rows[2]); // Budova A (id 2)
        $tree = $this->tree(array_values($rows));

        $this->assertContains(3, array_column($tree->children(null), 'id'));
        $this->assertSame('', $tree->pathText(3));
    }

    public function testSingleQueryLoadsPlacesOutsideTrashWithTheirKind(): void
    {
        $captured = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->once())->method('fetchAll')->willReturnCallback(
            static function (string $sql, mixed ...$params) use (&$captured): array {
                $captured = [$sql, $params];
                return self::ROWS;
            },
        );

        $tree = new PlaceTree($db);
        $tree->pathText(6);
        $tree->children(1);

        $this->assertStringContainsString('LEFT JOIN `base_places_kinds`', $captured[0]);
        $this->assertStringContainsString('p.`docState` <> %i', $captured[0]);
        $this->assertSame([90], $captured[1]);
    }
}
