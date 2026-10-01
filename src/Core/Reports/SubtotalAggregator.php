<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

/**
 * Obecný prefix-based rollup detail řádků (klíč = číslo účtu): pro zadané
 * délky prefixů (v1: 3 = syntetika, 2 = skupina, 1 = třída) vyrobí subtotal
 * řádky + závěrečný total. Sčítá `md`/`d` per sloupec; `balance` = md − d
 * (znaménko dle stran účtu, ne prezentace — D6).
 *
 * Výstupní pořadí: detail řádky dle čísla účtu, subtotal následuje hned za
 * svou skupinou (delší prefix před kratším), total na konci. Levely:
 * total = 0, subtotaly 1..k dle délky prefixu (nejkratší = 1); level detail
 * řádků určuje builder.
 *
 * Píše se obecně — Fáze 2 (výsledovka, rozvaha) ho použije beze změn.
 * Reporty, jejichž řádky nejsou účty, seskupuje `groupBy()`.
 */
final class SubtotalAggregator
{
    /**
     * @param list<ReportRow> $detailRows Řádky s neprázdným `account`.
     * @param list<int> $prefixLengths Délky prefixů, např. [3, 2, 1].
     * @param callable(string, int): string $labelResolver
     *        fn(prefix, délka) → label subtotal řádku.
     * @return list<ReportRow>
     */
    public function rollup(
        array $detailRows,
        array $prefixLengths,
        callable $labelResolver,
        string $totalLabel,
    ): array {
        if ($detailRows === []) {
            return [];
        }

        usort(
            $detailRows,
            static fn (ReportRow $a, ReportRow $b): int => strcmp((string) $a->account, (string) $b->account),
        );

        $lengthsDesc = $prefixLengths;
        rsort($lengthsDesc, SORT_NUMERIC);
        $lengthsAsc = array_reverse($lengthsDesc);

        // Nejkratší prefix (třída) = level 1, delší postupně hlouběji.
        $levelByLength = [];
        foreach ($lengthsAsc as $i => $length) {
            $levelByLength[$length] = $i + 1;
        }

        /** @var array<int, array{prefix: string, sums: array<string, array{md: float, d: float}>}> $open */
        $open  = [];
        $total = [];
        $out   = [];

        foreach ($detailRows as $row) {
            $account = (string) $row->account;

            foreach ($lengthsDesc as $length) {
                $prefix = substr($account, 0, $length);
                if (isset($open[$length]) && $open[$length]['prefix'] !== $prefix) {
                    $out[] = $this->makeRow(
                        ReportRowKind::Subtotal,
                        $levelByLength[$length],
                        $open[$length]['prefix'],
                        $labelResolver($open[$length]['prefix'], $length),
                        $open[$length]['sums'],
                    );
                    unset($open[$length]);
                }
                if (!isset($open[$length])) {
                    $open[$length] = ['prefix' => $prefix, 'sums' => []];
                }
                $this->accumulate($open[$length]['sums'], $row->values);
            }

            $this->accumulate($total, $row->values);
            $out[] = $row;
        }

        foreach ($lengthsDesc as $length) {
            if (!isset($open[$length])) {
                continue;
            }
            $out[] = $this->makeRow(
                ReportRowKind::Subtotal,
                $levelByLength[$length],
                $open[$length]['prefix'],
                $labelResolver($open[$length]['prefix'], $length),
                $open[$length]['sums'],
            );
        }

        $out[] = $this->makeRow(ReportRowKind::Total, 0, null, $totalLabel, $total);

        return $out;
    }

    /**
     * Seskupení detail řádků podle explicitního klíče — pro reporty, jejichž
     * řádky nejsou účty (karty majetku po účetní skupině, události po
     * druhu). Skupiny jdou v pořadí prvního výskytu a řádky uvnitř skupiny
     * drží pořadí vstupu; řazení je věc builderu.
     *
     * Řádek skupiny (subtotal, level 1, `key` = `group:{klíč}`) stojí před
     * svými řádky jako nadpis se součty, nebo za nimi (`$subtotalFirst`
     * false). Bez `$groupOf` vzniká jen total, bez `$totalLabel` jen skupiny.
     *
     * @param list<ReportRow> $detailRows
     * @param ?callable(ReportRow): string $groupOf fn(řádek) → klíč skupiny.
     * @param callable(string): string $labelResolver fn(klíč) → label skupiny.
     * @return list<ReportRow>
     */
    public function groupBy(
        array $detailRows,
        ?callable $groupOf,
        callable $labelResolver,
        ?string $totalLabel,
        bool $subtotalFirst = true,
    ): array {
        if ($detailRows === []) {
            return [];
        }

        /** @var array<string, array{rows: list<ReportRow>, sums: array<string, array{md: float, d: float}>}> $groups */
        $groups = [];
        $total  = [];
        foreach ($detailRows as $row) {
            $key = $groupOf !== null ? $groupOf($row) : '';
            $groups[$key] ??= ['rows' => [], 'sums' => []];
            $groups[$key]['rows'][] = $row;
            $this->accumulate($groups[$key]['sums'], $row->values);
            $this->accumulate($total, $row->values);
        }

        $out = [];
        foreach ($groups as $key => $group) {
            $key = (string) $key;
            if ($groupOf === null) {
                array_push($out, ...$group['rows']);
                continue;
            }
            $subtotal = $this->makeRow(
                ReportRowKind::Subtotal,
                1,
                null,
                $labelResolver($key),
                $group['sums'],
                'group:' . $key,
            );
            if ($subtotalFirst) {
                $out[] = $subtotal;
            }
            array_push($out, ...$group['rows']);
            if (!$subtotalFirst) {
                $out[] = $subtotal;
            }
        }

        if ($totalLabel !== null) {
            $out[] = $this->makeRow(ReportRowKind::Total, 0, null, $totalLabel, $total);
        }

        return $out;
    }

    /**
     * @param array<string, array{md: float, d: float}> $sums
     * @param array<string, array{md: float, d: float, balance: float}> $values
     */
    private function accumulate(array &$sums, array $values): void
    {
        foreach ($values as $columnId => $cell) {
            if (!is_array($cell)) {
                continue; // text/date buňky (string) nelze agregovat
            }
            if (!isset($sums[$columnId])) {
                $sums[$columnId] = ['md' => 0.0, 'd' => 0.0];
            }
            $sums[$columnId]['md'] += $cell['md'];
            $sums[$columnId]['d']  += $cell['d'];
        }
    }

    /** @param array<string, array{md: float, d: float}> $sums */
    private function makeRow(
        ReportRowKind $kind,
        int $level,
        ?string $account,
        string $label,
        array $sums,
        ?string $key = null,
    ): ReportRow {
        $values = [];
        foreach ($sums as $columnId => $sum) {
            $md = round($sum['md'], 2);
            $d  = round($sum['d'], 2);
            $values[$columnId] = ['md' => $md, 'd' => $d, 'balance' => round($md - $d, 2)];
        }
        return new ReportRow($kind, $level, $account, $label, $values, $key);
    }
}
