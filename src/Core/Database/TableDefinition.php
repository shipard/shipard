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
    ) {}

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
        );
    }
}
