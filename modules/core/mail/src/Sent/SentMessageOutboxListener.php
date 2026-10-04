<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Sent;

use Shipard\Core\Mail\OutboxSourceListener;

/**
 * Propisuje výsledek transportu do odeslané zprávy (#90 D43): stav, čas
 * a počet odeslání, nebo poslední chybu. Úklid fronty tak historii nevezme.
 * Registruje `MailServiceFactory` pro `source_ref` s prefixem `sentMessage:`.
 */
final class SentMessageOutboxListener implements OutboxSourceListener
{
    public function __construct(
        private readonly SentMessageStore $store,
    ) {}

    public function outboxStateChanged(
        string $sourceRef,
        int $outboxId,
        string $state,
        \DateTimeImmutable $at,
        ?string $error = null,
    ): void {
        $id = SentMessageStore::idFromSourceRef($sourceRef);
        if ($id === null) {
            return;
        }

        match ($state) {
            self::STATE_SENT     => $this->store->markSent($id, $outboxId, $at),
            self::STATE_FAILED   => $this->store->markFailed($id, $outboxId, $error, $at),
            self::STATE_REQUEUED => $this->store->markRequeued($id, $outboxId, $at),
            default              => null,
        };
    }
}
