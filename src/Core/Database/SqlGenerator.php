<?php

declare(strict_types=1);

namespace Shipard\Core\Database;

class SqlGenerator
{
    private static function mapType(ColumnDefinition $col): string
    {
        return match ($col->type) {
            'tinyint'    => 'TINYINT',
            'smallint'   => 'SMALLINT',
            'int'        => 'INT',
            'bigint'     => 'BIGINT',
            'varchar'    => "VARCHAR({$col->length})",
            'text'       => 'TEXT',
            'mediumtext' => 'MEDIUMTEXT',
            'longtext'   => 'LONGTEXT',
            'numeric'    => "NUMERIC({$col->precision}, {$col->scale})",
            'float'      => 'FLOAT',
            'date'       => 'DATE',
            'datetime'   => 'DATETIME',
            'time'       => 'TIME',
            'boolean'    => 'TINYINT(1)',
            'json'       => 'JSON',
            'enumInt'    => 'SMALLINT',
            'enumString' => "CHAR({$col->length}) CHARACTER SET ascii",
            'encrypted_text' => 'TEXT',
            default      => throw new \InvalidArgumentException("Unknown column type: '{$col->type}'"),
        };
    }

    private static function generateColumnDef(ColumnDefinition $col): string
    {
        $sql = '`' . $col->id . '` ' . self::mapType($col);

        // Collation sloupce: znakovou sadu si MariaDB odvodí z názvu collation
        // (`utf8mb4_bin` → utf8mb4). Tvar a typ sloupce hlídá ColumnDefinition.
        if ($col->collation !== null) {
            $sql .= ' COLLATE ' . $col->collation;
        }

        $sql .= $col->nullable ? ' NULL' : ' NOT NULL';

        if ($col->default !== null) {
            if (is_string($col->default)) {
                $escaped = str_replace("'", "''", $col->default);
                $sql .= " DEFAULT '{$escaped}'";
            } else {
                $sql .= ' DEFAULT ' . ($col->default === true ? '1' : ($col->default === false ? '0' : $col->default));
            }
        }

        if ($col->autoIncrement) {
            $sql .= ' AUTO_INCREMENT';
        }

        return $sql;
    }

    public static function generateCreateTable(string $tableName, TableDefinition $definition): string
    {
        $parts = [];
        $pkCol = null;

        foreach ($definition->columns as $col) {
            $parts[] = '  ' . self::generateColumnDef($col);
            if ($col->primaryKey) {
                $pkCol = $col->id;
            }
        }

        $parts[] = "  PRIMARY KEY (`{$pkCol}`)";

        $colDefs = implode(",\n", $parts);

        return "CREATE TABLE `{$tableName}` (\n{$colDefs}\n) CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci";
    }

    public static function generateAddColumn(string $table, ColumnDefinition $column): string
    {
        return 'ALTER TABLE `' . $table . '` ADD COLUMN ' . self::generateColumnDef($column);
    }

    public static function generateModifyColumn(string $table, ColumnDefinition $column): string
    {
        return 'ALTER TABLE `' . $table . '` MODIFY COLUMN ' . self::generateColumnDef($column);
    }

    public static function generateCreateIndex(string $table, IndexDefinition $index): string
    {
        $keyword = match ($index->type) {
            'unique'   => 'UNIQUE ',
            'fulltext' => 'FULLTEXT ',
            default    => '',
        };

        $cols = implode(', ', array_map(
            fn(array $c) => '`' . $c['column'] . '` ' . $c['order'],
            $index->columns,
        ));

        return "CREATE {$keyword}INDEX `{$index->id}` ON `{$table}` ({$cols})";
    }
}
