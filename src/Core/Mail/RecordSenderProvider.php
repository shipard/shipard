<?php

declare(strict_types=1);

namespace Shipard\Core\Mail;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Odesílatel podle záznamu (#90 D39) — jeden poskytovatel na tabulku,
 * registrace `recordSenderProviders: [{table, class}]` v module.jsonc.
 * Doklady ho berou z číselné řady (`NumberSeriesSenderProvider`).
 *
 * Třída se instancuje bez parametrů.
 */
interface RecordSenderProvider
{
    /**
     * @param array<string, mixed> $record Řádek tabulky, pro kterou je
     *        poskytovatel registrovaný.
     * @return ?RecordSender Null = záznam odesílatele neurčuje.
     */
    public function recordSender(array $record, DataSourceConnection $db): ?RecordSender;
}
