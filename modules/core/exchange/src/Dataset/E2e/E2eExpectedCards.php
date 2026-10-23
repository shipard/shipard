<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\E2e;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\Feed\MailSuggestionsSource;

/**
 * Počet karet návrhů, které proklikání čeká (`SHPD_E2E_EXPECTED_CARDS`,
 * tasks/testing-phase7a.md I9): zprávy sady (podle `message_id`
 * z `mail/*.jsonc`) splňující **tentýž** predikát jako feed dashboardu
 * ({@see MailSuggestionsSource::openProposalWhere()}) — počítá se po
 * přeseedování, nad skutečným stavem zdroje dat.
 */
final class E2eExpectedCards
{
    private function __construct()
    {
    }

    /** @param list<string> $messageIds */
    public static function count(DataSourceConnection $db, array $messageIds): int
    {
        if ($messageIds === []) {
            return 0;
        }
        $value = $db->fetchSingle(
            'SELECT COUNT(*) FROM `core_mail_incoming_messages` `m`'
            . MailSuggestionsSource::latestAnalysisJoin()
            . ' WHERE ' . MailSuggestionsSource::openProposalWhere()
            . ' AND `m`.`message_id` IN %in',
            $messageIds,
        );
        return (int) $value;
    }
}
