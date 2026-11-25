<?php

declare(strict_types=1);

namespace Shipard\Core\Viewer;

/**
 * Skládá strom pro stromový layout vieweru (#169 D20, D21;
 * docs/viewer-tree.md). Čistá třída bez databáze: dostane vybrané řádky,
 * dotáhne k nim předky a vrátí plochý seznam v pořadí průchodu do hloubky.
 *
 * Vybrané řádky (prošly filtrem a hledáním) mají `match: true`. Jejich
 * předci, kteří filtrem neprošli, se dotahují po vrstvách přes callback
 * a nesou `match: false` — bez nich by hledání „101“ vrátilo místnosti bez
 * budov a z místností by se staly kořeny.
 *
 * Poškozená data nic neshodí: dotahování má strop {@see MAX_DEPTH} vrstev,
 * průchod si pamatuje navštívené uzly a uzly v cyklu (nedosažitelné
 * z žádného kořene) se vydají jako další kořeny — nic nezmizí.
 */
final class TreeRowsBuilder
{
    public const MAX_DEPTH = 100;

    /**
     * @param list<array<string, mixed>> $selected raw řádky s `id` a sloupcem rodiče
     * @param callable(list<int>): list<array<string, mixed>> $loadByIds raw řádky podle id bez ohledu na filtry
     * @param callable(array<string, mixed>, array<string, mixed>): int $compare pořadí sourozenců
     * @return list<array{row: array<string, mixed>, parent: ?int, depth: int, childCount: int, match: bool}>
     */
    public static function build(array $selected, callable $loadByIds, string $parentColumn, callable $compare): array
    {
        /** @var array<int, array<string, mixed>> $rows id → raw řádek */
        $rows = [];
        /** @var array<int, bool> $match */
        $match = [];
        foreach ($selected as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && !isset($rows[$id])) {
                $rows[$id] = $row;
                $match[$id] = true;
            }
        }

        // Předci po vrstvách. Každé id se žádá nejvýš jednou — rodič, který
        // neexistuje, se nehledá znovu a cyklus dotahování neroztočí.
        $requested = [];
        $pending = self::missingParents($rows, $rows, $parentColumn, $requested);
        for ($layer = 0; $pending !== [] && $layer < self::MAX_DEPTH; $layer++) {
            foreach ($pending as $id) {
                $requested[$id] = true;
            }
            $loaded = [];
            foreach ($loadByIds($pending) as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0 && !isset($rows[$id])) {
                    $rows[$id] = $row;
                    $match[$id] = false;
                    $loaded[$id] = $row;
                }
            }
            $pending = self::missingParents($loaded, $rows, $parentColumn, $requested);
        }

        // Děti podle rodiče; rodič mimo sestavený strom dělá z uzlu kořen.
        /** @var array<int, list<int>> $children id rodiče (0 = kořeny) → id dětí */
        $children = [];
        foreach ($rows as $id => $row) {
            $parent = self::parentOf($row, $parentColumn);
            $children[$parent !== null && isset($rows[$parent]) && $parent !== $id ? $parent : 0][] = $id;
        }
        foreach ($children as &$ids) {
            usort($ids, static fn (int $a, int $b): int => $compare($rows[$a], $rows[$b]) ?: $a <=> $b);
        }
        unset($ids);

        $out = [];
        $visited = [];
        self::walk($children[0] ?? [], $rows, $children, $match, $visited, $out);

        // Uzly v cyklu nemají kořen, průchod je minul. Každý cyklus se vydá
        // jednou jako další kořen — od uzlu, který na cyklu leží, aby uzly
        // zavěšené pod cyklem zůstaly pod svým rodičem.
        $orphans = array_values(array_filter(array_keys($rows), static fn (int $id): bool => !isset($visited[$id])));
        usort($orphans, static fn (int $a, int $b): int => $compare($rows[$a], $rows[$b]) ?: $a <=> $b);
        foreach ($orphans as $id) {
            if (!isset($visited[$id])) {
                $entry = self::cycleEntry($id, $rows, $parentColumn, $visited);
                self::walk([$entry], $rows, $children, $match, $visited, $out);
            }
        }

        return $out;
    }

    /**
     * První uzel na cestě k rodičům, který se zopakuje — leží na cyklu.
     * Cesta, která cyklem neskončí (rodič chybí nebo už je vydaný), vrací
     * svůj poslední uzel.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, bool> $visited
     */
    private static function cycleEntry(int $id, array $rows, string $parentColumn, array $visited): int
    {
        $seen = [];
        $current = $id;
        while (!isset($seen[$current])) {
            $seen[$current] = true;
            $parent = self::parentOf($rows[$current], $parentColumn);
            if ($parent === null || !isset($rows[$parent]) || isset($visited[$parent])) {
                return $current;
            }
            $current = $parent;
        }
        return $current;
    }

    /**
     * Id rodičů řádků `$of`, kteří ve stromu chybí a ještě se nežádali.
     *
     * @param array<int, array<string, mixed>> $of
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, bool> $requested
     * @return list<int>
     */
    private static function missingParents(array $of, array $rows, string $parentColumn, array $requested): array
    {
        $missing = [];
        foreach ($of as $row) {
            $parent = self::parentOf($row, $parentColumn);
            if ($parent !== null && !isset($rows[$parent]) && !isset($requested[$parent])) {
                $missing[$parent] = true;
            }
        }
        return array_keys($missing);
    }

    /** @param array<string, mixed> $row */
    private static function parentOf(array $row, string $parentColumn): ?int
    {
        $parent = (int) ($row[$parentColumn] ?? 0);
        return $parent > 0 ? $parent : null;
    }

    /**
     * Průchod do hloubky od zadaných uzlů, bez rekurze. Uzel vydaný jako
     * kořen (začátek průchodu) nemá rodiče, i když na nějakého ukazuje.
     *
     * @param list<int> $roots
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, list<int>> $children
     * @param array<int, bool> $match
     * @param array<int, bool> $visited
     * @param list<array{row: array<string, mixed>, parent: ?int, depth: int, childCount: int, match: bool}> $out
     */
    private static function walk(array $roots, array $rows, array $children, array $match, array &$visited, array &$out): void
    {
        /** @var list<array{0: int, 1: ?int, 2: int}> $stack [id, rodič, hloubka] */
        $stack = [];
        foreach (array_reverse($roots) as $id) {
            $stack[] = [$id, null, 0];
        }

        while ($stack !== []) {
            [$id, $parent, $depth] = array_pop($stack);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;

            $childIds = array_values(array_filter(
                $children[$id] ?? [],
                static fn (int $childId): bool => !isset($visited[$childId]),
            ));
            $out[] = [
                'row'        => $rows[$id],
                'parent'     => $parent,
                'depth'      => $depth,
                'childCount' => count($childIds),
                'match'      => $match[$id] ?? false,
            ];
            foreach (array_reverse($childIds) as $childId) {
                $stack[] = [$childId, $id, $depth + 1];
            }
        }
    }
}
