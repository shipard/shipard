<?php

declare(strict_types=1);

namespace Shipard\Core\Numbering;

/**
 * Pořadí v číselné řadě nad tabulkou čítačů (#110 D17) — společné dokladům
 * a zakázkám, tabulky a sloupce dává {@see SequenceStorage}.
 *
 * Řádek čítače je klíčovaný (řada, rozsah); rozsah NULL = průběžná řada.
 * UNIQUE v MariaDB bere NULL jako různé hodnoty, proto všechny příkazy
 * porovnávají rozsah NULL-safe (`<=>`) a init řádku je dvoukrokový
 * (`INSERT IGNORE`, pak UPDATE) — `ON DUPLICATE KEY` by pro NULL nevystřelil.
 *
 * Transakce: přidělení a uvolnění otevírají vlastní, pokud volající
 * nedrží vnější (exchange Applier přes TransactionlessTableGateway —
 * druhý `begin()` by ji v MariaDB implicitně commitnul). `FOR UPDATE`
 * zámek funguje ve vnější transakci stejně, jen se drží do jejího commitu.
 *
 * Zápisy jdou přes executor (výchozí `Connection::query`), aby je dokument
 * mohl vést svým `executeSql` — `query()` je final a testy ho nemockují.
 */
final class SequenceCounter
{
    /** @var \Closure(mixed ...$args): void */
    private \Closure $execute;

    /** @param \Closure(mixed ...$args): void|null $execute */
    public function __construct(
        private readonly \Dibi\Connection $db,
        private readonly SequenceStorage $storage,
        ?\Closure $execute = null,
    ) {
        $this->execute = $execute ?? static function (mixed ...$args) use ($db): void {
            $db->query(...$args);
        };
    }

    /** Další pořadí v řadě: init řádku, zámek, zvýšení. */
    public function next(int $series, ?int $scope, bool $ownTransaction = true): int
    {
        $s = $this->storage;
        if ($ownTransaction) {
            $this->db->begin();
        }
        try {
            ($this->execute)(
                "INSERT IGNORE INTO [{$s->countersTable}]
                 ([{$s->counterSeriesColumn}], [{$s->counterScopeColumn}], [{$s->counterValueColumn}])
                 VALUES (%i, %iN, 0)",
                $series, $scope,
            );

            $row = $this->db->fetch(
                "SELECT [{$s->counterValueColumn}] FROM [{$s->countersTable}]
                 WHERE [{$s->counterSeriesColumn}] = %i AND [{$s->counterScopeColumn}] <=> %iN
                 FOR UPDATE",
                $series, $scope,
            );
            $next = (int) ($row[$s->counterValueColumn] ?? 0) + 1;

            ($this->execute)(
                "UPDATE [{$s->countersTable}]
                 SET [{$s->counterValueColumn}] = %i
                 WHERE [{$s->counterSeriesColumn}] = %i AND [{$s->counterScopeColumn}] <=> %iN",
                $next, $series, $scope,
            );

            if ($ownTransaction) {
                $this->db->commit();
            }
            return $next;
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    /**
     * Posun čítače na importované pořadí přes GREATEST: idempotentní
     * (opakovaný import nikdy čítač nesníží), nezávislé na pořadí importu
     * (7, pak 3 → 7) a snáší díry po smazaných zdrojových záznamech.
     * Bez transakce — volá se uvnitř uložení záznamu.
     */
    public function syncImported(int $series, ?int $scope, int $sequence): void
    {
        $s = $this->storage;
        ($this->execute)(
            "INSERT IGNORE INTO [{$s->countersTable}]
                ([{$s->counterSeriesColumn}], [{$s->counterScopeColumn}], [{$s->counterValueColumn}])
             VALUES (%i, %iN, 0)",
            $series, $scope,
        );
        ($this->execute)(
            "UPDATE [{$s->countersTable}]
             SET [{$s->counterValueColumn}] = GREATEST([{$s->counterValueColumn}], %i)
             WHERE [{$s->counterSeriesColumn}] = %i AND [{$s->counterScopeColumn}] <=> %iN",
            $sequence, $series, $scope,
        );
    }

    /** Nejvyšší přidělené pořadí v tabulce záznamů — guard „poslední v řadě“; 0 bez záznamů. */
    public function maxSequence(int $series, ?int $scope): int
    {
        $s = $this->storage;
        $row = $this->db->fetch(
            "SELECT MAX([{$s->recordSequenceColumn}]) AS [max_seq]
             FROM [{$s->recordsTable}]
             WHERE [{$s->recordSeriesColumn}] = %i AND [{$s->recordScopeColumn}] <=> %iN",
            $series, $scope,
        );
        return (int) ($row['max_seq'] ?? 0);
    }

    /** Vrácení pořadí: čítač klesne jen, když na něm uvolňované pořadí právě je. */
    public function release(int $series, ?int $scope, int $sequence, bool $ownTransaction = true): void
    {
        $s = $this->storage;
        if ($ownTransaction) {
            $this->db->begin();
        }
        try {
            ($this->execute)(
                "UPDATE [{$s->countersTable}]
                 SET [{$s->counterValueColumn}] = [{$s->counterValueColumn}] - 1
                 WHERE [{$s->counterSeriesColumn}] = %i AND [{$s->counterScopeColumn}] <=> %iN AND [{$s->counterValueColumn}] = %i",
                $series, $scope, $sequence,
            );
            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                $this->db->rollback();
            }
            throw $e;
        }
    }
}
