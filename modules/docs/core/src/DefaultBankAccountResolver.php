<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Dibi\Connection;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Výchozí vlastní bankovní účet dokladu: účet s `is_default` ve stavu
 * V pořádku bez ohledu na měnu dokladu (FPB v EUR z českého účtu je
 * běžná). Jedno místo pro formulář nového dokladu (DocsHeadsFormBase —
 * DataSourceConnection) i pro generátory dokladů, které vlastní účet
 * posílají applieru (`applyOptions.importOwnBankAccount`, periodická
 * fakturace #110 Q5 — Dibi).
 */
final class DefaultBankAccountResolver
{
    private const SQL = 'SELECT `id` FROM `economy_codebooks_bank_accounts`'
        . ' WHERE `is_default` = 1 AND `docState` = 40'
        . ' ORDER BY `sort_order` ASC, `id` ASC LIMIT 1';

    public static function resolve(Connection|DataSourceConnection $db): ?int
    {
        if ($db instanceof DataSourceConnection) {
            $row = $db->fetchRow(self::SQL);
            return $row !== null ? (int) $row['id'] : null;
        }
        $id = $db->fetchSingle(self::SQL);
        return $id !== null && $id !== false ? (int) $id : null;
    }
}
