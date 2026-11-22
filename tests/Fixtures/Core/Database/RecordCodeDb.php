<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Core\Database;

use Dibi\Connection;
use Dibi\Fluent;
use Dibi\Row;
use Dibi\UniqueConstraintViolationException;

/**
 * Tabulka s kódem záznamu (#166 D17) v paměti — odpovídá na dotazy
 * generátorů kódu, validace v Document třídách a služby doplnění
 * (`RecordCodeBackfill`), včetně unikátního indexu nad `code`, který jako
 * databáze nerozlišuje velikost písmen.
 *
 * Řádky drží `$store['rows']`; test je po běhu čte zpět. `$store['collide']`
 * = kódy, které při prvním zápisu „obsadil souběžný zápis“ (porušení
 * unikátního indexu, i když je čtení obsazených kódů nevidí).
 * `$store['updates']` = provedené zápisy kódu.
 */
trait RecordCodeDb
{
    /**
     * @param \ArrayObject<string, mixed> $store
     * @param string $groupColumn sloupec, v jehož rámci se hledá první
     *        záznam (`doc_type` řady, `country` registrace)
     * @param array<string, array<int, string>> $entities tabulka → id → kód entity
     */
    private function recordCodeDb(\ArrayObject $store, string $groupColumn, array $entities = []): Connection
    {
        $store['rows'] ??= [];
        $store['collide'] ??= [];
        $store['updates'] ??= [];
        $store['affected'] = 0;

        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(static function (string $sql, mixed ...$args) use ($store, $groupColumn, $entities): ?Row {
            if (str_contains($sql, 'MIN([id])')) {
                $ids = [];
                foreach ($store['rows'] as $row) {
                    if (($row[$groupColumn] ?? null) === $args[1] && (int) ($row['docState'] ?? 40) !== (int) $args[2]) {
                        $ids[] = (int) $row['id'];
                    }
                }
                return new Row(['first_id' => $ids === [] ? null : min($ids)]);
            }
            if (str_contains($sql, 'SELECT [id], [name], [docState]')) {
                foreach ($store['rows'] as $row) {
                    if ((int) $row['id'] !== (int) $args[2]
                        && strtolower((string) ($row['code'] ?? '')) === strtolower((string) $args[1])
                    ) {
                        return new Row(['id' => $row['id'], 'name' => $row['name'] ?? '', 'docState' => $row['docState'] ?? 40]);
                    }
                }
                return null;
            }
            if (str_contains($sql, 'SELECT * FROM')) {
                foreach ($store['rows'] as $row) {
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
                // Vazba řady: entita existuje a je V pořádku.
                return new Row(['docState' => 40]);
            }
            return null;
        });

        $db->method('fetchAll')->willReturnCallback(static function (string $sql, mixed ...$args) use ($store): array {
            if (str_contains($sql, '[code] IS NULL OR')) {
                // Řádky bez kódu: živé podle id, pak koš.
                $rows = array_values(array_filter(
                    $store['rows'],
                    static fn(array $row): bool => (string) ($row['code'] ?? '') === '',
                ));
                usort($rows, static fn(array $a, array $b): int => [(int) ($a['docState'] ?? 40) === (int) $args[1], (int) $a['id']]
                    <=> [(int) ($b['docState'] ?? 40) === (int) $args[1], (int) $b['id']]);
                return array_map(static fn(array $row): Row => new Row($row + ['docState' => 40]), $rows);
            }
            // Obsazené kódy ostatních řádků.
            $out = [];
            foreach ($store['rows'] as $row) {
                if ((string) ($row['code'] ?? '') !== '' && (int) $row['id'] !== (int) $args[1]) {
                    $out[] = new Row(['code' => $row['code']]);
                }
            }
            return $out;
        });

        // Zápis kódu: `update(tabulka, [code])->where(podmínka, id)->execute()`.
        $db->method('update')->willReturnCallback(function (string $table, iterable $values) use ($store): Fluent {
            $code = (string) ([...$values]['code'] ?? '');
            $id = 0;
            $fluent = $this->createMock(Fluent::class);
            $fluent->method('__call')->willReturnCallback(static function (string $clause, array $args) use (&$id, $fluent): Fluent {
                if ($clause === 'where') {
                    $id = (int) ($args[1] ?? 0);
                }
                return $fluent;
            });
            $fluent->method('execute')->willReturnCallback(static function () use ($store, $code, &$id): null {
                $key = strtolower($code);
                $collide = $store['collide'];
                $at = array_search($key, $collide, true);
                if ($at !== false) {
                    unset($collide[$at]);
                    $store['collide'] = array_values($collide);
                    throw new UniqueConstraintViolationException("Duplicate entry '{$code}' for key 'unq_code'", 1062);
                }
                $rows = $store['rows'];
                foreach ($rows as $row) {
                    if ((int) $row['id'] !== $id && strtolower((string) ($row['code'] ?? '')) === $key) {
                        throw new UniqueConstraintViolationException("Duplicate entry '{$code}' for key 'unq_code'", 1062);
                    }
                }
                $affected = 0;
                foreach ($rows as $i => $row) {
                    if ((int) $row['id'] === $id && (string) ($row['code'] ?? '') === '') {
                        $rows[$i]['code'] = $code;
                        $affected++;
                    }
                }
                $store['rows'] = $rows;
                $store['affected'] = $affected;
                $store['updates'] = [...$store['updates'], [$id, $code]];
                return null;
            });
            return $fluent;
        });
        $db->method('getAffectedRows')->willReturnCallback(static fn(): int => (int) $store['affected']);

        return $db;
    }

    /**
     * @param \ArrayObject<string, mixed> $store
     * @return array<int, ?string> id → kód
     */
    private static function codesOf(\ArrayObject $store): array
    {
        $out = [];
        foreach ($store['rows'] as $row) {
            $out[(int) $row['id']] = $row['code'] ?? null;
        }
        return $out;
    }
}
