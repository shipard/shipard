<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Queue;

use Dibi\Connection;
use Shipard\Module\Economy\SalesRegistration\RegistrationDocument;

/**
 * Úložiště fronty tržeb — jediné SQL nad tabulkami tržeb a pokusů pro
 * {@see SaleQueue}. Časy dostává jako text z PHP (žádné `NOW()` v SQL),
 * ve stejné zóně, v jaké se ukládá čas přechodu dokladu.
 */
class SaleStore
{
    public const string SALES = 'economy_sales_registration_sales';
    public const string ATTEMPTS = 'economy_sales_registration_attempts';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Zámek odesílatele (D24): atomický přechod `pending` → `sending`.
     * False = tržbu už má jiný proces, nebo není `pending`.
     */
    public function claim(int $saleId, string $stamp): bool
    {
        $this->db->query(
            'UPDATE %n SET [state] = %s, [claimed_at] = %s WHERE [id] = %i AND [state] = %s',
            self::SALES,
            SaleState::SENDING,
            $stamp,
            $saleId,
            SaleState::PENDING,
        );
        return $this->db->getAffectedRows() === 1;
    }

    /** @return array<string, mixed>|null */
    public function sale(int $saleId): ?array
    {
        $row = $this->db->fetch('SELECT * FROM %n WHERE [id] = %i', self::SALES, $saleId);
        return $row === null ? null : iterator_to_array($row);
    }

    /** Řádek registrace včetně certifikátu a klíče — jen pro odesílač. @return array<string, mixed>|null */
    public function registration(int $registrationId): ?array
    {
        $row = $this->db->fetch('SELECT * FROM %n WHERE [id] = %i', RegistrationDocument::TABLE, $registrationId);
        return $row === null ? null : iterator_to_array($row);
    }

    /**
     * Má tržba pokus, který zprávu odeslal — nebo odeslat mohl? Přerušený
     * pokus (`interrupted`) se bere jako možná odeslaný: raději `false`
     * u skutečně prvního zaslání než dvakrát `true`.
     */
    public function hasSentAttempt(int $saleId): bool
    {
        return $this->db->fetchSingle(
            'SELECT 1 FROM %n WHERE [sale] = %i AND ([sent] = 1 OR [status] = %s) LIMIT 1',
            self::ATTEMPTS,
            $saleId,
            SaleQueue::ATTEMPT_INTERRUPTED,
        ) !== null;
    }

    /** @param array<string, mixed> $data */
    public function insertAttempt(array $data): int
    {
        $this->db->query('INSERT INTO %n %v', self::ATTEMPTS, $data);
        return (int) $this->db->getInsertId();
    }

    /** @param array<string, mixed> $data */
    public function updateAttempt(int $attemptId, array $data): void
    {
        $this->db->query('UPDATE %n SET %a WHERE [id] = %i', self::ATTEMPTS, $data, $attemptId);
    }

    /**
     * Zapíše výsledek pokusu do tržby — jen dokud ji drží tento proces.
     *
     * @param array<string, mixed> $data
     */
    public function finish(int $saleId, array $data): void
    {
        $this->db->query(
            'UPDATE %n SET %a WHERE [id] = %i AND [state] = %s',
            self::SALES,
            $data,
            $saleId,
            SaleState::SENDING,
        );
    }

    /**
     * Splatné tržby, nejstarší termín první.
     *
     * @return list<array{id: int, registration: int}>
     */
    public function due(int $limit, string $stamp): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [registration] FROM %n WHERE [state] = %s AND [next_attempt] <= %s'
            . ' ORDER BY [next_attempt], [id] LIMIT %i',
            self::SALES,
            SaleState::PENDING,
            $stamp,
            $limit,
        );
        return array_map(
            static fn($row): array => ['id' => (int) $row['id'], 'registration' => (int) $row['registration']],
            $rows,
        );
    }

    /**
     * Vrátí `sending` zamčené před `$staleBefore` na `pending`; počet pokusů
     * nemění. Pokusy těch tržeb, které zůstaly `sending`, dostanou stav
     * `interrupted` — a to **dřív** než se tržba vrátí: spadne-li obnova mezi
     * oběma dotazy, tržba zůstane `sending` a příští běh ji dorovná (opačné
     * pořadí by nechalo pokus `sending` navždy).
     */
    public function recoverStale(string $stamp, string $staleBefore): int
    {
        $this->db->query(
            'UPDATE %n a JOIN %n s ON s.[id] = a.[sale] SET a.[status] = %s'
            . ' WHERE a.[status] = %s AND s.[state] = %s AND (s.[claimed_at] IS NULL OR s.[claimed_at] < %s)',
            self::ATTEMPTS,
            self::SALES,
            SaleQueue::ATTEMPT_INTERRUPTED,
            SaleQueue::ATTEMPT_SENDING,
            SaleState::SENDING,
            $staleBefore,
        );
        $this->db->query(
            'UPDATE %n SET [state] = %s, [claimed_at] = NULL, [next_attempt] = %s'
            . ' WHERE [state] = %s AND ([claimed_at] IS NULL OR [claimed_at] < %s)',
            self::SALES,
            SaleState::PENDING,
            $stamp,
            SaleState::SENDING,
            $staleBefore,
        );
        return $this->db->getAffectedRows();
    }

    /**
     * Výsledek posledního dokončeného pokusu registrace od `$since`, nebo
     * null. Pokus bez výsledku (`sending`, `interrupted`) dokončený není.
     */
    public function lastAttemptStatus(int $registrationId, string $since): ?string
    {
        $status = $this->db->fetchSingle(
            'SELECT a.[status] FROM %n a JOIN %n s ON s.[id] = a.[sale]'
            . ' WHERE s.[registration] = %i AND a.[started_at] >= %s AND a.[status] NOT IN %in'
            . ' ORDER BY a.[started_at] DESC, a.[id] DESC LIMIT 1',
            self::ATTEMPTS,
            self::SALES,
            $registrationId,
            $since,
            [SaleQueue::ATTEMPT_SENDING, SaleQueue::ATTEMPT_INTERRUPTED],
        );
        return is_string($status) ? $status : null;
    }

    /** Vrátí tržbu ve stavu, ze kterého jde poslat znovu, do fronty s termínem hned. */
    public function requeue(int $saleId, string $stamp): bool
    {
        $state = $this->db->fetchSingle('SELECT [state] FROM %n WHERE [id] = %i', self::SALES, $saleId);
        if (!in_array($state, SaleState::RESENDABLE, true)) {
            return false;
        }
        $this->db->query(
            'UPDATE %n SET [state] = %s, [next_attempt] = %s, [claimed_at] = NULL WHERE [id] = %i AND [state] IN %in',
            self::SALES,
            SaleState::PENDING,
            $stamp,
            $saleId,
            SaleState::RESENDABLE,
        );
        return true;
    }
}
