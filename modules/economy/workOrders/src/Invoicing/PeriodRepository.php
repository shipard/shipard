<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Invoicing;

use Dibi\Connection;

/**
 * Evidence období periodické fakturace (`economy_work_orders_periods`,
 * docs/work-orders.md D5): jediný zápis do tabulky. Stavy planned → waiting
 * → issued; „zastaveno“ je odvozené (`isStopped`: issued období, jehož
 * doklad je Smazaný nebo chybí). Zápisy jdou přes `execute()` (Dibi
 * `query` je final — seam pro testy).
 */
class PeriodRepository
{
    public const TABLE = 'economy_work_orders_periods';

    public const STATE_PLANNED = 'planned';
    public const STATE_WAITING = 'waiting';
    public const STATE_ISSUED = 'issued';
    /** Odvozený stav pro UI, neukládá se. */
    public const STATE_STOPPED = 'stopped';

    public const RESULT_FAILED = 'failed';
    public const RESULT_NO_ROWS = 'no_rows';
    public const RESULT_CATCHUP = 'catchup';
    public const RESULT_WAITING = 'waiting';
    public const RESULT_EDITED = 'edited';

    private const DOC_STATE_DELETED = 90;

    public function __construct(protected readonly Connection $db)
    {
    }

    /**
     * Období zakázky, nejnovější nahoře.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(int $workOrderId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM [' . self::TABLE . '] WHERE [work_order] = %i ORDER BY [period_from] DESC, [id] DESC',
            $workOrderId,
        );
        return array_map(static fn($r): array => is_array($r) ? $r : $r->toArray(), $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetch('SELECT * FROM [' . self::TABLE . '] WHERE [id] = %i', $id);
        return $row === null ? null : (is_array($row) ? $row : $row->toArray());
    }

    /**
     * Založí chybějící období jako `planned`; existující (UNIQUE zakázka ×
     * začátek) nechá beze změny — souběh dvou běhů tak nevyrobí duplikát.
     *
     * @param list<Period> $periods
     */
    public function ensurePlanned(int $workOrderId, array $periods, string $now): void
    {
        foreach ($periods as $period) {
            $this->execute(
                'INSERT IGNORE INTO [' . self::TABLE . '] %v',
                [
                    'work_order'  => $workOrderId,
                    'period_from' => $period->from,
                    'period_to'   => $period->to,
                    'state'       => self::STATE_PLANNED,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
            );
        }
    }

    /**
     * Řádek období zamčený do konce transakce (druhý běh na něj čeká
     * a po odemčení vidí už vystavené období).
     *
     * @return array<string, mixed>|null
     */
    public function lockForUpdate(int $id): ?array
    {
        $row = $this->db->fetch('SELECT * FROM [' . self::TABLE . '] WHERE [id] = %i FOR UPDATE', $id);
        return $row === null ? null : (is_array($row) ? $row : $row->toArray());
    }

    public function markIssued(int $id, int $docId, string $contentHash, string $now): void
    {
        $this->update($id, [
            'state'         => self::STATE_ISSUED,
            'doc'           => $docId,
            'content_hash'  => $contentHash,
            'result'        => null,
            'message'       => null,
            'waiting_since' => null,
            'updated_at'    => $now,
        ]);
    }

    /** Koncept vznikl, ale čeká na podklady přispěvatele (D10). */
    public function markWaiting(int $id, int $docId, string $contentHash, ?string $message, string $now, ?string $waitingSince): void
    {
        $this->update($id, [
            'state'         => self::STATE_WAITING,
            'doc'           => $docId,
            'content_hash'  => $contentHash,
            'result'        => self::RESULT_WAITING,
            'message'       => $message,
            'waiting_since' => $waitingSince ?? $now,
            'updated_at'    => $now,
        ]);
    }

    /** Období zůstává bez dokladu; `result` + `message` říkají proč. */
    public function markPlanned(int $id, ?string $result, ?string $message, string $now): void
    {
        $this->update($id, [
            'state'      => self::STATE_PLANNED,
            'doc'        => null,
            'result'     => $result,
            'message'    => $message,
            'updated_at' => $now,
        ]);
    }

    /** Jen výsledek posledního běhu (stav a doklad zůstávají). */
    public function markResult(int $id, ?string $result, ?string $message, string $now): void
    {
        $this->update($id, [
            'result'     => $result,
            'message'    => $message,
            'updated_at' => $now,
        ]);
    }

    /** Obnovit (D24): zruší vazbu na smazaný doklad, období se vrátí do `planned`. */
    public function unlinkDoc(int $id, string $now): void
    {
        $this->update($id, [
            'state'         => self::STATE_PLANNED,
            'doc'           => null,
            'content_hash'  => null,
            'result'        => null,
            'message'       => null,
            'waiting_since' => null,
            'updated_at'    => $now,
        ]);
    }

    /**
     * Doklady období (stav, typ, číslo, částka) pro odvození „zastaveno“
     * a záložku Fakturace.
     *
     * @param list<int> $docIds
     * @return array<int, array<string, mixed>> id dokladu → řádek
     */
    public function docInfo(array $docIds): array
    {
        $docIds = array_values(array_unique(array_filter(array_map('intval', $docIds), static fn(int $id): bool => $id > 0)));
        if ($docIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAll(
            'SELECT [id], [docState], [doc_type], [doc_number], [total_amount], [doc_currency]'
            . ' FROM [docs_core_heads] WHERE [id] IN %in',
            $docIds,
        ) as $row) {
            $row = is_array($row) ? $row : $row->toArray();
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    /**
     * Zastaveno = vystavené období, jehož doklad je v koši nebo neexistuje
     * (D5, D10). Obnovení dokladu z koše tím samo „odzastaví“.
     *
     * @param array<string, mixed> $period
     * @param array<int, array<string, mixed>> $docInfo výsledek docInfo()
     */
    public static function isStopped(array $period, array $docInfo): bool
    {
        if ((string) ($period['state'] ?? '') !== self::STATE_ISSUED) {
            return false;
        }
        $docId = (int) ($period['doc'] ?? 0);
        $doc = $docInfo[$docId] ?? null;
        return $doc === null || (int) ($doc['docState'] ?? 0) === self::DOC_STATE_DELETED;
    }

    /**
     * Stav pro UI vč. odvozeného `stopped`.
     *
     * @param array<string, mixed> $period
     * @param array<int, array<string, mixed>> $docInfo
     */
    public static function effectiveState(array $period, array $docInfo): string
    {
        return self::isStopped($period, $docInfo) ? self::STATE_STOPPED : (string) ($period['state'] ?? self::STATE_PLANNED);
    }

    /** @param array<string, mixed> $values */
    private function update(int $id, array $values): void
    {
        $this->execute('UPDATE [' . self::TABLE . '] SET %a WHERE [id] = %i', $values, $id);
    }

    /** Zápis (Dibi `query` je final — přepsatelné v testech). */
    protected function execute(string $sql, mixed ...$args): void
    {
        $this->db->query($sql, ...$args);
    }
}
