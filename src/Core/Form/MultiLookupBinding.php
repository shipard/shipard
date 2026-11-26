<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

use Shipard\Core\Database\TableDefinition;

/**
 * Vazba prvku `multilookup` na child tabulku rodiče (#176 D1–D3, D9).
 *
 * Prvek nese ve formuláři **seznam id** odkazovaných záznamů; v databázi je
 * to child tabulka, kterou rodič deklaruje v `childTables` a jejíž `dataKey`
 * je `column` prvku. Tahle třída vazbu odvodí z definic tabulek a převádí
 * mezi oběma tvary — čistě, bez databáze:
 *
 *  - odkazový sloupec = jediný sloupec child tabulky s `reference` na
 *    tabulku lookupu mimo cizí klíč na rodiče; žádný nebo víc kandidátů je
 *    chyba deklarace a prvek musí říct `refColumn` (D2);
 *  - pořadí drží sloupec `order_pos`, má-li ho child tabulka;
 *  - `toRows()` posílá gatewayi jen `id` / odkaz / pořadí — ostatní sloupce
 *    vazby prvek nespravuje: nový řádek dostane výchozí hodnoty sloupců,
 *    existující se nemění (D9).
 *
 * Chybná deklarace je `\LogicException` s tabulkou a sloupcem; nad stromem
 * ji hlídá `FormContractTest`.
 */
final class MultiLookupBinding
{
    public const ORDER_COLUMN = 'order_pos';

    private function __construct(
        /** `column` prvku = `dataKey` child tabulky. */
        public readonly string $column,
        public readonly string $lookupTable,
        public readonly string $childTable,
        public readonly string $foreignKey,
        public readonly string $refColumn,
        public readonly ?string $orderColumn,
    ) {
    }

    /**
     * @param array<string, TableDefinition> $tables definice tabulek zdroje dat
     */
    public static function forElement(
        FormElement $element,
        string $parentTable,
        TableDefinition $parentDef,
        array $tables,
    ): self {
        if ($element->type !== 'multilookup') {
            throw new \LogicException("MultiLookupBinding: element '{$element->column}' is not a multilookup");
        }

        return self::resolve(
            (string) $element->column,
            (string) ($element->lookup['table'] ?? ''),
            isset($element->lookup['ref_column']) ? (string) $element->lookup['ref_column'] : null,
            $parentTable,
            $parentDef,
            $tables,
        );
    }

    /**
     * @param array<string, TableDefinition> $tables
     */
    public static function resolve(
        string $column,
        string $lookupTable,
        ?string $refColumn,
        string $parentTable,
        TableDefinition $parentDef,
        array $tables,
    ): self {
        $where = "multilookup '{$column}' on table '{$parentTable}'";

        $child = null;
        foreach ($parentDef->childTables as $candidate) {
            if (($candidate['dataKey'] ?? null) === $column) {
                $child = $candidate;
                break;
            }
        }
        if ($child === null) {
            throw new \LogicException(
                "{$where}: table has no childTables entry with dataKey '{$column}'",
            );
        }

        $childTable = (string) ($child['table'] ?? '');
        $foreignKey = (string) ($child['foreignKey'] ?? '');
        $childDef = $tables[$childTable] ?? null;
        if ($childDef === null) {
            throw new \LogicException("{$where}: child table '{$childTable}' is not defined");
        }

        $columnIds = [];
        $candidates = [];
        foreach ($childDef->columns as $col) {
            $columnIds[$col->id] = true;
            if ($col->id !== $foreignKey && !$col->primaryKey && $col->reference === $lookupTable) {
                $candidates[] = $col->id;
            }
        }
        if (!isset($columnIds[$foreignKey])) {
            throw new \LogicException(
                "{$where}: foreign key '{$foreignKey}' is not a column of child table '{$childTable}'",
            );
        }

        if ($refColumn !== null) {
            if (!isset($columnIds[$refColumn])) {
                throw new \LogicException(
                    "{$where}: refColumn '{$refColumn}' is not a column of child table '{$childTable}'",
                );
            }
            if ($refColumn === $foreignKey) {
                throw new \LogicException(
                    "{$where}: refColumn '{$refColumn}' is the foreign key of child table '{$childTable}'",
                );
            }
        } elseif ($candidates === []) {
            throw new \LogicException(
                "{$where}: child table '{$childTable}' has no column referencing '{$lookupTable}' — declare refColumn",
            );
        } elseif (count($candidates) > 1) {
            throw new \LogicException(sprintf(
                "%s: child table '%s' has more columns referencing '%s' (%s) — declare refColumn",
                $where, $childTable, $lookupTable, implode(', ', $candidates),
            ));
        } else {
            $refColumn = $candidates[0];
        }

        return new self(
            column: $column,
            lookupTable: $lookupTable,
            childTable: $childTable,
            foreignKey: $foreignKey,
            refColumn: $refColumn,
            orderColumn: isset($columnIds[self::ORDER_COLUMN]) && $refColumn !== self::ORDER_COLUMN
                ? self::ORDER_COLUMN
                : null,
        );
    }

    /**
     * Hodnota prvku z klienta → seznam kladných celých čísel, nebo `null`,
     * když to seznam id není (jiný typ, nula, záporné číslo, text, desetinné
     * číslo). Číslo zapsané jako text (`"12"`) se bere — id z lookupu může
     * přijít jako řetězec. Duplicity nechává být, hlásí je `firstDuplicate()`.
     *
     * @return list<int>|null
     */
    public static function parseIds(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        $ids = [];
        foreach ($value as $item) {
            if (is_string($item) && preg_match('/^[1-9][0-9]*$/', $item) === 1) {
                $item = (int) $item;
            }
            if (!is_int($item) || $item <= 0) {
                return null;
            }
            $ids[] = $item;
        }
        return $ids;
    }

    /**
     * První id, které je v seznamu podruhé (D5), nebo `null`.
     *
     * @param list<int> $ids
     */
    public static function firstDuplicate(array $ids): ?int
    {
        $seen = [];
        foreach ($ids as $id) {
            if (isset($seen[$id])) {
                return $id;
            }
            $seen[$id] = true;
        }
        return null;
    }

    /**
     * Řádky child tabulky → seznam id odkazovaných záznamů v pořadí
     * (`order_pos`, pak `id` řádku). Řádek bez odkazu se přeskočí; odkaz,
     * který je v řádcích víckrát (data mimo formulář), se vrátí jednou.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    public function idsFromRows(array $rows): array
    {
        $ids = [];
        foreach ($this->sortRows($rows) as $row) {
            $ref = (int) ($row[$this->refColumn] ?? 0);
            if ($ref > 0 && !in_array($ref, $ids, true)) {
                $ids[] = $ref;
            }
        }
        return $ids;
    }

    /**
     * Seznam id → řádky pro synchronizaci gatewaye (D3): odkaz, který už
     * řádek má, si nechá jeho `id` (mění se nejvýš pořadí), nový je bez `id`,
     * chybějící v seznamu vypadne a gateway ho smaže. Pořadí se čísluje od 1
     * u všech řádků. `id` se bere **jen** z `$existingRows` načtených
     * serverem — nikdy z klienta.
     *
     * @param list<int> $ids seznam bez duplicit
     * @param list<array<string, mixed>> $existingRows řádky child tabulky rodiče
     * @return list<array<string, int>>
     */
    public function toRows(array $ids, array $existingRows): array
    {
        $rowIdByRef = [];
        foreach ($this->sortRows($existingRows) as $row) {
            $ref = (int) ($row[$this->refColumn] ?? 0);
            $rowId = (int) ($row['id'] ?? 0);
            if ($ref > 0 && $rowId > 0 && !isset($rowIdByRef[$ref])) {
                $rowIdByRef[$ref] = $rowId;
            }
        }

        $rows = [];
        $position = 0;
        foreach ($ids as $id) {
            $position++;
            $row = [];
            if (isset($rowIdByRef[$id])) {
                $row['id'] = $rowIdByRef[$id];
            }
            $row[$this->refColumn] = $id;
            if ($this->orderColumn !== null) {
                $row[$this->orderColumn] = $position;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function sortRows(array $rows): array
    {
        $orderColumn = $this->orderColumn;
        usort($rows, static function (array $a, array $b) use ($orderColumn): int {
            if ($orderColumn !== null) {
                $byOrder = (int) ($a[$orderColumn] ?? 0) <=> (int) ($b[$orderColumn] ?? 0);
                if ($byOrder !== 0) {
                    return $byOrder;
                }
            }
            return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
        });
        return $rows;
    }
}
