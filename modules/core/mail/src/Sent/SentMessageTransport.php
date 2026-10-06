<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Sent;

use Shipard\Core\Mail\AddressList;
use Shipard\Core\Mail\MailOutboxService;
use Shipard\Core\Mail\OutboundMessage;

/**
 * Předání odeslané zprávy transportu (#90 D43, D44). Obsah je v zprávě —
 * transport z ní jen sestaví `OutboundMessage` (odesílatel se jménem,
 * všichni v „Komu“, kopie, tělo, přílohy zprávy) a zařadí ji do fronty.
 * Výsledek si zpráva propíše sama přes `SentMessageOutboxListener`.
 *
 * Odeslat znovu = další průchod téže zprávy: stejní příjemci, stejné
 * přílohy, nic nového nevzniká.
 */
class SentMessageTransport
{
    private const SOURCE_MODULE = 'core.mail';

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param ?\Closure(): \DateTimeImmutable $clock Čas (testy). */
    public function __construct(
        private readonly SentMessageStore $store,
        private readonly MailOutboxService $outbox,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * Zařadí zprávu do fronty a volitelně ji hned zkusí odeslat. Stav
     * zprávy nehlídá — první odeslání nové zprávy i Odeslat znovu jdou
     * tudy; pravidla pro opakování jsou v `resend()`.
     *
     * @return int id řádku fronty
     * @throws SentMessageException Zpráva neexistuje.
     * @throws \LogicException Zpráva má jiný kanál než e-mail.
     */
    public function dispatch(int $sentMessageId, bool $sendNow, ?int $userId = null): int
    {
        $outboxId = $this->enqueue($sentMessageId, $sendNow, $userId);
        if ($sendNow) {
            $this->attempt($outboxId);
        }
        return $outboxId;
    }

    /**
     * Jen zařazení do fronty — volající s vlastní transakcí pošle okamžitý
     * pokus (`attempt()`) až po commitu, aby rollback nepřišel po odeslání.
     *
     * @param bool $priority Zpráva čeká na uživatele — ve frontě předbíhá.
     * @return int id řádku fronty
     */
    public function enqueue(int $sentMessageId, bool $priority = false, ?int $userId = null): int
    {
        $message = $this->store->get($sentMessageId);
        if ($message === null) {
            throw new SentMessageException(
                SentMessageException::NOT_FOUND,
                "Sent message {$sentMessageId} not found",
            );
        }
        if ((string) $message['channel'] !== SentMessageStore::CHANNEL_EMAIL) {
            throw new \LogicException(
                "Sent message {$sentMessageId}: channel '{$message['channel']}' has no transport",
            );
        }

        $now      = ($this->clock)();
        $outboxId = $this->outbox->enqueue(new OutboundMessage(
            to: AddressList::parse($message['email_to'] ?? null),
            subject: (string) $message['subject'],
            sourceModule: self::SOURCE_MODULE,
            from: (string) $message['email_from'],
            bodyText: (string) ($message['body_text'] ?? ''),
            attachments: $this->store->attachmentIds($sentMessageId),
            recipientPersonId: isset($message['recipient_person']) ? (int) $message['recipient_person'] : null,
            sourceRef: SentMessageStore::sourceRef($sentMessageId),
            priority: $priority ? MailOutboxService::PRIORITY_HIGH : 0,
            createdBy: $userId ?? (isset($message['created_by']) ? (int) $message['created_by'] : null),
            cc: AddressList::parse($message['email_cc'] ?? null),
            fromName: isset($message['email_from_name']) ? (string) $message['email_from_name'] : null,
        ), $now);

        $this->store->markQueued($sentMessageId, $outboxId, $now);

        return $outboxId;
    }

    /**
     * Okamžitý pokus o odeslání řádku fronty. Selhání nepropaguje — zprávu
     * převezme fronta (další pokusy), zpráva zůstává „ve frontě“.
     */
    public function attempt(int $outboxId): bool
    {
        try {
            return $this->outbox->attemptSend($outboxId, ($this->clock)());
        } catch (\Throwable $e) {
            error_log("SentMessageTransport::attempt outbox #{$outboxId}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Odeslat znovu (D44): jen zprávu ve stavu Odeslaná (archivovanou
     * nejdřív obnovit), která právě nečeká ve frontě. Zpráva převzatá ze
     * starého systému znovu nejde nikdy (#104 D5) — nové odeslání je
     * Odeslat na záznamu.
     *
     * @return int id řádku fronty
     * @throws SentMessageException `NOT_FOUND`, `IMPORTED`, `INVALID_STATE`, `ALREADY_QUEUED`.
     */
    public function resend(int $sentMessageId, bool $sendNow = true, ?int $userId = null): int
    {
        $message = $this->store->get($sentMessageId);
        if ($message === null) {
            throw new SentMessageException(
                SentMessageException::NOT_FOUND,
                "Sent message {$sentMessageId} not found",
            );
        }
        if ((string) ($message['send_trigger'] ?? '') === SentMessageStore::TRIGGER_IMPORT) {
            throw new SentMessageException(
                SentMessageException::IMPORTED,
                'An imported message cannot be sent again — use Send on the record instead',
            );
        }
        if ((int) $message['docState'] !== SentMessageStore::DOC_STATE_SENT) {
            throw new SentMessageException(
                SentMessageException::INVALID_STATE,
                'Only a message in the Sent state can be sent again — restore it first',
            );
        }
        if ((string) $message['transport_state'] === SentMessageStore::TRANSPORT_QUEUED) {
            throw new SentMessageException(
                SentMessageException::ALREADY_QUEUED,
                'The message is already waiting in the outbound queue',
            );
        }

        return $this->dispatch($sentMessageId, $sendNow, $userId);
    }
}
