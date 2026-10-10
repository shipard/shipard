<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\Analysis\AnalysisResultWriter;
use Shipard\Module\Core\Mail\Analysis\AnalysisRunner;
use Shipard\Module\Core\Mail\Analysis\AnalysisStates;

/**
 * Najde expirované rezervace v `core_mail_analysis_claims` (released=false,
 * expires_at < now), označí je `released=true` s reason `expired` a vrátí
 * jejich zprávy z `analysis_state=20` (Analyzuje se) zpět do fronty
 * (`analysis_state=10`). docState (workflow) se nemění.
 *
 * Strop (tasks/mail-analysis-queue-drain.md D26): když zprávě za poslední
 * hodinu vypršel claim potřetí (včetně právě uvolněného; stejné okno
 * a strop jako u selhaných běhů runneru — {@see AnalysisRunner::MAX_FAILURES_PER_HOUR},
 * {@see AnalysisRunner::FAILURE_WINDOW_SECONDS}), do fronty se nevrací:
 * stav 70 (Analýza selhala) a řádek selhaného běhu přes
 * {@see AnalysisResultWriter::recordFailedRun()} (`ai_error`, `created_by`
 * NULL). Kryje pády, které PHP nezachytí (paměť, zabitý proces), i claimy
 * démona. Obojí jen pokud je zpráva stále ve stavu 20 — result nebo
 * failed mohl mezitím doběhnout.
 *
 * Volá se 1×/min z cronu (CLI `mail-analysis-reap`). Recovery, když analyzer
 * mezi `claim` a `result` spadne. Spec: tasks/mail-phase3a.md §3.7.
 */
class AnalysisClaimReaper
{
    public const RELEASE_REASON_EXPIRED = 'expired';
    /** Hláška selhaného běhu při stropu vypršelých claimů (`%d` = strop). */
    public const EXPIRED_CAP_MESSAGE = 'analysis did not finish %d times within an hour (claim expired)';

    private const CLAIMS_TABLE = 'core_mail_analysis_claims';
    private const MESSAGES_TABLE = 'core_mail_incoming_messages';

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly AnalysisResultWriter $results,
    ) {}

    /**
     * @return list<array{
     *     claim_id: int,
     *     message_id: int,
     *     analyzer_id: string,
     *     duration_seconds: int,
     *     failed: bool
     * }> `failed` = zpráva přepnutá na stav 70 místo návratu do fronty.
     */
    public function reapExpired(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        $expired = $this->db->fetchAll(
            'SELECT id, message, analyzer_id, claimed_at FROM %n WHERE %n = %i AND %n < %s',
            self::CLAIMS_TABLE,
            'released',
            0,
            'expires_at',
            $nowStr,
        );

        if ($expired === []) {
            return [];
        }

        $reaped = [];
        $this->db->begin();
        try {
            foreach ($expired as $claim) {
                $claimId = (int) $claim['id'];
                $messageId = (int) $claim['message'];
                $analyzerId = (string) $claim['analyzer_id'];
                $claimedAt = (string) $claim['claimed_at'];

                $this->db->updateWhere(
                    self::CLAIMS_TABLE,
                    [
                        'released' => 1,
                        'released_at' => $nowStr,
                        'release_reason' => self::RELEASE_REASON_EXPIRED,
                    ],
                    '%n = %i',
                    'id',
                    $claimId,
                );

                $failed = $this->expiredWithinWindow($messageId, $now) >= AnalysisRunner::MAX_FAILURES_PER_HOUR
                    ? $this->failMessage($messageId, $nowStr)
                    : $this->requeueMessage($messageId, $nowStr);

                $reaped[] = [
                    'claim_id' => $claimId,
                    'message_id' => $messageId,
                    'analyzer_id' => $analyzerId,
                    'duration_seconds' => $this->durationSeconds($claimedAt, $nowStr),
                    'failed' => $failed,
                ];
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return $reaped;
    }

    /** Vypršelé claimy zprávy uvolněné za poslední hodinu — včetně právě uvolněného. */
    private function expiredWithinWindow(int $messageId, \DateTimeImmutable $now): int
    {
        return (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM %n WHERE %n = %i AND %n = %s AND %n > %s',
            self::CLAIMS_TABLE,
            'message',
            $messageId,
            'release_reason',
            self::RELEASE_REASON_EXPIRED,
            'released_at',
            $now->modify('-' . AnalysisRunner::FAILURE_WINDOW_SECONDS . ' seconds')->format('Y-m-d H:i:s'),
        );
    }

    /**
     * Analýza se vrací do fronty jen pokud je stále "Analyzuje se" —
     * result/failed mohl mezitím doběhnout, nepřepisujeme jeho stav.
     */
    private function requeueMessage(int $messageId, string $nowStr): bool
    {
        $this->db->execute(
            'UPDATE %n SET %n = %i, %n = %s WHERE %n = %i AND %n = %i',
            self::MESSAGES_TABLE,
            'analysis_state',
            AnalysisStates::QUEUED,
            'modified',
            $nowStr,
            'id',
            $messageId,
            'analysis_state',
            AnalysisStates::ANALYZING,
        );
        return false;
    }

    /**
     * Strop vypršelých claimů: stav 70 + selhaný běh, jen pokud je zpráva
     * stále ve stavu 20 (řádek zamčený pro zbytek transakce — jako claim).
     */
    private function failMessage(int $messageId, string $nowStr): bool
    {
        $state = $this->db->fetchSingle(
            'SELECT %n FROM %n WHERE %n = %i FOR UPDATE',
            'analysis_state',
            self::MESSAGES_TABLE,
            'id',
            $messageId,
        );
        if ((int) $state !== AnalysisStates::ANALYZING) {
            return false;
        }

        $this->db->execute(
            'UPDATE %n SET %n = %i, %n = %s WHERE %n = %i AND %n = %i',
            self::MESSAGES_TABLE,
            'analysis_state',
            AnalysisStates::FAILED,
            'modified',
            $nowStr,
            'id',
            $messageId,
            'analysis_state',
            AnalysisStates::ANALYZING,
        );
        $this->results->recordFailedRun(
            $messageId,
            'ai_error',
            sprintf(self::EXPIRED_CAP_MESSAGE, AnalysisRunner::MAX_FAILURES_PER_HOUR),
            null,
            null,
            null,
            null,
            $nowStr,
        );
        return true;
    }

    private function durationSeconds(string $from, string $to): int
    {
        $a = strtotime($from);
        $b = strtotime($to);
        if ($a === false || $b === false) {
            return 0;
        }
        return max(0, $b - $a);
    }
}
