<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\IncomingMessageDocument;

/**
 * Fronta AI analýzy — jediné místo s predikátem „zpráva čeká na analýzu“
 * (tasks/mail-analysis-inprocess.md D13):
 *
 *   - `analysis_state = 10` (Ve frontě),
 *   - mimo Archiv a Koš (workflow se jinak nekontroluje),
 *   - `preprocess_state` mimo {@see AnalysisStates::PREPROCESS_BLOCKING_STATES},
 *   - `ai_analysis_enabled` zprávy NULL nebo true,
 *   - schránka bez `ai_analysis_disabled`, nebo explicitní message-level
 *     enabled=1 (přebíjí default schránky),
 *   - bez aktivního claimu (released=0 a expires_at v budoucnu).
 *
 * Sdílí ji in-process runner (`isEligible()` před claimem,
 * `eligible(1, null, $excludeIds)` při dobírání fronty), sweep
 * (`eligible(freeCount())`), gate reanalýzy v `AnalysisController`
 * a upozornění „Analýza pošty stojí“ (`stalled()`).
 */
class AnalysisQueue
{
    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const MAILBOXES_TABLE = 'core_mail_mailboxes';
    private const CLAIMS_TABLE = 'core_mail_analysis_claims';

    /**
     * Predikát nad aliasy `m` (zpráva) a `mb` (schránka); parametry dodává
     * {@see predicateParams()} ve stejném pořadí jako placeholdery.
     */
    private const PREDICATE = 'm.analysis_state = %i
                AND m.docState NOT IN %in
                AND m.preprocess_state NOT IN %in
                AND (m.ai_analysis_enabled IS NULL OR m.ai_analysis_enabled = %i)
                AND (mb.ai_analysis_disabled = %i OR m.ai_analysis_enabled = %i)
                AND NOT EXISTS (
                    SELECT 1 FROM %n c
                     WHERE c.message = m.id
                       AND c.released = %i
                       AND c.expires_at > %s
                )';

    public function __construct(
        private readonly DataSourceConnection $db,
    ) {}

    /**
     * Zprávy ve frontě, nejstarší první.
     *
     * @param list<int> $excludeIds Zprávy, které se nevydají — runner při
     *        dobírání fronty vynechává zprávy už zpracované v témže procesu
     *        (tasks/mail-analysis-queue-drain.md D25), jinak by se točil
     *        na zprávě vrácené do fronty.
     * @return list<array<string, mixed>> Sloupce `ndx`, `received_at`,
     *         `subject`, `sender_email`, `profile_override`,
     *         `raw_source_attachment`.
     */
    public function eligible(int $limit, ?string $now = null, array $excludeIds = []): array
    {
        $now ??= date('Y-m-d H:i:s');
        $exclude = $excludeIds !== [] ? ' AND m.id NOT IN %in' : '';
        $excludeParams = $excludeIds !== [] ? [array_values($excludeIds)] : [];

        return $this->db->fetchAll(
            'SELECT m.id AS ndx, m.received_at, m.subject, m.sender_email,
                    m.profile_override, m.raw_source_attachment
               FROM %n m
               JOIN %n mb ON mb.id = m.mailbox
              WHERE ' . self::PREDICATE . $exclude . '
              ORDER BY m.received_at ASC, m.id ASC
              LIMIT %i',
            self::MESSAGES_TABLE,
            self::MAILBOXES_TABLE,
            ...self::predicateParams($now),
            ...$excludeParams,
            ...[$limit],
        );
    }

    public function countEligible(?string $now = null): int
    {
        $now ??= date('Y-m-d H:i:s');

        return (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM %n m
               JOIN %n mb ON mb.id = m.mailbox
              WHERE ' . self::PREDICATE,
            self::MESSAGES_TABLE,
            self::MAILBOXES_TABLE,
            ...self::predicateParams($now),
        );
    }

    /**
     * Zprávy ve frontě déle než `$olderThanSeconds` podle `modified`
     * (vstup do fronty i návrat z reaperu `modified` razítkují) — vstup
     * upozornění „Analýza pošty stojí“ ({@see AnalysisStalledAlertCheck}).
     *
     * @return array{count: int, oldest: ?string} `oldest` = nejstarší `modified`
     */
    public function stalled(int $olderThanSeconds, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $cutoff = date('Y-m-d H:i:s', strtotime($now) - $olderThanSeconds);

        $row = $this->db->fetchRow(
            'SELECT COUNT(*) AS cnt, MIN(m.modified) AS oldest
               FROM %n m
               JOIN %n mb ON mb.id = m.mailbox
              WHERE ' . self::PREDICATE . '
                AND m.modified <= %s',
            self::MESSAGES_TABLE,
            self::MAILBOXES_TABLE,
            ...self::predicateParams($now),
            ...[$cutoff],
        );

        return [
            'count' => (int) ($row['cnt'] ?? 0),
            'oldest' => isset($row['oldest']) && $row['oldest'] !== null
                ? ($row['oldest'] instanceof \DateTimeInterface ? $row['oldest']->format('Y-m-d H:i:s') : (string) $row['oldest'])
                : null,
        ];
    }

    /** Jedna zpráva splňuje celý predikát fronty (gate před claimem runneru). */
    public function isEligible(int $messageId, ?string $now = null): bool
    {
        $now ??= date('Y-m-d H:i:s');

        return (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM %n m
               JOIN %n mb ON mb.id = m.mailbox
              WHERE m.id = %i
                AND ' . self::PREDICATE,
            self::MESSAGES_TABLE,
            self::MAILBOXES_TABLE,
            $messageId,
            ...self::predicateParams($now),
        ) > 0;
    }

    /** @return list<mixed> */
    private static function predicateParams(string $now): array
    {
        return [
            AnalysisStates::QUEUED,
            [IncomingMessageDocument::DOC_STATE_ARCHIVED, IncomingMessageDocument::DOC_STATE_TRASH],
            AnalysisStates::PREPROCESS_BLOCKING_STATES,
            1,
            0,
            1,
            self::CLAIMS_TABLE,
            0,
            $now,
        ];
    }
}
