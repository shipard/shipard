<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval\Browse;

use Shipard\Module\Core\Exchange\Compare\CanonicalComparator;
use Shipard\Module\Core\Exchange\Compare\FieldState;
use Shipard\Module\Core\Exchange\Compare\Projection;
use Shipard\Module\Core\Mail\Eval\EvalCallResult;
use Shipard\Module\Core\Mail\Eval\EvalFieldGroups;
use Shipard\Module\Core\Mail\Eval\EvalMessage;
use Shipard\Module\Core\Mail\Eval\EvalRunner;

/**
 * Rekonstrukce výstupu modelu pro detail dokladu v prohlížeči (#103 D53):
 * soubor běhu nese jen odchylky a shody po normalizaci, takže výstup =
 * očekávání sady + odchylky volání. Strom polí se skládá z projekce sady
 * (`expected/projection.jsonc`) v jejím pořadí; list bez záznamu ve volání
 * je shoda s hodnotou očekávání.
 *
 * Výsledek odpovídá list po listu výsledkům {@see CanonicalComparator::evaluate()},
 * ze kterých soubor vznikl — `counts` rekonstrukce proto u úspěšného volání
 * sedí s `counts` volání v souboru (test to hlídá):
 *
 * - pole (`rows`, `vatRecap`) po pozicích; jiný počet prvků je jedna
 *   odchylka na uzlu pole (`arrays`) a prvky očekávání nad počet výstupu
 *   se neporovnávaly (`notCompared`, do počtů nejdou); řádky výstupu nad
 *   počet očekávání v souboru nejsou vůbec;
 * - klíčované seznamy ({@see CanonicalComparator::KEYED_LISTS}: výjimky
 *   štítků podle `rowIndex`) mají v cestě klíč, ne pozici;
 * - odchylka na uzlu objektu (nullable `customer` prázdný na jedné straně,
 *   `rows[2].vat` null) nahrazuje listy pod ním jedním záznamem;
 * - klasifikace zprávy je virtuální list {@see EvalFieldGroups::CLASSIFICATION_PATH};
 * - selhané volání (D44): každý neprázdný list očekávání je `missing`,
 *   ostatní listy `notCompared`;
 * - shoda po normalizaci ze staršího běhu (jen cesta) nemá výstup
 *   (`actualKnown` false) — ukáže se očekávání se štítkem.
 */
final class EvalOutputReconstruction
{
    public const string STATE_NOT_COMPARED = 'notCompared';

    /** Uzly polí zobrazené jako tabulka: cesta → titulek. */
    public const array TABLE_NODES = [
        'rows' => 'Řádky',
        'vatRecap' => 'Rekapitulace DPH',
        '_resolve.contentTag.rowExceptions' => 'Výjimky štítků řádků',
    ];

    private const array COUNTED_STATES = ['match', 'matchNormalized', 'missing', 'extra', 'wrong'];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $call položka `calls[]` běhu
     * @return array<string, mixed>
     */
    public static function build(Projection $projection, EvalMessage $message, array $call, bool $currentSet = true): array
    {
        $projected = $projection->project($message->expectedCanonical);
        $leaves = self::expectedLeaves($projection, $projected);
        $arrays = [];
        $ok = ($call['status'] ?? null) === EvalCallResult::STATUS_OK;

        if ($ok) {
            foreach (EvalCallResult::normalizedEntries($call) as $entry) {
                self::apply($leaves, $entry['path'], FieldState::MatchNormalized->value, null, $entry['actual'], $entry['actualKnown'], $entry['similarity'], keepExpected: true);
            }
            foreach ((array) ($call['deviations'] ?? []) as $deviation) {
                if (!is_array($deviation) || !is_string($deviation['path'] ?? null)) {
                    continue;
                }
                $path = $deviation['path'];
                $state = (string) ($deviation['state'] ?? FieldState::Wrong->value);
                $expected = $deviation['expected'] ?? null;
                $actual = $deviation['actual'] ?? null;
                if (isset(self::TABLE_NODES[$path]) && is_int($expected) && is_int($actual)) {
                    $arrays[] = ['path' => $path, 'label' => self::TABLE_NODES[$path], 'state' => $state, 'expected' => $expected, 'actual' => $actual];
                    if ($actual < $expected) {
                        self::markNotCompared($leaves, $path, $actual);
                    }
                    continue;
                }
                $similarity = isset($deviation['similarity']) && is_numeric($deviation['similarity']) ? (float) $deviation['similarity'] : null;
                self::apply($leaves, $path, $state, $expected, $actual, true, $similarity, keepExpected: false);
            }
        } else {
            foreach ($leaves as &$leaf) {
                $leaf['actual'] = null;
                $leaf['state'] = self::STATE_NOT_COMPARED;
                $leaf['counted'] = false;
            }
            unset($leaf);
            foreach ((new CanonicalComparator($projection))->evaluateAgainstNothing($message->expectedCanonical) as $outcome) {
                self::apply($leaves, $outcome->path, $outcome->state->value, $outcome->expected, null, true, null, keepExpected: false);
            }
        }

        $classification = EvalRunner::classificationOutcome($message->expectedPrimaryType, is_string($call['primaryType'] ?? null) ? $call['primaryType'] : null);
        $leaves[] = self::leaf($classification->path, null, $classification->expected, $classification->actual, $classification->state->value);

        return self::assemble($message, $call, $leaves, $arrays, $currentSet);
    }

    // ── listy očekávání ─────────────────────────────────────────────────────

    /**
     * Listy projekce v jejím pořadí; nullable uzel prázdný v očekávání jako
     * jeden list (porovnávač dává jeden výsledek za uzel); klíčované seznamy
     * s klíčem v cestě.
     *
     * @param array<string, mixed> $projected
     * @return list<array<string, mixed>>
     */
    private static function expectedLeaves(Projection $projection, array $projected): array
    {
        $leaves = [];
        $projection->map($projected, static function (?string $shape, mixed $value, ?array $parent, string $path) use (&$leaves): mixed {
            $leaves[] = self::leaf($path, $shape, $value, $value, FieldState::Match->value);
            return $value;
        });

        foreach ($projection->nullableNodes() as $node) {
            if (array_key_exists($node, $projected) && $projected[$node] === null && !str_contains($node, '.')) {
                $leaves[] = self::leaf($node, null, null, null, FieldState::Match->value);
            }
        }

        foreach (CanonicalComparator::KEYED_LISTS as $nodePath => $key) {
            $list = self::valueAt($projected, $nodePath);
            $positions = is_array($list) ? self::keyedPositions($list, $key) : null;
            if ($positions === null) {
                continue;
            }
            $prefix = $nodePath . '[';
            foreach ($leaves as &$leaf) {
                if (!str_starts_with($leaf['path'], $prefix)) {
                    continue;
                }
                if (preg_match('/^' . preg_quote($nodePath, '/') . '\[(\d+)\](.*)$/', $leaf['path'], $m) && isset($positions[(int) $m[1]])) {
                    $leaf['path'] = $nodePath . '[' . $positions[(int) $m[1]] . ']' . $m[2];
                }
            }
            unset($leaf);
        }

        return self::ordered($leaves);
    }

    /**
     * Pozice → klíč, jen když má každý prvek celočíselný klíč ≥ 0 a klíče
     * se neopakují (jinak porovnávač jede po pozicích).
     *
     * @param list<mixed> $list
     * @return array<int, int>|null
     */
    private static function keyedPositions(array $list, string $key): ?array
    {
        $positions = [];
        $seen = [];
        foreach (array_values($list) as $i => $item) {
            $k = is_array($item) ? ($item[$key] ?? null) : null;
            if (!is_int($k) || $k < 0 || isset($seen[$k])) {
                return null;
            }
            $seen[$k] = true;
            $positions[$i] = $k;
        }
        return $positions;
    }

    /** @param array<string, mixed> $tree */
    private static function valueAt(array $tree, string $dotted): mixed
    {
        $cursor = $tree;
        foreach (explode('.', $dotted) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }

    // ── aplikace záznamů volání ─────────────────────────────────────────────

    /**
     * Záznam volání na cestě: list → přepsat; uzel s listy pod sebou →
     * listy nahradit jedním záznamem; neznámá cesta → nový list (`extra`
     * na prvku, který očekávání nemá).
     *
     * @param list<array<string, mixed>> $leaves
     */
    private static function apply(array &$leaves, string $path, string $state, mixed $expected, mixed $actual, bool $actualKnown, ?float $similarity, bool $keepExpected): void
    {
        $index = self::indexOf($leaves, $path);
        if ($index !== null) {
            $leaf = &$leaves[$index];
            $leaf['state'] = $state;
            if (!$keepExpected) {
                $leaf['expected'] = $expected;
            }
            $leaf['actual'] = $actualKnown ? $actual : $leaf['expected'];
            $leaf['actualKnown'] = $actualKnown;
            $leaf['similarity'] = $similarity;
            $leaf['counted'] = true;
            unset($leaf);
            return;
        }

        $descendants = [];
        foreach ($leaves as $i => $leaf) {
            if (str_starts_with($leaf['path'], $path . '.') || str_starts_with($leaf['path'], $path . '[')) {
                $descendants[] = $i;
            }
        }
        $node = self::leaf($path, null, $expected, $actualKnown ? $actual : $expected, $state);
        $node['actualKnown'] = $actualKnown;
        $node['similarity'] = $similarity;
        if ($descendants === []) {
            $leaves[] = $node;
            return;
        }
        $first = $descendants[0];
        $leaves = array_values(array_merge(
            array_slice($leaves, 0, $first),
            [$node],
            array_values(array_filter(array_slice($leaves, $first), static fn (array $l): bool => !str_starts_with($l['path'], $path . '.') && !str_starts_with($l['path'], $path . '['))),
        ));
    }

    /** Prvky pole od pozice `$from` se neporovnávaly (výstup má méně prvků). */
    private static function markNotCompared(array &$leaves, string $arrayPath, int $from): void
    {
        $re = '/^' . preg_quote($arrayPath, '/') . '\[(\d+)\]/';
        foreach ($leaves as &$leaf) {
            if (preg_match($re, $leaf['path'], $m) && (int) $m[1] >= $from) {
                $leaf['state'] = self::STATE_NOT_COMPARED;
                $leaf['actual'] = null;
                $leaf['counted'] = false;
            }
        }
        unset($leaf);
    }

    /** @param list<array<string, mixed>> $leaves */
    private static function indexOf(array $leaves, string $path): ?int
    {
        foreach ($leaves as $i => $leaf) {
            if ($leaf['path'] === $path) {
                return $i;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private static function leaf(string $path, ?string $shape, mixed $expected, mixed $actual, string $state): array
    {
        $nodePath = Projection::nodePath($path);
        return [
            'path' => $path,
            'nodePath' => $nodePath,
            'shape' => $shape,
            'group' => EvalFieldGroups::groupOf($nodePath),
            'expected' => $expected,
            'actual' => $actual,
            'actualKnown' => true,
            'state' => $state,
            'similarity' => null,
            'counted' => true,
        ];
    }

    /** @param list<array<string, mixed>> $leaves @return list<array<string, mixed>> */
    private static function ordered(array $leaves): array
    {
        return array_values($leaves);
    }

    // ── výstup ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $call
     * @param list<array<string, mixed>> $leaves
     * @param list<array<string, mixed>> $arrays
     * @return array<string, mixed>
     */
    private static function assemble(EvalMessage $message, array $call, array $leaves, array $arrays, bool $currentSet): array
    {
        $counts = self::emptyCounts();
        $groupCounts = [];
        foreach (EvalFieldGroups::GROUPS as $group) {
            $groupCounts[$group] = self::emptyCounts();
        }
        $fieldsByGroup = array_fill_keys(EvalFieldGroups::GROUPS, []);
        $tables = [];
        foreach (self::TABLE_NODES as $path => $label) {
            $tables[$path] = ['path' => $path, 'label' => $label, 'group' => EvalFieldGroups::groupOf($path), 'columns' => [], 'rows' => []];
        }

        foreach ($leaves as $leaf) {
            if ($leaf['counted'] && in_array($leaf['state'], self::COUNTED_STATES, true)) {
                $counts[$leaf['state']]++;
                $groupCounts[$leaf['group']][$leaf['state']]++;
            }
            $table = self::tableOf($leaf['path']);
            if ($table === null) {
                $fieldsByGroup[$leaf['group']][] = $leaf;
                continue;
            }
            [$node, $key, $column] = $table;
            $tables[$node]['rows'][$key] ??= ['key' => $key, 'cells' => [], 'node' => null];
            if ($column === '') {
                $tables[$node]['rows'][$key]['node'] = $leaf;
                continue;
            }
            if (!in_array($column, $tables[$node]['columns'], true)) {
                $tables[$node]['columns'][] = $column;
            }
            $tables[$node]['rows'][$key]['cells'][$column] = $leaf;
        }
        foreach ($arrays as $entry) {
            $counts[$entry['state']]++;
            $groupCounts[EvalFieldGroups::groupOf($entry['path'])][$entry['state']]++;
        }

        $groups = [];
        foreach (EvalFieldGroups::GROUPS as $group) {
            $groups[] = [
                'id' => $group,
                'label' => EvalFieldGroups::label($group),
                'counts' => $groupCounts[$group],
                'fields' => $fieldsByGroup[$group],
            ];
        }
        foreach ($tables as &$table) {
            ksort($table['rows'], SORT_NUMERIC);
            $table['rows'] = array_values($table['rows']);
        }
        unset($table);

        $fileCounts = is_array($call['counts'] ?? null) ? $call['counts'] : null;
        $classification = null;
        foreach ($leaves as $leaf) {
            if ($leaf['path'] === EvalFieldGroups::CLASSIFICATION_PATH) {
                $classification = ['expected' => $leaf['expected'], 'actual' => $leaf['actual'], 'state' => $leaf['state']];
            }
        }

        return [
            'messageId' => $message->messageId,
            'cell' => EvalDatasetMessages::cell($message),
            'repeat' => (int) ($call['repeat'] ?? 1),
            'status' => $call['status'] ?? null,
            'errorType' => $call['errorType'] ?? null,
            'note' => $call['note'] ?? null,
            'costUsd' => $call['costUsd'] ?? null,
            'tokensIn' => $call['tokensIn'] ?? null,
            'tokensOut' => $call['tokensOut'] ?? null,
            'durationMs' => $call['durationMs'] ?? null,
            'model' => $call['model'] ?? null,
            'tag' => $call['tag'] ?? null,
            'currentSet' => $currentSet,
            'classification' => $classification,
            'counts' => $counts,
            'fileCounts' => $fileCounts,
            'countsMatch' => $fileCounts === null ? null : self::sameCounts($counts, $fileCounts),
            'arrays' => $arrays,
            'groups' => $groups,
            'tables' => array_values($tables),
        ];
    }

    /** @return array{0: string, 1: int, 2: string}|null uzel pole, klíč prvku, sloupec ('' = záznam na prvku) */
    private static function tableOf(string $path): ?array
    {
        foreach (self::TABLE_NODES as $node => $_) {
            if (preg_match('/^' . preg_quote($node, '/') . '\[(\d+)\](?:\.(.+))?$/', $path, $m)) {
                return [$node, (int) $m[1], $m[2] ?? ''];
            }
        }
        return null;
    }

    /** @return array{match: int, matchNormalized: int, missing: int, extra: int, wrong: int} */
    private static function emptyCounts(): array
    {
        return ['match' => 0, 'matchNormalized' => 0, 'missing' => 0, 'extra' => 0, 'wrong' => 0];
    }

    /** @param array<string, int> $a @param array<string, mixed> $b */
    private static function sameCounts(array $a, array $b): bool
    {
        foreach (self::COUNTED_STATES as $state) {
            if (($a[$state] ?? 0) !== (int) ($b[$state] ?? 0)) {
                return false;
            }
        }
        return true;
    }
}
