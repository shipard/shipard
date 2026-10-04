<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Mail\RecordSender;
use Shipard\Core\Mail\RecordSenderProvider;

/**
 * Odesílatel dokladu podle číselné řady (#90 D39): volba „Odesílat z“ na
 * řadě (`email_from`, NULL = automaticky) a volitelné jméno odesílatele
 * (`email_from_name`). Fakturace tak může odcházet z jiné adresy než
 * pokladní doklady, bez volby při každém odeslání.
 */
final class NumberSeriesSenderProvider implements RecordSenderProvider
{
    public function recordSender(array $record, DataSourceConnection $db): ?RecordSender
    {
        $seriesId = (int) ($record['number_series'] ?? 0);
        if ($seriesId <= 0) {
            return null;
        }

        $series = $db->fetchRow(
            'SELECT [email_from], [email_from_name] FROM [docs_core_number_series] WHERE [id] = %i',
            $seriesId,
        );
        if ($series === null) {
            return null;
        }

        $email = trim((string) ($series['email_from'] ?? ''));
        $name  = trim((string) ($series['email_from_name'] ?? ''));

        return new RecordSender($email === '' ? null : $email, $name === '' ? null : $name);
    }
}
