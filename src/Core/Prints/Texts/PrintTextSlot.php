<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\Texts;

/**
 * Sloty textů na tiscích (#90 D48) — pevná místa, kam jde do tisku nebo do
 * e-mailu vložit vlastní text. Tisk v deklaraci (`textSlots`) uvede, které
 * podporuje.
 *
 * Výčet je zdroj pravdy pro id slotů a jejich druh: deklarace tisku se
 * validuje bez kompilované konfigurace. Názvy a popisy pro formulář nese
 * cfgItem `core.prints.textSlots` modulu `core.prints`; shodu klíčů hlídá
 * test.
 */
enum PrintTextSlot: string
{
    /** Začátek těla dokumentu — pod záhlavím, před stranami. */
    case Header = 'header';

    /** Před tabulkou řádků. */
    case BeforeRows = 'beforeRows';

    /** Za tabulkou řádků — před rekapitulací a součty. */
    case AfterRows = 'afterRows';

    /** Konec dokumentu — za poznámkami. */
    case Footer = 'footer';

    /** Předmět e-mailu — přepisuje výchozí šablonu (D49). */
    case EmailSubject = 'emailSubject';

    /** Tělo e-mailu — přepisuje výchozí šablonu (D49). */
    case EmailBody = 'emailBody';

    /** cfgItem s názvy a popisy slotů. */
    public const CFG_ITEM = 'core.prints.textSlots';

    /**
     * E-mailový slot je prostý text (bez Markdownu) a dává smysl jen u tisku,
     * který jde odeslat; ostatní sloty jsou HTML do stránky tisku.
     */
    public function isEmail(): bool
    {
        return $this === self::EmailSubject || $this === self::EmailBody;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(static fn (self $slot): string => $slot->value, self::cases());
    }
}
