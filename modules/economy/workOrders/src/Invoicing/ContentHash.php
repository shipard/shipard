<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Invoicing;

/**
 * Otisk obsahu konceptu (`economy_work_orders_periods.content_hash`, D10):
 * sha256 normalizovaného JSON vybraných sloupců hlavičky a řádků
 * uloženého dokladu. Počítá se z toho, co je v databázi (po apply), a
 * stejně se přepočítá později — shoda = koncept nikdo ručně neupravil,
 * takže ho běh po příchodu podkladů smí přegenerovat sám; neshoda =
 * upozornění „podklady jsou, koncept upravený — Přegenerovat“.
 */
final class ContentHash
{
    private const HEAD_COLUMNS = [
        'doc_text', 'partner', 'issue_date', 'due_date', 'accounting_date', 'vat_duzp',
        'period_from', 'period_to', 'doc_currency', 'vat_mode', 'payment_method', 'payment_reference',
        'bank_account', 'cost_center', 'work_order', 'number_series',
    ];

    private const ROW_COLUMNS = [
        'order_pos', 'row_kind', 'operation', 'item', 'description', 'quantity', 'unit', 'unit_price',
        'vat_code', 'cost_center', 'work_order',
    ];

    /**
     * @param array<string, mixed> $head
     * @param list<array<string, mixed>> $rows
     */
    public static function ofDocument(array $head, array $rows): string
    {
        $normalizedRows = [];
        foreach ($rows as $row) {
            $normalizedRows[] = self::pick($row, self::ROW_COLUMNS);
        }
        usort($normalizedRows, static fn(array $a, array $b): int => [$a['order_pos'] ?? '', $a['description'] ?? ''] <=> [$b['order_pos'] ?? '', $b['description'] ?? '']);

        $payload = ['head' => self::pick($head, self::HEAD_COLUMNS), 'rows' => $normalizedRows];
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $record
     * @param list<string> $columns
     * @return array<string, string|null>
     */
    private static function pick(array $record, array $columns): array
    {
        $out = [];
        foreach ($columns as $column) {
            $out[$column] = self::normalize($record[$column] ?? null);
        }
        return $out;
    }

    private static function normalize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_float($value) || (is_string($value) && is_numeric($value) && str_contains($value, '.'))) {
            return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
        }
        if (is_int($value)) {
            return (string) $value;
        }
        return trim((string) $value);
    }
}
