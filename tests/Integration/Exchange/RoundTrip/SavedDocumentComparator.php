<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange\RoundTrip;

use Shipard\Core\Database\ColumnDefinition;
use Shipard\Core\Database\TableDefinition;

/**
 * Porovnání dvou uložených dokladů sloupec po sloupci (U3): sloupce bere
 * z definic tabulek ({@see RoundTripColumns::compared()}), čísla porovnává
 * na počet desetinných míst sloupce, data jako text, JSON po klíčích.
 * Řádky páruje podle `order_pos`, rekapitulaci podle klíče skupiny (kód DPH,
 * sazba, předmět plnění, datum sazby, příznak oddaňovacího páru).
 *
 * Výsledek je mapa `cesta → [A, B]`; prázdná = doklady se shodují. Cesty:
 * `head.<sloupec>`, `rows[<order_pos>].<sloupec>`,
 * `vatRecap[<klíč skupiny>].<sloupec>`, u JSON sloupce s tečkovou cestou
 * dovnitř (`head.supplier_snapshot.address.city`; klíč, který v jednom
 * z dokladů chybí, nese {@see ABSENT}). Chybějící řádek je rozdíl celého
 * prvku (`rows[2]` → `['present', 'missing']`).
 *
 * Hodnoty referenčních sloupců jsou id závislá na běhu — volitelný
 * `$referenceLabel` je převede na stálé označení, aby šel rozdíl zapsat
 * jako známá mezera.
 */
final class SavedDocumentComparator
{
    public const PRESENT = 'present';
    public const MISSING = 'missing';
    /** Klíč v JSON sloupci chybí (na rozdíl od klíče s hodnotou null). */
    public const ABSENT = '(klíč chybí)';

    private const INT_TYPES = ['tinyint', 'smallint', 'int', 'bigint', 'enumInt', 'boolean'];

    /** Sloupce klíče skupiny rekapitulace v pořadí zápisu klíče. */
    private const RECAP_KEY = ['vat_code', 'vat_pct', 'reverse_charge_code', 'vat_rate_date', 'is_reverse_pair'];

    /** @var (\Closure(string, int): string)|null tabulka reference, id → označení */
    private readonly ?\Closure $referenceLabel;

    /**
     * @param array<string, TableDefinition> $tables definice aspoň tří tabulek dokladu
     * @param (callable(string, int): string)|null $referenceLabel
     */
    public function __construct(
        private readonly array $tables,
        ?callable $referenceLabel = null,
    ) {
        foreach (RoundTripColumns::TABLES as $table) {
            if (!isset($tables[$table])) {
                throw new \InvalidArgumentException("Chybí definice tabulky {$table}.");
            }
        }
        $this->referenceLabel = $referenceLabel !== null ? $referenceLabel(...) : null;
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}> cesta → [A, B]
     */
    public function compare(SavedDocument $a, SavedDocument $b): array
    {
        $diff = $this->compareRecord(RoundTripColumns::HEADS, 'head', $a->head, $b->head);

        $diff += $this->compareSet(
            RoundTripColumns::ROWS,
            'rows',
            $this->keyed($a->rows, fn (array $row): string => (string) (int) ($row['order_pos'] ?? 0)),
            $this->keyed($b->rows, fn (array $row): string => (string) (int) ($row['order_pos'] ?? 0)),
        );
        $diff += $this->compareSet(
            RoundTripColumns::RECAP,
            'vatRecap',
            $this->keyed($a->recap, $this->recapKey(...)),
            $this->keyed($b->recap, $this->recapKey(...)),
        );

        return $diff;
    }

    /**
     * @param array<string, array<string, mixed>> $a
     * @param array<string, array<string, mixed>> $b
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function compareSet(string $table, string $prefix, array $a, array $b): array
    {
        $diff = [];
        foreach (array_keys($a + $b) as $key) {
            $path = "{$prefix}[{$key}]";
            if (!isset($a[$key]) || !isset($b[$key])) {
                $diff[$path] = [
                    isset($a[$key]) ? self::PRESENT : self::MISSING,
                    isset($b[$key]) ? self::PRESENT : self::MISSING,
                ];
                continue;
            }
            $diff += $this->compareRecord($table, $path, $a[$key], $b[$key]);
        }
        return $diff;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function compareRecord(string $table, string $prefix, array $a, array $b): array
    {
        $diff = [];
        foreach (RoundTripColumns::compared($table, $this->tables[$table]) as $column) {
            $va = $this->normalize($column, $a[$column->id] ?? null);
            $vb = $this->normalize($column, $b[$column->id] ?? null);
            if ($va === $vb) {
                continue;
            }
            $path = "{$prefix}.{$column->id}";
            if ($column->type === 'json' && is_array($va) && is_array($vb)) {
                $diff += self::jsonDiff($path, $va, $vb);
                continue;
            }
            if ($column->reference !== null && $this->referenceLabel !== null) {
                $va = is_int($va) ? ($this->referenceLabel)($column->reference, $va) : $va;
                $vb = is_int($vb) ? ($this->referenceLabel)($column->reference, $vb) : $vb;
            }
            $diff[$path] = [$va, $vb];
        }
        return $diff;
    }

    /** Hodnota sloupce v porovnatelném tvaru podle typu z definice. */
    private function normalize(ColumnDefinition $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format($column->type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s');
        }
        return match (true) {
            $column->type === 'numeric' => number_format((float) $value, (int) $column->scale, '.', ''),
            $column->type === 'float'   => round((float) $value, 6),
            in_array($column->type, self::INT_TYPES, true) => (int) $value,
            $column->type === 'date'     => substr((string) $value, 0, 10),
            $column->type === 'datetime' => str_replace('T', ' ', substr((string) $value, 0, 19)),
            $column->type === 'json'     => self::decodeJson($value),
            default => (string) $value,
        };
    }

    private static function decodeJson(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }
        return is_array($value) ? self::sortKeys($value) : $value;
    }

    /**
     * @param array<int|string, mixed> $value
     * @return array<int|string, mixed>
     */
    private static function sortKeys(array $value): array
    {
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::sortKeys($v);
            }
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return $value;
    }

    /**
     * Rozdíl dvou JSON hodnot po listech s tečkovou cestou.
     *
     * @param array<int|string, mixed> $a
     * @param array<int|string, mixed> $b
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private static function jsonDiff(string $path, array $a, array $b): array
    {
        $diff = [];
        foreach (array_keys($a + $b) as $key) {
            // Chybějící klíč není totéž co null — uložený JSON se liší.
            $va = array_key_exists($key, $a) ? $a[$key] : self::ABSENT;
            $vb = array_key_exists($key, $b) ? $b[$key] : self::ABSENT;
            if ($va === $vb) {
                continue;
            }
            if (is_array($va) && is_array($vb)) {
                $diff += self::jsonDiff("{$path}.{$key}", $va, $vb);
                continue;
            }
            $diff["{$path}.{$key}"] = [$va, $vb];
        }
        return $diff;
    }

    /**
     * @param list<array<string, mixed>> $records
     * @param \Closure(array<string, mixed>): string $keyOf
     * @return array<string, array<string, mixed>> klíč → záznam; opakovaný klíč dostane `#2`, `#3`…
     */
    private function keyed(array $records, \Closure $keyOf): array
    {
        $out = [];
        foreach ($records as $record) {
            $key = $keyOf($record);
            $unique = $key;
            for ($n = 2; isset($out[$unique]); $n++) {
                $unique = "{$key}#{$n}";
            }
            $out[$unique] = $record;
        }
        return $out;
    }

    /**
     * Klíč skupiny rekapitulace, např. `cz-110/21.00/-/-/0`.
     *
     * @param array<string, mixed> $record
     */
    private function recapKey(array $record): string
    {
        $columns = [];
        foreach ($this->tables[RoundTripColumns::RECAP]->columns as $column) {
            $columns[$column->id] = $column;
        }
        $parts = [];
        foreach (self::RECAP_KEY as $id) {
            $value = isset($columns[$id]) ? $this->normalize($columns[$id], $record[$id] ?? null) : ($record[$id] ?? null);
            $parts[] = $value === null || $value === '' ? '-' : (string) $value;
        }
        return implode('/', $parts);
    }
}
