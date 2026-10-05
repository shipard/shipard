<?php

declare(strict_types=1);

namespace Shipard\Core\Mail;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Mail\Exception\MailValidationException;
use Shipard\Core\Settings\SettingsStore;

/**
 * Fronta odchozí pošty (D25) — enqueue, stavový automat, exponenciální
 * backoff, atomický claim a recovery po pádu workeru. Stavy zprávy:
 * pending → sending → sent | failed (terminal, vrací mail-outbox-retry);
 * cancelled je rezervován pro budoucí UI.
 *
 * Každý pokus projde pojistkou odchozí pošty (`MailSafetyGuard`, #95):
 * mezi sestavením e-mailu a výběrem transportu, takže platí pro relay
 * i pro odesílatele s vlastním SMTP.
 *
 * Čas se všude předává parametrem (žádné NOW() v SQL) kvůli
 * testovatelnosti — vzor AlertReconciler.
 */
class MailOutboxService
{
    public const TABLE = 'core_mail_outbox';
    public const LOG_TABLE = 'core_mail_outbox_log';

    /** Odklad po 1.–5. selhání (s); 6. selhání je terminální `failed`. */
    public const BACKOFF = [60, 300, 1800, 7200, 21600];
    public const MAX_ATTEMPTS = 6;

    /** `sending` starší než tohle = spadlý worker, recovery vrací do `pending`. */
    public const STALE_SENDING_SEC = 600;

    public const PRIORITY_HIGH = 10;

    private const ERROR_MAX_LEN = 500;

    /** Délka sloupců `email_to` / `email_cc` a `email_from_name`. */
    private const ADDRESS_LIST_MAX_LEN = 2000;
    private const FROM_NAME_MAX_LEN = 200;

    /** @var array<string, OutboxSourceListener> prefix `source_ref` → posluchač */
    private array $sourceListeners = [];

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly TransportResolver $resolver,
        private readonly MailComposer $composer,
        private readonly SettingsStore $settings,
        private readonly MailSafetyConfig $safety,
    ) {
    }

    /**
     * Posluchač výsledku transportu pro zprávy, jejichž `source_ref` začíná
     * daným prefixem (#90 D43) — původce zprávy si výsledek propíše k sobě
     * a úklid fronty mu historii nevezme.
     */
    public function addSourceListener(string $sourceRefPrefix, OutboxSourceListener $listener): void
    {
        $this->sourceListeners[$sourceRefPrefix] = $listener;
    }

    /**
     * Zařadí zprávu do fronty se stavem `pending` a okamžitým `next_attempt`.
     * From doplní z DS defaultu (`mail.defaultFrom`), bez něj validační chyba.
     *
     * @return int id řádku outboxu
     */
    public function enqueue(OutboundMessage $message, ?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();

        $from = trim((string) ($message->from ?? ''));
        if ($from === '') {
            $from = trim((string) ($this->settings->get('mail.defaultFrom') ?? ''));
        }
        if ($from === '') {
            throw new MailValidationException(
                "Outbound message has no from address and setting 'mail.defaultFrom' is not set",
            );
        }
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailValidationException("Invalid from address: '{$from}'");
        }

        $to = AddressList::parse($message->to);
        if ($to === []) {
            throw new MailValidationException('Outbound message has no to address');
        }
        $invalid = AddressList::invalid($to);
        if ($invalid !== null) {
            throw new MailValidationException("Invalid to address: '{$invalid}'");
        }

        $cc      = AddressList::parse($message->cc);
        $invalid = AddressList::invalid($cc);
        if ($invalid !== null) {
            throw new MailValidationException("Invalid cc address: '{$invalid}'");
        }

        $toList = AddressList::format($to);
        $ccList = AddressList::format($cc);
        if (strlen($toList) > self::ADDRESS_LIST_MAX_LEN || strlen($ccList) > self::ADDRESS_LIST_MAX_LEN) {
            throw new MailValidationException('Outbound message has too many recipients');
        }

        // Jméno jde do hlavičky From — konce řádků by ji rozdělily.
        $fromName = trim((string) preg_replace('/[\r\n]+/', ' ', (string) ($message->fromName ?? '')));

        if (trim($message->subject) === '') {
            throw new MailValidationException('Outbound message subject must not be empty');
        }
        if (trim($message->sourceModule) === '') {
            throw new MailValidationException('Outbound message sourceModule must not be empty');
        }
        if (($message->bodyText ?? '') === '' && ($message->bodyHtml ?? '') === '') {
            throw new MailValidationException('Outbound message has no body (text nor html)');
        }

        $attachmentIds = [];
        foreach ($message->attachments as $attachmentId) {
            if (!is_int($attachmentId) || $attachmentId <= 0) {
                throw new MailValidationException('Attachment ids must be positive integers');
            }
            $attachmentIds[] = $attachmentId;
        }

        $nowStr = $now->format('Y-m-d H:i:s');

        // Jméno odesílatele a kopie jdou do řádku jen když jsou — zpráva bez
        // nich (pozvánka, reset hesla) tak projde i na zdroji dat, který
        // ještě neprošel `ds-upgrade` a sloupce nemá.
        $optional = [];
        if ($fromName !== '') {
            $optional['email_from_name'] = mb_substr($fromName, 0, self::FROM_NAME_MAX_LEN);
        }
        if ($ccList !== '') {
            $optional['email_cc'] = $ccList;
        }

        return $this->db->insertRow(self::TABLE, $optional + [
            'created'             => $nowStr,
            'created_by'          => $message->createdBy,
            'source_module'       => $message->sourceModule,
            'source_ref'          => $message->sourceRef,
            'email_from'          => $from,
            'email_to'            => $toList,
            'recipient_person_id' => $message->recipientPersonId,
            'subject'             => $message->subject,
            'body_text'           => $message->bodyText,
            'body_html'           => $message->bodyHtml,
            'attachments'         => $attachmentIds === [] ? null : json_encode($attachmentIds),
            'priority'            => $message->priority,
            'state'               => 'pending',
            'attempt_count'       => 0,
            'next_attempt'        => $nowStr,
        ]);
    }

    /**
     * Enqueue s priority high + okamžitý synchronní pokus o odeslání
     * v témže requestu (reset hesla nesmí čekat na cron). Selhání pokusu
     * nikdy nepropaguje — zprávu převezme fronta; validační chyby
     * z enqueue propagují.
     *
     * @return int id řádku outboxu
     */
    public function enqueueAndSend(OutboundMessage $message, ?\DateTimeImmutable $now = null): int
    {
        $prioritized = $message->withPriority(max($message->priority, self::PRIORITY_HIGH));

        $id = $this->enqueue($prioritized, $now);

        try {
            $this->attemptSend($id, $now);
        } catch (\Throwable $e) {
            // Transportní/compose chyby řeší fail větev attemptSend;
            // tohle chytá neočekávané infra chyby — request nesmí spadnout.
            error_log("MailOutboxService::enqueueAndSend outbox #{$id}: {$e->getMessage()}");
        }

        return $id;
    }

    /**
     * Jeden pokus o odeslání zprávy. Atomický claim (UPDATE podmíněný
     * stavem `pending`) — souběžný druhý claim téže zprávy vrátí false.
     * Úspěch → `sent`; chyba → backoff, po MAX_ATTEMPTS terminální
     * `failed`. Každý pokus zapíše řádek do logu.
     *
     * Zprávu zachycenou pojistkou transport nedostane a pokus končí jako
     * úspěch (#95 D6) — aplikace se chová jako v produkci, jen řádek nese
     * `safety_action`.
     */
    public function attemptSend(int $id, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        $this->db->execute(
            "UPDATE core_mail_outbox SET state = 'sending', claimed_at = %s WHERE id = %i AND state = 'pending'",
            $nowStr,
            $id,
        );
        if ($this->db->getAffectedRows() !== 1) {
            return false;
        }

        $row = $this->db->fetchRow('SELECT * FROM core_mail_outbox WHERE id = %i', $id);
        if ($row === null) {
            return false;
        }

        $attempt = (int) $row['attempt_count'] + 1;
        $transportLabel = 'unresolved';
        $startNs = hrtime(true);

        try {
            // Pojistka před výběrem transportu: řádek fronty si nechává
            // původní adresy, mění se jen obálka (#95 D3).
            $safety = MailSafetyGuard::apply($this->composer->compose($row), $this->safety);

            if ($safety->isDropped()) {
                $transportLabel = 'safety:' . $this->safety->mode;
                $response       = 'mail safety: not sent';
            } else {
                $resolved = $this->resolver->resolve((string) $row['email_from']);
                $transportLabel = $resolved->label;

                $response = $resolved->transport->send($safety->email)?->getDebug();
                if ($safety->intervened()) {
                    $note = $safety->target !== null
                        ? "mail safety: redirected to {$safety->target}"
                        : 'mail safety: recipients restricted';
                    $response = trim($note . "\n" . $response);
                }
            }

            $this->insertLogRow($id, $attempt, $nowStr, $transportLabel, 'ok', $response, $startNs);
            $this->db->updateWhere(self::TABLE, [
                'state'         => 'sent',
                'sent_at'       => $nowStr,
                'attempt_count' => $attempt,
                'claimed_at'    => null,
                'last_error'    => null,
            ], 'id = %i', $id);
            $this->recordSafety($id, $safety);
            $this->notifySource($row['source_ref'] ?? null, $id, OutboxSourceListener::STATE_SENT, $now, null, $safety);

            return true;
        } catch (\Throwable $e) {
            $error = $this->truncate($e->getMessage());
            $this->insertLogRow($id, $attempt, $nowStr, $transportLabel, 'fail', $error, $startNs);

            if ($attempt >= self::MAX_ATTEMPTS) {
                $this->db->updateWhere(self::TABLE, [
                    'state'         => 'failed',
                    'attempt_count' => $attempt,
                    'claimed_at'    => null,
                    'last_error'    => $error,
                ], 'id = %i', $id);
                $this->notifySource($row['source_ref'] ?? null, $id, OutboxSourceListener::STATE_FAILED, $now, $error);
            } else {
                $delay = self::BACKOFF[$attempt - 1];
                $this->db->updateWhere(self::TABLE, [
                    'state'         => 'pending',
                    'attempt_count' => $attempt,
                    'claimed_at'    => null,
                    'last_error'    => $error,
                    'next_attempt'  => $now->modify("+{$delay} seconds")->format('Y-m-d H:i:s'),
                ], 'id = %i', $id);
            }

            return false;
        }
    }

    /**
     * Zpracuje due zprávy fronty (worker `mail-outbox-run`). Nejdřív
     * recovery: `sending` starší STALE_SENDING_SEC (pád workeru) vrací
     * do `pending`, pak due `pending` v pořadí priorita DESC, stáří ASC.
     *
     * @return array{requeued: int, processed: int, sent: int, retried: int, failed: int}
     */
    public function processQueue(int $limit = 50, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        $this->db->execute(
            "UPDATE core_mail_outbox SET state = 'pending', claimed_at = NULL"
            . " WHERE state = 'sending' AND claimed_at < %s",
            $now->modify('-' . self::STALE_SENDING_SEC . ' seconds')->format('Y-m-d H:i:s'),
        );
        $requeued = $this->db->getAffectedRows();

        $rows = $this->db->fetchAll(
            "SELECT id FROM core_mail_outbox WHERE state = 'pending' AND next_attempt <= %s"
            . ' ORDER BY priority DESC, created ASC LIMIT %i',
            $nowStr,
            $limit,
        );

        $stats = ['requeued' => $requeued, 'processed' => 0, 'sent' => 0, 'retried' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $stats['processed']++;

            if ($this->attemptSend($id, $now)) {
                $stats['sent']++;
                continue;
            }

            $state = (string) $this->db->fetchSingle(
                'SELECT state FROM core_mail_outbox WHERE id = %i',
                $id,
            );
            if ($state === 'failed') {
                $stats['failed']++;
            } else {
                $stats['retried']++;
            }
        }

        return $stats;
    }

    /** Vrátí `failed` zprávu do fronty s vynulovaným počítadlem (mail-outbox-retry). */
    public function retry(int $id, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        $this->db->execute(
            "UPDATE core_mail_outbox SET state = 'pending', attempt_count = 0,"
            . " next_attempt = %s, last_error = NULL WHERE id = %i AND state = 'failed'",
            $now->format('Y-m-d H:i:s'),
            $id,
        );

        if ($this->db->getAffectedRows() !== 1) {
            return false;
        }

        if ($this->sourceListeners !== []) {
            $sourceRef = $this->db->fetchSingle('SELECT source_ref FROM core_mail_outbox WHERE id = %i', $id);
            $this->notifySource($sourceRef, $id, OutboxSourceListener::STATE_REQUEUED, $now);
        }

        return true;
    }

    /**
     * Zapíše na řádek fronty zásah pojistky. Zvlášť a až po stavu `sent`:
     * zdroj dat před `ds-upgrade` sloupce nemá a chyba zápisu stopy nesmí
     * z odeslané zprávy udělat selhanou — další pokus by ji poslal znovu.
     */
    private function recordSafety(int $id, MailSafetyResult $safety): void
    {
        if (!$safety->intervened()) {
            return;
        }
        try {
            $this->db->updateWhere(self::TABLE, [
                'safety_action' => $safety->action,
                'safety_target' => $safety->target,
            ], 'id = %i', $id);
        } catch (\Throwable $e) {
            error_log("MailOutboxService: safety trace of outbox #{$id} not stored: {$e->getMessage()}");
        }
    }

    /**
     * Řekne posluchači registrovanému pro `source_ref` zprávy, jak transport
     * dopadl. Stav fronty je v tu chvíli už zapsaný — chyba posluchače ho
     * nesmí změnit ani shodit worker, jen se zaloguje.
     */
    private function notifySource(
        mixed $sourceRef,
        int $outboxId,
        string $state,
        \DateTimeImmutable $at,
        ?string $error = null,
        ?MailSafetyResult $safety = null,
    ): void {
        if (!is_string($sourceRef) || $sourceRef === '') {
            return;
        }
        foreach ($this->sourceListeners as $prefix => $listener) {
            if (!str_starts_with($sourceRef, $prefix)) {
                continue;
            }
            try {
                $listener->outboxStateChanged($sourceRef, $outboxId, $state, $at, $error, $safety);
            } catch (\Throwable $e) {
                error_log("MailOutboxService: source listener '{$prefix}' failed for outbox #{$outboxId}: {$e->getMessage()}");
            }
        }
    }

    private function insertLogRow(
        int $outboxId,
        int $attempt,
        string $ts,
        string $transport,
        string $result,
        ?string $smtpResponse,
        int $startNs,
    ): void {
        $this->db->insertRow(self::LOG_TABLE, [
            'outbox_id'     => $outboxId,
            'attempt'       => $attempt,
            'ts'            => $ts,
            'transport'     => $transport,
            'result'        => $result,
            'smtp_response' => $this->truncate($smtpResponse),
            'duration_ms'   => intdiv(hrtime(true) - $startNs, 1_000_000),
        ]);
    }

    private function truncate(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text === '' ? null : $text;
        }
        return mb_substr($text, 0, self::ERROR_MAX_LEN);
    }
}
