<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

/**
 * Strojové porovnání dvou dekódovaných `ReportResult` array (D14) — čistá
 * třída bez DB, primární využití: kontrola importu ze starého Shipardu
 * (`report-diff` CLI). Strany nemusejí mít shodné `reportId` ani stejnou
 * sadu sloupců — porovnává se průnik sloupců dle `id`. Money sloupce se
 * porovnávají numericky s tolerancí, text/date sloupce striktně jako string
 * (`field: 'value'`, `delta: null`); chybějící `type` sloupce = money
 * (kompatibilita se starými exporty).
 *
 * Porovnávají se pouze řádky `kind: detail` (klíč = `account`) a jako
 * kontrolní součet řádky `kind: total` (klíč = `label`, jen labely přítomné
 * na obou stranách — stará strana total mít nemusí). Subtotaly a computed
 * se ignorují — derivují z detailů.
 *
 * D15: vstup se `status: errors` porovnání nezastaví, ale výsledek nese
 * `statusA`/`statusB` — striktní odmítnutí je věc konzumenta (CLI `--strict`).
 */
final class ReportDiff
{
    /** Tolerance absolutního rozdílu hodnot — pod ní se strany berou jako shodné. */
    public const TOLERANCE = 0.005;

    private const FIELDS = ['md', 'd', 'balance'];

    /**
     * @param array<string, mixed> $a Dekódovaný `ReportResult::toArray()` strany A.
     * @param array<string, mixed> $b Dekódovaný `ReportResult::toArray()` strany B.
     * @return array{
     *     identical: bool,
     *     differences: list<array{account: string, column: string, field: string, a: float|string, b: float|string, delta: ?float}>,
     *     onlyInA: list<string>,
     *     onlyInB: list<string>,
     *     columnsOnlyInA: list<string>,
     *     columnsOnlyInB: list<string>,
     *     statusA: string,
     *     statusB: string,
     * }
     */
    public function diff(array $a, array $b): array
    {
        $columnsA = $this->columnTypes($a);
        $columnsB = $this->columnTypes($b);
        // Průnik sloupců; kind porovnání: money jen když obě strany money,
        // jinak string (text/date na kterékoli straně vítězí).
        $sharedColumns = [];
        foreach ($columnsA as $id => $typeA) {
            if (!isset($columnsB[$id])) {
                continue;
            }
            $sharedColumns[$id] = ($typeA === 'money' && $columnsB[$id] === 'money') ? 'money' : 'text';
        }

        // Detail se páruje podle `key` (řádky, které nejsou účty — karty
        // majetku, události), jinak podle `account`.
        $detailsA = $this->rowsByKey($a, 'detail', ['key', 'account']);
        $detailsB = $this->rowsByKey($b, 'detail', ['key', 'account']);
        $totalsA  = $this->rowsByKey($a, 'total', ['label']);
        $totalsB  = $this->rowsByKey($b, 'total', ['label']);

        // Číselné účty PHP kastuje na int klíče — ven jdou vždy stringy.
        $onlyInA = array_map(strval(...), array_values(array_diff(array_keys($detailsA), array_keys($detailsB))));
        $onlyInB = array_map(strval(...), array_values(array_diff(array_keys($detailsB), array_keys($detailsA))));

        $differences = [];
        foreach ($detailsA as $account => $valuesA) {
            if (!isset($detailsB[$account])) {
                continue;
            }
            $this->compareValues((string) $account, $valuesA, $detailsB[$account], $sharedColumns, $differences);
        }
        // Totaly jen jako kontrolní součet — label chybějící na jedné straně
        // není rozdíl (total je volitelný), porovnává se jen průnik labelů.
        foreach ($totalsA as $label => $valuesA) {
            if (!isset($totalsB[$label])) {
                continue;
            }
            $this->compareValues((string) $label, $valuesA, $totalsB[$label], $sharedColumns, $differences);
        }

        return [
            'identical'      => $differences === [] && $onlyInA === [] && $onlyInB === [],
            'differences'    => $differences,
            'onlyInA'        => $onlyInA,
            'onlyInB'        => $onlyInB,
            'columnsOnlyInA' => array_values(array_diff(array_keys($columnsA), array_keys($columnsB))),
            'columnsOnlyInB' => array_values(array_diff(array_keys($columnsB), array_keys($columnsA))),
            'statusA'        => (string) ($a['status'] ?? 'ok'),
            'statusB'        => (string) ($b['status'] ?? 'ok'),
        ];
    }

    /** @return array<string, string> id sloupce => typ; chybějící/nevalidní `type` = money. */
    private function columnTypes(array $result): array
    {
        $types = [];
        foreach ($result['columns'] ?? [] as $column) {
            if (is_array($column) && isset($column['id']) && is_string($column['id'])) {
                $type = $column['type'] ?? 'money';
                $types[$column['id']] = is_string($type) ? $type : 'money';
            }
        }
        return $types;
    }

    /**
     * Řádky daného kind klíčované první neprázdnou hodnotou z $keyFields;
     * řádky bez klíče se přeskočí (nelze je spárovat).
     *
     * @param list<string> $keyFields
     * @return array<string, array<string, mixed>> klíč => values
     */
    private function rowsByKey(array $result, string $kind, array $keyFields): array
    {
        $out = [];
        foreach ($result['rows'] ?? [] as $row) {
            if (!is_array($row) || ($row['kind'] ?? null) !== $kind) {
                continue;
            }
            $key = null;
            foreach ($keyFields as $field) {
                if (is_string($row[$field] ?? null) && $row[$field] !== '') {
                    $key = $row[$field];
                    break;
                }
            }
            if ($key === null) {
                continue;
            }
            $out[$key] = is_array($row['values'] ?? null) ? $row['values'] : [];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $valuesA
     * @param array<string, mixed> $valuesB
     * @param array<string, string> $columns id sloupce => kind porovnání ('money' | 'text')
     * @param list<array<string, mixed>> $differences
     */
    private function compareValues(string $key, array $valuesA, array $valuesB, array $columns, array &$differences): void
    {
        foreach ($columns as $column => $kind) {
            if ($kind !== 'money') {
                $valA = is_string($valuesA[$column] ?? null) ? $valuesA[$column] : '';
                $valB = is_string($valuesB[$column] ?? null) ? $valuesB[$column] : '';
                if ($valA !== $valB) {
                    $differences[] = [
                        'account' => $key,
                        'column'  => $column,
                        'field'   => 'value',
                        'a'       => $valA,
                        'b'       => $valB,
                        'delta'   => null,
                    ];
                }
                continue;
            }
            foreach (self::FIELDS as $field) {
                $valA = (float) ($valuesA[$column][$field] ?? 0.0);
                $valB = (float) ($valuesB[$column][$field] ?? 0.0);
                if (abs($valB - $valA) <= self::TOLERANCE) {
                    continue;
                }
                $differences[] = [
                    'account' => $key,
                    'column'  => $column,
                    'field'   => $field,
                    'a'       => $valA,
                    'b'       => $valB,
                    'delta'   => round($valB - $valA, 6),
                ];
            }
        }
    }
}
