<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Resolve;

use Dibi\Connection;
use Shipard\Core\Database\TableDefinition;

/**
 * Jediné hledání záznamu přirozeným klíčem (#166 D8–D10). Klíč říká
 * definice tabulky (`exchangeKey`, `exchangeLookupColumns`); resolver ho
 * jen najde:
 *
 *  - přesná shoda v collation sloupce, hodnota se nijak neupravuje;
 *  - žádný záznam = `reference_not_found`, víc než jeden =
 *    `reference_ambiguous` — nikdy první nalezený;
 *  - záznam v koši (`docState` 90, jen u tabulky s `docStates`) se
 *    nepočítá ani při hledání primárním klíčem; archivovaný ano.
 *
 * Tabulka i sloupec jdou do SQL jen z definice, hodnota vždy parametrem.
 * Nalezená id si instance pamatuje (nenalezené ne — záznam může během
 * seedu teprve vzniknout).
 */
final class ReferenceResolver
{
    private const TRASH = 90;

    /** @var array<string, array<string, array<string, int>>> tabulka → sloupec → hodnota → id */
    private array $found = [];

    /** @var array<string, array<int, ?string>> tabulka → id → přirozený klíč */
    private array $keys = [];

    /**
     * @param array<string, TableDefinition> $tables aktivní tabulky zdroje dat
     */
    public function __construct(
        private readonly Connection $db,
        private readonly array $tables,
    ) {}

    /** Má tabulka přirozený klíč? Neznámá tabulka ho nemá. */
    public function hasKey(string $table): bool
    {
        return $this->keyColumn($table) !== null;
    }

    /** Sloupec přirozeného klíče tabulky; null = tabulka ho nemá nebo není aktivní. */
    public function keyColumn(string $table): ?string
    {
        return ($this->tables[$table] ?? null)?->exchangeKey;
    }

    /**
     * Id záznamu podle hodnoty reference z JSON ({@see ReferenceValue}).
     *
     * @throws ReferenceException
     */
    public function resolve(string $table, mixed $value): int
    {
        $def = $this->definition($table);
        try {
            $reference = ReferenceValue::parse($value, $def);
        } catch (ReferenceException $e) {
            throw $e->forTable($table);
        }

        return match ($reference->kind) {
            ReferenceValue::KIND_ID     => $this->findById($table, $def, (int) $reference->value),
            ReferenceValue::KIND_COLUMN => $this->resolveByColumn($table, (string) $reference->column, (string) $reference->value),
            default                     => $this->resolveByKey($table, (string) $reference->value),
        };
    }

    /**
     * Id záznamu podle holé hodnoty přirozeného klíče — pro volající,
     * jejichž soubory nesou klíč bez `@` (datové sady, dimenze dokladů).
     *
     * @throws ReferenceException
     */
    public function resolveByKey(string $table, string $value): int
    {
        $def = $this->definition($table);
        if ($def->exchangeKey === null) {
            throw ReferenceException::noKey($table);
        }
        return $this->findByColumn($table, $def, $def->exchangeKey, $value);
    }

    /**
     * Id záznamu podle sloupce, který tabulka pro hledání deklaruje
     * (`exchangeKey` nebo `exchangeLookupColumns`).
     *
     * @throws ReferenceException
     */
    public function resolveByColumn(string $table, string $column, string $value): int
    {
        $def = $this->definition($table);
        if (!$def->isExchangeLookupColumn($column)) {
            throw ReferenceException::columnNotAllowed($table, $column);
        }
        return $this->findByColumn($table, $def, $column, $value);
    }

    /**
     * Hodnota přirozeného klíče záznamu (export). Null = záznam není nebo
     * má klíč prázdný. Koš se tu nefiltruje — odkaz na smazaný záznam
     * zůstává v exportu čitelný a odmítne ho až hledání.
     *
     * @throws ReferenceException neznámá tabulka nebo tabulka bez klíče
     */
    public function keyOf(string $table, int $id): ?string
    {
        $def = $this->definition($table);
        if ($def->exchangeKey === null) {
            throw ReferenceException::noKey($table);
        }
        if (array_key_exists($id, $this->keys[$table] ?? [])) {
            return $this->keys[$table][$id];
        }

        $column = $def->exchangeKey;
        $row = $this->db->fetch("SELECT [{$column}] FROM [{$table}] WHERE [id] = %i", $id);
        $value = $row === null ? null : (is_array($row) ? $row : $row->toArray())[$column] ?? null;
        $key = is_scalar($value) && (string) $value !== '' ? (string) $value : null;

        return $this->keys[$table][$id] = $key;
    }

    private function definition(string $table): TableDefinition
    {
        return $this->tables[$table] ?? throw ReferenceException::tableUnknown($table);
    }

    private function findById(string $table, TableDefinition $def, int $id): int
    {
        $ids = $this->fetchIds(
            "SELECT [id] FROM [{$table}] WHERE [id] = %i" . self::notDeleted($def),
            $id,
        );
        if ($ids === []) {
            throw ReferenceException::notFound($table, null, $id);
        }
        return $ids[0];
    }

    private function findByColumn(string $table, TableDefinition $def, string $column, string $value): int
    {
        if ($value === '') {
            throw ReferenceException::invalid('Hodnota reference nesmí být prázdná.', $value, $table);
        }
        if (isset($this->found[$table][$column][$value])) {
            return $this->found[$table][$column][$value];
        }

        $ids = $this->fetchIds(
            "SELECT [id] FROM [{$table}] WHERE [{$column}] = %s" . self::notDeleted($def) . ' ORDER BY [id]',
            $value,
        );
        if ($ids === []) {
            throw ReferenceException::notFound($table, $column, $value);
        }
        if (count($ids) > 1) {
            throw ReferenceException::ambiguous($table, $column, $value, $ids);
        }
        return $this->found[$table][$column][$value] = $ids[0];
    }

    private static function notDeleted(TableDefinition $def): string
    {
        return $def->docStates !== null ? " AND [{$def->docStates->stateColumn}] <> " . self::TRASH : '';
    }

    /** @return list<int> */
    private function fetchIds(string $sql, mixed ...$args): array
    {
        $out = [];
        foreach ($this->db->fetchAll($sql, ...$args) as $row) {
            $row = is_array($row) ? $row : $row->toArray();
            $out[] = (int) $row['id'];
        }
        return $out;
    }
}
