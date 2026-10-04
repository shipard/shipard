<?php

declare(strict_types=1);

namespace Shipard\Core\Mail;

/**
 * Posluchač výsledku transportu pro zprávy s daným `source_ref` (#90 D43).
 * Fronta zůstává čistě transportní — kdo do ní zprávu zařadil a chce znát
 * výsledek, zaregistruje se podle prefixu `source_ref`
 * (`MailOutboxService::addSourceListener()`).
 *
 * Volá se jen při změnách, které něco znamenají pro původce: zpráva odešla,
 * selhala natrvalo, nebo se selhaná vrátila do fronty. Mezistavy (další
 * pokus po chybě) posluchač nevidí.
 */
interface OutboxSourceListener
{
    /** Zpráva odešla. */
    public const STATE_SENT = 'sent';
    /** Zpráva selhala natrvalo (vyčerpané pokusy). */
    public const STATE_FAILED = 'failed';
    /** Selhaná zpráva se vrátila do fronty (`mail-outbox-retry`). */
    public const STATE_REQUEUED = 'requeued';

    /**
     * @param string $sourceRef Celý `source_ref` řádku fronty.
     * @param string $state Jedna z `STATE_*`.
     * @param ?string $error Poslední chyba transportu (jen u `STATE_FAILED`).
     */
    public function outboxStateChanged(
        string $sourceRef,
        int $outboxId,
        string $state,
        \DateTimeImmutable $at,
        ?string $error = null,
    ): void;
}
