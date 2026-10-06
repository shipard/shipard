<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Sent;

/**
 * Výsledek importu odeslané zprávy (#104 D1, D2): `created = false` značí,
 * že zpráva s touto identitou už existovala a nic nevzniklo.
 */
final class SentMessageImportResult
{
    public function __construct(
        public readonly int $id,
        public readonly bool $created,
        /** Počet uložených příloh; u existující zprávy 0. */
        public readonly int $attachments = 0,
    ) {}
}
