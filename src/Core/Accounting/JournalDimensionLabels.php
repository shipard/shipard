<?php

declare(strict_types=1);

namespace Shipard\Core\Accounting;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Popisky dimenzí deníku použitých v řádcích deníku — sdílí je tab
 * Účtování v detailu dokladu a tisk Kontace, ať oba ukazují totéž.
 *
 * Vrací jen dimenze, které některý řádek nese; hodnoty se dohledají jedním
 * dotazem per dimenze (`displayPattern` cílové tabulky).
 */
final class JournalDimensionLabels
{
    /**
     * @param list<array<string, mixed>> $journalRows Řádky `economy_accounting_journal`
     *        včetně sloupců dimenzí (`JournalDimension::$journalColumn`).
     * @return array<string, array{name: string, column: string, labels: array<int, string>}>
     *         id dimenze → název, sloupec deníku a id hodnoty → popisek
     */
    public static function forRows(JournalDimensionSet $dimensions, DataSourceConnection $db, array $journalRows): array
    {
        $out = [];
        foreach ($dimensions as $dimension) {
            $ids = [];
            foreach ($journalRows as $jr) {
                $value = (int) ($jr[$dimension->journalColumn] ?? 0);
                if ($value > 0) {
                    $ids[$value] = true;
                }
            }
            if ($ids === []) {
                continue;
            }
            $columns = array_unique(['id', ...$dimension->labelColumns()]);
            $rows = $db->fetchAll(
                'SELECT `' . implode('`, `', $columns) . '` FROM `' . $dimension->table . '` WHERE `id` IN %in',
                array_keys($ids),
            );
            $labels = [];
            foreach ($rows as $row) {
                $record = [];
                foreach ($columns as $column) {
                    $record[$column] = $row[$column] ?? null;
                }
                $labels[(int) $row['id']] = $dimension->label($record) ?? '#' . (int) $row['id'];
            }
            $out[$dimension->id] = [
                'name'   => $dimension->name,
                'column' => $dimension->journalColumn,
                'labels' => $labels,
            ];
        }
        return $out;
    }
}
