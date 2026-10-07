<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

/**
 * Sdílená SQL podmínka „čekající ostatní pošta“ (tasks/mail-other-attention.md
 * D4, D5, #105): zpráva v Nové (10), analyzovaná (30), `primary_type =
 * other`, bez otevřeného dokumentového návrhu poslední úspěšné analýzy.
 * Nad touž množinou staví `MailSuggestionsSource` karty K vyřízení
 * (`attention = action`) a Ostatní (zbytek) a `MailController::
 * archiveInformational` (Archivovat vše) z ní bere řádky `info` / `promo` —
 * jedno místo pravdy, aby tlačítko odklidilo přesně to, co sekce ukazuje.
 *
 * Fragmenty jsou z konstant bez Dibi placeholderů, vkládají se do dotazu
 * s aliasem `m` tabulky `core_mail_incoming_messages`. Ruční nahrání se
 * tu **nevylučuje** (na dashboardu je) — `PostAnalysisDisposer` má vlastní
 * dotaz s dalšími podmínkami (jistota, odesílatel).
 */
final class OtherMailQuery
{
    private const string ANALYSES_TABLE = 'core_mail_message_analyses';

    /** `core_mail_message_analyses.status` úspěšného běhu. */
    private const int ANALYSIS_STATUS_SUCCESS = 2;

    /** WHERE fragment (bez úvodního AND) čekající ostatní pošty. */
    public static function pendingWhere(): string
    {
        return '`m`.`analysis_state` = ' . IncomingMessageDocument::ANALYSIS_ANALYZED
            . ' AND `m`.`docState` = ' . IncomingMessageDocument::DOC_STATE_NEW
            . ' AND `m`.`primary_type` = \'' . PrimaryTypes::OTHER . '\''
            . ' AND COALESCE(('
            . '     SELECT `a`.`canonical_json` IS NOT NULL AND `a`.`resolution` IS NULL'
            . '     FROM `' . self::ANALYSES_TABLE . '` `a`'
            . '     WHERE `a`.`message` = `m`.`id` AND `a`.`status` = ' . self::ANALYSIS_STATUS_SUCCESS
            . '     ORDER BY `a`.`analyzed_at` DESC, `a`.`id` DESC LIMIT 1'
            . ' ), 0) = 0';
    }

    /**
     * WHERE fragment informativních řádků Ostatní (`attention` info / promo) —
     * množina pro Archivovat vše (D5). Řádky s NULL pozorností (starší
     * analýza) zůstávají per řádek.
     */
    public static function informationalWhere(): string
    {
        $kinds = array_map(static fn (string $k): string => "'{$k}'", AttentionKinds::INFORMATIONAL);
        return self::pendingWhere() . ' AND `m`.`attention` IN (' . implode(', ', $kinds) . ')';
    }
}
