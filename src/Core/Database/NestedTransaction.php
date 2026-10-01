<?php

declare(strict_types=1);

namespace Shipard\Core\Database;

/**
 * Transakce, která smí běžet i uvnitř cizí transakce.
 *
 * MariaDB vnořené transakce nemá — druhý `START TRANSACTION` vnější
 * transakci tiše commitne. Kód volaný z event handlerů (účtovací enginy,
 * saldo ledger) přitom neví, jestli ho volá formulář po commitu, nebo
 * služba s vlastní transakcí přes `TransactionlessTableGateway`. Helper to
 * zjistí z `@@in_transaction`: mimo transakci otevře vlastní, uvnitř
 * použije `SAVEPOINT` — rollback pak vrátí jen vlastní práci a vnější
 * transakce zůstává otevřená a v rukou volajícího.
 */
final class NestedTransaction
{
    private static int $sequence = 0;

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function run(\Dibi\Connection $db, callable $work): mixed
    {
        $savepoint = self::isActive($db) ? 'shpd_nested_' . (++self::$sequence) : null;

        $db->begin($savepoint);
        try {
            $result = $work();
            $db->commit($savepoint);
        } catch (\Throwable $e) {
            try {
                $db->rollback($savepoint);
            } catch (\Throwable) {
                // Server mohl celou transakci zrušit sám (deadlock) a savepoint
                // už neexistuje — původní výjimka je důležitější.
            }
            throw $e;
        }

        return $result;
    }

    /** Běží na spojení právě transakce? */
    public static function isActive(\Dibi\Connection $db): bool
    {
        return (int) $db->fetchSingle('SELECT @@in_transaction') === 1;
    }
}
