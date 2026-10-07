<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Čtení cfgItem `core.mail.attentionKinds` — pozornost u zprávy bez dokladu
 * (tasks/mail-other-attention.md D1, D3, #105): AI u `primary_type = other`
 * rozliší, zda zpráva vyžaduje akci (`action`), jen informuje (`info`),
 * nebo je to obchodní sdělení (`promo`). Sdílí AnalysisController (validace
 * z /result), PostAnalysisDisposer (D6), MailSuggestionsSource (sekce feedu)
 * a MailController (Archivovat vše), aby hodnoty nežily v literálech.
 */
final class AttentionKinds
{
    public const string ACTION = 'action';
    public const string INFO = 'info';
    public const string PROMO = 'promo';

    /** Informativní pošta — zůstává v Ostatních, odklízí ji Archivovat vše (D5). */
    public const array INFORMATIONAL = [self::INFO, self::PROMO];

    private const string CFG_ITEM = 'core.mail.attentionKinds';

    /** Pevný seznam pro DS bez compiled configu — musí odpovídat attentionKinds.jsonc. */
    private const array FALLBACK = [self::ACTION, self::INFO, self::PROMO];

    /**
     * Klíče cfgItem; bez compiled configu pevný seznam.
     *
     * @return list<string>
     */
    public static function known(?ConfigRuntime $config): array
    {
        $cfg = $config?->cfgItem(self::CFG_ITEM);
        if (is_array($cfg) && $cfg !== []) {
            return array_values(array_map('strval', array_keys($cfg)));
        }
        return self::FALLBACK;
    }
}
