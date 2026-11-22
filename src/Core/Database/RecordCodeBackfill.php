<?php

declare(strict_types=1);

namespace Shipard\Core\Database;

use Dibi\Connection;
use Dibi\UniqueConstraintViolationException;

/**
 * Doplnění kódu ({@see RecordCode}) záznamům, které ho nemají — řádkům
 * z doby před zavedením sloupce a řádkům vloženým mimo `Document`
 * (provisionery, import přes generické CRUD). Společné jádro služeb
 * doplnění jednotlivých tabulek (#166 D17).
 *
 * Idempotentní: bere jen řádky s prázdným kódem a zapisuje podmíněně, takže
 * druhý běh nic nenajde a souběžný běh týž řádek nepřepíše. Pořadí: živé
 * řádky podle id, až po nich koš — živé tak dostanou „hezké“ kódy (`invno`,
 * `cz`), řádek v koši příponu.
 *
 * Běží i uprostřed cizí operace (líné založení řady při apply dokladu):
 * neotevírá vlastní transakci a porušení unikátního indexu — kód mezitím
 * obsadil souběžný zápis — není chyba, zkusí další volný kód.
 */
final class RecordCodeBackfill
{
    private const DELETED = 90;
    /** Pojistka proti nekonečné smyčce; řádek zůstane bez kódu do dalšího běhu. */
    private const MAX_ATTEMPTS = 50;

    /**
     * @param string $table tabulka se sloupci `id`, `code` a `docState`
     * @param list<string> $columns další sloupce řádku, které generátor čte
     * @param \Closure(array<string, mixed>, array<string, true>): string $generate
     *        kód pro řádek; druhý argument jsou kódy obsazené nad rámec
     *        toho, co generátor vidí v databázi ({@see RecordCode::key()})
     * @return int počet doplněných kódů
     */
    public static function run(Connection $db, string $table, array $columns, \Closure $generate): int
    {
        $select = implode(', ', array_map(
            static fn(string $column): string => "[{$column}]",
            ['id', 'docState', ...$columns],
        ));
        $rows = $db->fetchAll(
            "SELECT {$select} FROM %n WHERE [code] IS NULL OR [code] = '' ORDER BY ([docState] = %i), [id]",
            $table,
            self::DELETED,
        );

        $filled = 0;
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : $row->toArray();
            $alsoTaken = [];
            for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
                $code = $generate($row, $alsoTaken);
                try {
                    $db->update($table, ['code' => $code])
                        ->where("[id] = %i AND ([code] IS NULL OR [code] = '')", (int) $row['id'])
                        ->execute();
                    $filled += (int) $db->getAffectedRows();
                    break;
                } catch (UniqueConstraintViolationException) {
                    // Souběžný zápis kód obsadil; uvnitř transakce ho čtení
                    // obsazených kódů vidět nemusí, proto si ho pamatujeme.
                    $alsoTaken[RecordCode::key($code)] = true;
                }
            }
        }
        return $filled;
    }
}
