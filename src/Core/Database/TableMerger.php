<?php

declare(strict_types=1);

namespace Shipard\Core\Database;

class TableMerger
{
    public static function merge(TableDefinition $base, ExtensionDefinition $extension): TableDefinition
    {
        $columns = $base->columns;
        $baseColumnIds = array_map(fn(ColumnDefinition $c) => $c->id, $columns);

        foreach ($extension->columns as $col) {
            if (in_array($col->id, $baseColumnIds, true)) {
                throw new \InvalidArgumentException(
                    "Extension column '{$col->id}' already exists in table '{$base->name}'"
                );
            }
            $columns[] = $col;
            $baseColumnIds[] = $col->id;
        }

        $columnGroups = $base->columnGroups;
        $baseGroupIds = array_map(fn(array $g) => $g['id'] ?? null, $columnGroups);

        foreach ($extension->columnGroups as $group) {
            $groupId = $group['id'] ?? null;
            if ($groupId !== null && in_array($groupId, $baseGroupIds, true)) {
                continue;
            }
            $columnGroups[] = $group;
            $baseGroupIds[] = $groupId;
        }

        $indexes = $base->indexes;
        $baseIndexIds = array_map(fn(IndexDefinition $i) => $i->id, $indexes);

        foreach ($extension->indexes as $idx) {
            if (in_array($idx->id, $baseIndexIds, true)) {
                throw new \InvalidArgumentException(
                    "Extension index '{$idx->id}' already exists in table '{$base->name}'"
                );
            }
            $indexes[] = $idx;
            $baseIndexIds[] = $idx->id;
        }

        return new TableDefinition(
            tableId: $base->tableId,
            name: $base->name,
            displayPattern: $base->displayPattern,
            columnGroups: $columnGroups,
            columns: $columns,
            indexes: $indexes,
            childTables: $base->childTables,
            docStates: $base->docStates,
            stateTransitionsRunDocumentHooks: $base->stateTransitionsRunDocumentHooks,
            adminOnly: $base->adminOnly,
            systemManaged: $base->systemManaged,
        );
    }
}
