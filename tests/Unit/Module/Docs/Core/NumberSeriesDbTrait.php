<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;

/**
 * Databáze číselných řad v paměti pro testy kódu řady (#166 D17): odpovídá
 * na dotazy `NumberSeriesCodeGenerator` a `NumberSeriesDocument` nad
 * zadanými řádky řad a kódy pokladen / skladů.
 */
trait NumberSeriesDbTrait
{
    /**
     * @param list<array<string, mixed>> $series řádky `docs_core_number_series`
     * @param array<string, array<int, string>> $entities tabulka → id → kód entity
     */
    private function seriesDb(array $series = [], array $entities = []): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(static function (string $sql, mixed ...$args) use ($series, $entities): ?Row {
            if (str_contains($sql, 'MIN([id])')) {
                $ids = [];
                foreach ($series as $row) {
                    if (($row['doc_type'] ?? null) === $args[1] && (int) ($row['docState'] ?? 40) !== (int) $args[2]) {
                        $ids[] = (int) $row['id'];
                    }
                }
                return new Row(['first_id' => $ids === [] ? null : min($ids)]);
            }
            if (str_contains($sql, 'SELECT [id], [name], [docState]')) {
                foreach ($series as $row) {
                    if ((int) $row['id'] !== (int) $args[2]
                        && strtolower((string) ($row['code'] ?? '')) === strtolower((string) $args[1])
                    ) {
                        return new Row(['id' => $row['id'], 'name' => $row['name'] ?? '', 'docState' => $row['docState'] ?? 40]);
                    }
                }
                return null;
            }
            if (str_contains($sql, 'SELECT * FROM')) {
                foreach ($series as $row) {
                    if ((int) $row['id'] === (int) $args[1]) {
                        return new Row($row);
                    }
                }
                return null;
            }
            if (str_contains($sql, 'SELECT [code] FROM %n WHERE [id]')) {
                $code = $entities[(string) $args[0]][(int) $args[1]] ?? null;
                return $code === null ? null : new Row(['code' => $code]);
            }
            if (str_contains($sql, 'SELECT [docState] FROM [')) {
                // validateBinding: entita existuje a je V pořádku.
                return new Row(['docState' => 40]);
            }
            return null;
        });
        $db->method('fetchAll')->willReturnCallback(static function (string $sql, mixed ...$args) use ($series): array {
            $out = [];
            foreach ($series as $row) {
                if ((string) ($row['code'] ?? '') !== '' && (int) $row['id'] !== (int) $args[1]) {
                    $out[] = new Row(['code' => $row['code']]);
                }
            }
            return $out;
        });
        return $db;
    }
}
