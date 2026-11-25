<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Viewer;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Viewer\TreeRowsBuilder;

/**
 * Skládání stromu pro stromový layout vieweru (#169 D20, D21;
 * docs/viewer-tree.md): pořadí průchodu do hloubky, hloubka, počet dětí,
 * předci dotažení po vrstvách a odolnost proti poškozeným datům.
 */
class TreeRowsBuilderTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function row(int $id, ?int $parent, int $pos = 0): array
    {
        return ['id' => $id, 'parent' => $parent, 'pos' => $pos];
    }

    private static function byPos(array $a, array $b): int
    {
        return $a['pos'] <=> $b['pos'];
    }

    /**
     * @param list<array<string, mixed>> $selected
     * @param array<int, array<string, mixed>> $others řádky mimo výběr (předci)
     * @param list<list<int>> $requests zachycené dotazy na předky
     * @return list<array<string, mixed>>
     */
    private static function build(array $selected, array $others = [], ?array &$requests = null): array
    {
        $requests = [];
        return TreeRowsBuilder::build(
            $selected,
            static function (array $ids) use ($others, &$requests): array {
                sort($ids);
                $requests[] = $ids;
                return array_values(array_intersect_key($others, array_flip($ids)));
            },
            'parent',
            self::byPos(...),
        );
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return list<string> „id:rodič:hloubka:dětí:shoda“
     */
    private static function shape(array $nodes): array
    {
        return array_map(
            static fn (array $n): string => implode(':', [
                $n['row']['id'],
                $n['parent'] ?? '-',
                $n['depth'],
                $n['childCount'],
                $n['match'] ? 'm' : 'c',
            ]),
            $nodes,
        );
    }

    public function testEmptySelectionIsAnEmptyTree(): void
    {
        $this->assertSame([], self::build([], [], $requests));
        $this->assertSame([], $requests);
    }

    public function testRowsComeDepthFirstInComparatorOrder(): void
    {
        // Vstup záměrně přeházený — pořadí dělá komparátor, ne dotaz.
        $nodes = self::build([
            self::row(5, 2, 1),
            self::row(3, 1, 2),
            self::row(2, null, 2),
            self::row(4, 1, 1),
            self::row(1, null, 1),
            self::row(6, 4, 1),
        ]);

        $this->assertSame(
            ['1:-:0:2:m', '4:1:1:1:m', '6:4:2:0:m', '3:1:1:0:m', '2:-:0:1:m', '5:2:1:0:m'],
            self::shape($nodes),
        );
    }

    public function testSiblingsWithEqualOrderFallBackToId(): void
    {
        $nodes = self::build([self::row(9, null), self::row(3, null), self::row(7, null)]);

        $this->assertSame([3, 7, 9], array_map(static fn (array $n): int => $n['row']['id'], $nodes));
    }

    public function testAncestorsAreLoadedLayerByLayerAsContext(): void
    {
        // Hledání trefilo dvě místnosti; budova a podlaží jdou jako kontext.
        $others = [
            1  => self::row(1, null, 1),
            10 => self::row(10, 1, 1),
            20 => self::row(20, 1, 2),
        ];
        $nodes = self::build([self::row(101, 10, 1), self::row(201, 20, 1)], $others, $requests);

        $this->assertSame(
            ['1:-:0:2:c', '10:1:1:1:c', '101:10:2:0:m', '20:1:1:1:c', '201:20:2:0:m'],
            self::shape($nodes),
        );
        // První vrstva obě podlaží, druhá jen budova — a ta jen jednou.
        $this->assertSame([[10, 20], [1]], $requests);
    }

    public function testAncestorAlreadySelectedIsNotLoadedAgain(): void
    {
        $nodes = self::build([self::row(1, null), self::row(2, 1)], [], $requests);

        $this->assertSame(['1:-:0:1:m', '2:1:1:0:m'], self::shape($nodes));
        $this->assertSame([], $requests);
    }

    public function testMissingParentMakesTheNodeARoot(): void
    {
        $nodes = self::build([self::row(2, 99, 1), self::row(3, 2, 1)], [], $requests);

        $this->assertSame(['2:-:0:1:m', '3:2:1:0:m'], self::shape($nodes));
        // Neexistující rodič se hledá jednou, ne v každé vrstvě.
        $this->assertSame([[99]], $requests);
    }

    public function testZeroAndNullParentAreRoots(): void
    {
        $nodes = self::build([['id' => 1, 'parent' => 0, 'pos' => 1], ['id' => 2, 'parent' => null, 'pos' => 2]], [], $requests);

        $this->assertSame(['1:-:0:0:m', '2:-:0:0:m'], self::shape($nodes));
        $this->assertSame([], $requests);
    }

    public function testCycleInDataNeitherLoopsNorLosesNodes(): void
    {
        // 1 → 2 → 3 → 1 a k tomu zdravý kořen s dítětem.
        $nodes = self::build([
            self::row(1, 3, 1),
            self::row(2, 1, 1),
            self::row(3, 2, 1),
            self::row(8, null, 5),
            self::row(9, 8, 1),
        ]);

        $shape = self::shape($nodes);
        $this->assertCount(5, $shape);
        $this->assertSame(['8:-:0:1:m', '9:8:1:0:m'], array_slice($shape, 0, 2));
        // Cyklus se vydá jednou, od prvního uzlu podle pořadí, jako řetěz.
        $this->assertSame(['1:-:0:1:m', '2:1:1:1:m', '3:2:2:0:m'], array_slice($shape, 2));
    }

    public function testSelfParentIsARoot(): void
    {
        $nodes = self::build([self::row(4, 4)]);

        $this->assertSame(['4:-:0:0:m'], self::shape($nodes));
    }

    public function testCycleAmongLoadedAncestorsStopsLoading(): void
    {
        // Předci ukazují na sebe navzájem: 50 → 51 → 50.
        $others = [50 => self::row(50, 51), 51 => self::row(51, 50)];
        $nodes = self::build([self::row(7, 50)], $others, $requests);

        $this->assertSame([[50], [51]], $requests);
        // Cyklus se vydá od uzlu, který na něm leží — nalezený uzel zůstává
        // pod svým rodičem, ne jako samostatný kořen.
        $this->assertSame(['50:-:0:2:c', '7:50:1:0:m', '51:50:1:0:c'], self::shape($nodes));
    }

    public function testDuplicateSelectedRowIsTakenOnce(): void
    {
        $nodes = self::build([self::row(1, null), self::row(1, null)]);

        $this->assertCount(1, $nodes);
    }
}
