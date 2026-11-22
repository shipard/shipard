<?php

declare(strict_types=1);

namespace Shipard\Core\Database;

use Shipard\Core\Document\DocStatesDefinition;

class TableDefinition
{
    public function __construct(
        public readonly int $tableId,
        public readonly string $name,
        public readonly ?string $displayPattern,
        public readonly array $columnGroups,
        public readonly array $columns,
        public readonly array $indexes,
        public readonly array $childTables,
        public readonly ?DocStatesDefinition $docStates,
        public readonly bool $stateTransitionsRunDocumentHooks = false,
        public readonly bool $adminOnly = false,
        /**
         * Záznamy zakládá a ruší jen aplikace (služba, import) — generické
         * CRUD odmítne POST i DELETE. Úpravy existujícího záznamu dál řídí
         * stavy dokumentu.
         */
        public readonly bool $systemManaged = false,
        /**
         * Přirozený klíč záznamu (#166 D8): id sloupce, kterým se záznam
         * odkazuje ve výměnných formátech a datových sadách. Hledá přes něj
         * jediný obecný resolver; jednoznačnost se ověřuje až při hledání.
         */
        public readonly ?string $exchangeKey = null,
        /**
         * Další sloupce, přes které smí hledat jmenovaná reference
         * (`"@sloupec:hodnota"`, #166 D10). `exchangeKey` je povolený vždy
         * a do seznamu se neuvádí.
         *
         * @var list<string>
         */
        public readonly array $exchangeLookupColumns = [],
    ) {}

    /** Smí jmenovaná reference hledat přes tento sloupec? */
    public function isExchangeLookupColumn(string $column): bool
    {
        return $column === $this->exchangeKey
            || in_array($column, $this->exchangeLookupColumns, true);
    }

    /** @return string[] Column ids flagged "sensitive": true */
    public function getSensitiveColumns(): array
    {
        $result = [];
        foreach ($this->columns as $col) {
            if ($col->sensitive) {
                $result[] = $col->id;
            }
        }
        return $result;
    }

    /**
     * Sloupce se strukturovaným schématem (#74) — id sloupce => cfgItem
     * se schématem. Jediný zdroj pravdy pro zápisovou cestu
     * (`TableGateway`), formulář a `ds-upgrade`.
     *
     * @return array<string, string>
     */
    public function getStructuredColumns(): array
    {
        $result = [];
        foreach ($this->columns as $col) {
            if ($col->schema !== null) {
                $result[$col->id] = $col->schema;
            }
        }
        return $result;
    }

    public static function fromArray(array $data): self
    {
        if (!isset($data['tableId']) || !is_int($data['tableId']) || $data['tableId'] <= 0) {
            throw new \InvalidArgumentException('Table definition missing required field: tableId (must be positive int)');
        }

        if (!isset($data['name']) || !is_string($data['name']) || $data['name'] === '') {
            throw new \InvalidArgumentException('Table definition missing required field: name');
        }

        if (!isset($data['columns']) || !is_array($data['columns']) || count($data['columns']) === 0) {
            throw new \InvalidArgumentException('Table definition missing required field: columns (must be non-empty array)');
        }

        $columns = array_map(
            fn(array $col) => ColumnDefinition::fromArray($col),
            $data['columns'],
        );

        $primaryKeys = array_filter($columns, fn(ColumnDefinition $c) => $c->primaryKey);
        if (count($primaryKeys) === 0) {
            throw new \InvalidArgumentException("Table '{$data['name']}': must have exactly one column with primaryKey=true (found none)");
        }
        if (count($primaryKeys) > 1) {
            throw new \InvalidArgumentException("Table '{$data['name']}': must have exactly one column with primaryKey=true (found " . count($primaryKeys) . ')');
        }

        $indexes = array_map(
            fn(array $idx) => IndexDefinition::fromArray($idx),
            $data['indexes'] ?? [],
        );

        [$exchangeKey, $exchangeLookupColumns] = self::exchangeColumns($data, $columns);

        return new self(
            tableId: $data['tableId'],
            name: $data['name'],
            displayPattern: isset($data['displayPattern']) && is_string($data['displayPattern'])
                ? $data['displayPattern']
                : null,
            columnGroups: $data['columnGroups'] ?? [],
            columns: $columns,
            indexes: $indexes,
            childTables: $data['childTables'] ?? [],
            docStates: isset($data['docStates']) && is_array($data['docStates'])
                ? DocStatesDefinition::fromArray($data['docStates'])
                : null,
            stateTransitionsRunDocumentHooks: (bool) ($data['stateTransitionsRunDocumentHooks'] ?? false),
            adminOnly: (bool) ($data['adminOnly'] ?? false),
            systemManaged: (bool) ($data['systemManaged'] ?? false),
            exchangeKey: $exchangeKey,
            exchangeLookupColumns: $exchangeLookupColumns,
        );
    }

    /** Typy sloupce, který smí být přirozeným klíčem nebo sloupcem pro hledání. */
    private const EXCHANGE_COLUMN_TYPES = ['varchar', 'tinyint', 'smallint', 'int', 'bigint'];

    /**
     * `exchangeKey` a `exchangeLookupColumns` (#166 D8, D10): sloupec musí
     * existovat, nesmí být primární klíč ani citlivý a musí být řetězcový
     * nebo celočíselný — jde do SQL podmínky a jeho hodnota do souborů.
     *
     * @param array<string, mixed>   $data
     * @param list<ColumnDefinition> $columns
     * @return array{0: ?string, 1: list<string>}
     */
    private static function exchangeColumns(array $data, array $columns): array
    {
        $table = (string) $data['name'];
        $byId = [];
        foreach ($columns as $column) {
            $byId[$column->id] = $column;
        }
        $check = static function (mixed $columnId, string $field) use ($table, $byId): string {
            if (!is_string($columnId) || $columnId === '') {
                throw new \InvalidArgumentException("Table '{$table}': {$field} must be a non-empty column id");
            }
            $column = $byId[$columnId] ?? null;
            if ($column === null) {
                throw new \InvalidArgumentException("Table '{$table}': {$field} refers to unknown column '{$columnId}'");
            }
            if ($column->primaryKey) {
                throw new \InvalidArgumentException("Table '{$table}': {$field} '{$columnId}' must not be the primary key");
            }
            if ($column->sensitive) {
                throw new \InvalidArgumentException("Table '{$table}': {$field} '{$columnId}' must not be a sensitive column");
            }
            if (!in_array($column->type, self::EXCHANGE_COLUMN_TYPES, true)) {
                throw new \InvalidArgumentException(
                    "Table '{$table}': {$field} '{$columnId}' must be a varchar or integer column, got '{$column->type}'",
                );
            }
            return $columnId;
        };

        $exchangeKey = array_key_exists('exchangeKey', $data) && $data['exchangeKey'] !== null
            ? $check($data['exchangeKey'], 'exchangeKey')
            : null;

        $lookupColumns = [];
        if (array_key_exists('exchangeLookupColumns', $data)) {
            if (!is_array($data['exchangeLookupColumns']) || !array_is_list($data['exchangeLookupColumns'])) {
                throw new \InvalidArgumentException("Table '{$table}': exchangeLookupColumns must be a list of column ids");
            }
            foreach ($data['exchangeLookupColumns'] as $columnId) {
                $columnId = $check($columnId, 'exchangeLookupColumns');
                if ($columnId === $exchangeKey) {
                    throw new \InvalidArgumentException(
                        "Table '{$table}': exchangeLookupColumns must not repeat exchangeKey '{$columnId}'",
                    );
                }
                if (in_array($columnId, $lookupColumns, true)) {
                    throw new \InvalidArgumentException(
                        "Table '{$table}': exchangeLookupColumns has duplicate column '{$columnId}'",
                    );
                }
                $lookupColumns[] = $columnId;
            }
        }

        return [$exchangeKey, $lookupColumns];
    }
}
