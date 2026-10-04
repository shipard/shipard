<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Sent;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Zápis a čtení Odeslané pošty (`core_mail_sent_messages`, #90 D40) mimo
 * dokumentový lifecycle — zprávy zakládá služba odeslání, uživatel je přes
 * formulář jen archivuje a maže (stav). Jediné místo, které zná stavy
 * transportu a tvar `source_ref` ve frontě.
 */
class SentMessageStore
{
    public const TABLE    = 'core_mail_sent_messages';
    public const TABLE_ID = 455;

    public const DOC_STATE_SENT     = 40;
    public const DOC_STATE_ARCHIVED = 70;
    public const DOC_STATE_DELETED  = 90;

    /** `docStateMain` stavu Odeslaná (`core.mail.docStatesSent`). */
    private const MAIN_STATE_SENT = 1;

    public const TRANSPORT_QUEUED = 'queued';
    public const TRANSPORT_SENT   = 'sent';
    public const TRANSPORT_FAILED = 'failed';

    public const CHANNEL_EMAIL = 'email';

    /** Prefix `source_ref` řádků fronty, které patří odeslané zprávě. */
    public const SOURCE_REF_PREFIX = 'sentMessage:';

    public function __construct(
        private readonly DataSourceConnection $db,
    ) {}

    public static function sourceRef(int $id): string
    {
        return self::SOURCE_REF_PREFIX . $id;
    }

    /** Id zprávy ze `source_ref` řádku fronty; null = řádek zprávě nepatří. */
    public static function idFromSourceRef(string $sourceRef): ?int
    {
        if (!str_starts_with($sourceRef, self::SOURCE_REF_PREFIX)) {
            return null;
        }
        $id = substr($sourceRef, strlen(self::SOURCE_REF_PREFIX));
        return ctype_digit($id) && (int) $id > 0 ? (int) $id : null;
    }

    /**
     * Založí zprávu rovnou jako Odeslanou, s transportem „ve frontě“ —
     * Koncept zpráva nemá (D41).
     *
     * @param array<string, mixed> $fields Obsah a vazby zprávy (sloupce tabulky).
     * @return int id zprávy
     */
    public function create(array $fields, \DateTimeImmutable $now): int
    {
        return $this->db->insertRow(self::TABLE, array_merge(
            ['channel' => self::CHANNEL_EMAIL, 'send_trigger' => 'manual'],
            $fields,
            [
                'transport_state' => self::TRANSPORT_QUEUED,
                'sent_at'         => null,
                'send_count'      => 0,
                'last_error'      => null,
                'last_outbox_id'  => null,
                'created'         => $now->format('Y-m-d H:i:s'),
                'modified'        => $now->format('Y-m-d H:i:s'),
                'docState'        => self::DOC_STATE_SENT,
                'docStateMain'    => self::MAIN_STATE_SENT,
            ],
        ));
    }

    /** @return array<string, mixed>|null */
    public function get(int $id): ?array
    {
        return $this->db->fetchRow('SELECT * FROM %n WHERE [id] = %i', self::TABLE, $id);
    }

    /**
     * Id příloh zprávy v pořadí, ve kterém jdou do e-mailu.
     *
     * @return list<int>
     */
    public function attachmentIds(int $id): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id] FROM [core_attachments_files]'
            . ' WHERE [table_id] = %i AND [record_id] = %i AND [is_deleted] = 0'
            . ' ORDER BY [att_order], [id]',
            self::TABLE_ID,
            $id,
        );
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Zprávy ve stavu Odeslaná, které ukazují na záznam — nejnovější první.
     * Archivované a smazané se u záznamu neukazují (D41).
     *
     * @return list<array<string, mixed>>
     */
    public function forTarget(string $table, int $row): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM %n WHERE [target_table_id] = %s AND [target_row] = %i AND [docState] = %i'
            . ' ORDER BY [created] DESC, [id] DESC',
            self::TABLE,
            $table,
            $row,
            self::DOC_STATE_SENT,
        );
    }

    /** Zpráva předaná frontě: čeká na transport řádkem `$outboxId`. */
    public function markQueued(int $id, int $outboxId, \DateTimeImmutable $now): void
    {
        $this->db->updateWhere(self::TABLE, [
            'transport_state' => self::TRANSPORT_QUEUED,
            'last_outbox_id'  => $outboxId,
            'last_error'      => null,
            'modified'        => $now->format('Y-m-d H:i:s'),
        ], 'id = %i', $id);
    }

    /**
     * Řádek fronty odešel. Počet odeslání roste vždy; stav transportu se
     * mění jen podle posledního řádku fronty — opožděný výsledek staršího
     * průchodu nesmí přepsat novější.
     */
    public function markSent(int $id, int $outboxId, \DateTimeImmutable $at): void
    {
        $atStr = $at->format('Y-m-d H:i:s');
        $this->db->execute(
            'UPDATE %n SET [send_count] = [send_count] + 1, [sent_at] = %s, [modified] = %s WHERE [id] = %i',
            self::TABLE,
            $atStr,
            $atStr,
            $id,
        );
        $this->db->updateWhere(self::TABLE, [
            'transport_state' => self::TRANSPORT_SENT,
            'last_error'      => null,
        ], 'id = %i AND last_outbox_id = %i', $id, $outboxId);
    }

    /** Řádek fronty selhal natrvalo. */
    public function markFailed(int $id, int $outboxId, ?string $error, \DateTimeImmutable $at): void
    {
        $this->db->updateWhere(self::TABLE, [
            'transport_state' => self::TRANSPORT_FAILED,
            'last_error'      => $error,
            'modified'        => $at->format('Y-m-d H:i:s'),
        ], 'id = %i AND last_outbox_id = %i', $id, $outboxId);
    }

    /** Selhaný řádek fronty se vrátil do fronty (`mail-outbox-retry`). */
    public function markRequeued(int $id, int $outboxId, \DateTimeImmutable $at): void
    {
        $this->db->updateWhere(self::TABLE, [
            'transport_state' => self::TRANSPORT_QUEUED,
            'last_error'      => null,
            'modified'        => $at->format('Y-m-d H:i:s'),
        ], 'id = %i AND last_outbox_id = %i', $id, $outboxId);
    }
}
