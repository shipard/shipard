<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

/**
 * Kódy dispozic pravidel odesílatelů (cfgItem `core.mail.senderRuleDispositions`,
 * sloupec `core_mail_sender_rules.disposition`) — jediné místo s literály,
 * vzor {@see PrimaryTypes}.
 *
 * Dispozice určuje fázi zásahu (tasks/mail-sender-rules-after-analysis.md D6):
 * při příjmu archivuje jen `archive` (pre-triage, bez analýzy); po analýze
 * zprávy s `primary_type = other` a při potvrzení pravidla archivuje
 * jakákoli potvrzená dispozice — `archive` znamená „všechno“, tedy
 * i ostatní poštu. Vyhodnocení dělá volající matcheru, ne matcher.
 */
final class SenderRuleDispositions
{
    /** Před analýzou: zpráva vznikne rovnou v Archivu, AI ji nečte. */
    public const ARCHIVE = 'archive';

    /**
     * Po analýze: archivuje, jen když AI nenašla doklad ani dokument
     * Spisovny. Výchozí dispozice nového pravidla (D9).
     */
    public const ARCHIVE_IF_OTHER = 'archiveIfOther';

    public const DEFAULT = self::ARCHIVE_IF_OTHER;
}
