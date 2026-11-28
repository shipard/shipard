<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing\Rows;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\SubtableCellFormatter;

/**
 * Sub-tabulka řádků předpisu pro `renderSubtable()` formuláře rodiče
 * (#174 D31): popis, množství, jednotka, cena, kód DPH a — má-li řádek
 * platnost — platnost od / do. Sdílí ji formulář záznamu každého zdroje
 * fakturace a formulář druhu se šablonou řádků (bez platnosti).
 *
 * Popisky sloupců dodá volající (lokalizovaná definice tabulky řádků přes
 * `TableForm::subtableLabel()`), čísla a data jdou výhradně přes
 * SubtableCellFormatter.
 */
final class PrescriptionRowsSubtable
{
    public const ORDER_COLUMN = 'order_pos';

    /**
     * @param list<array<string, mixed>> $rows řádky předpisu
     * @param callable(string, string): string $label popisek sloupce: (sloupec, český fallback)
     * @param array<int, string> $unitShortcuts id jednotky → zkratka
     * @param string $language jazyk požadavku (zápis data)
     * @return array{columns: list<array<string, mixed>>, rows: list<array{id: int, cells: array<string, string>}>, order_column: string}
     */
    public static function render(
        array $rows,
        callable $label,
        array $unitShortcuts,
        string $language,
        bool $withValidity = true,
    ): array {
        $columns = [
            ['id' => 'description', 'label' => $label('description', 'Popis'), 'grow' => true],
            ['id' => 'quantity', 'label' => $label('quantity', 'Množství'), 'align' => 'right', 'width' => 90],
            ['id' => 'unit', 'label' => $label('unit', 'Jednotka'), 'width' => 80],
            ['id' => 'unit_price', 'label' => $label('unit_price', 'Cena/jednotka'), 'align' => 'right', 'width' => 120],
            ['id' => 'vat_code', 'label' => $label('vat_code', 'Kód DPH'), 'width' => 90],
        ];
        if ($withValidity) {
            $columns[] = ['id' => 'valid_from', 'label' => $label('valid_from', 'Platnost od'), 'width' => 100];
            $columns[] = ['id' => 'valid_to', 'label' => $label('valid_to', 'Platnost do'), 'width' => 100];
        }

        $out = [];
        foreach ($rows as $row) {
            $cells = [
                'description' => (string) ($row['description'] ?? ''),
                'quantity'    => SubtableCellFormatter::trimmedNumber($row['quantity'] ?? null, 4) ?? '',
                'unit'        => $unitShortcuts[(int) ($row['unit'] ?? 0)] ?? '',
                'unit_price'  => SubtableCellFormatter::number($row['unit_price'] ?? null, 2) ?? '',
                'vat_code'    => (string) ($row['vat_code'] ?? ''),
            ];
            if ($withValidity) {
                $cells['valid_from'] = SubtableCellFormatter::date($row['valid_from'] ?? null, $language) ?? '';
                $cells['valid_to'] = SubtableCellFormatter::date($row['valid_to'] ?? null, $language) ?? '';
            }
            $out[] = ['id' => (int) ($row['id'] ?? 0), 'cells' => $cells];
        }

        return ['columns' => $columns, 'rows' => $out, 'order_column' => self::ORDER_COLUMN];
    }

    /**
     * Zkratky jednotek pro sloupec Jednotka (id → zkratka, bez ní název).
     *
     * @return array<int, string>
     */
    public static function unitShortcuts(?DataSourceConnection $db): array
    {
        if ($db === null) {
            return [];
        }
        $out = [];
        foreach ($db->fetchAll('SELECT `id`, `name`, `shortcut` FROM `core_units`') as $row) {
            $shortcut = (string) ($row['shortcut'] ?? '');
            $out[(int) $row['id']] = $shortcut !== '' ? $shortcut : (string) ($row['name'] ?? '');
        }
        return $out;
    }
}
