<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\Texts;

/**
 * Escapování hodnot vkládaných do uživatelského textu, který se pak
 * zpracuje jako Markdown (#90 D50). Text píše uživatel a Markdown v něm je
 * záměr; hodnota z dokladu (název firmy, poznámka, číslo) Markdown být
 * nesmí — `*`, `_` nebo `[odkaz](…)` v datech by změnily vzhled tisku nebo
 * do něj vložily odkaz.
 *
 * CommonMark dovoluje zpětným lomítkem escapovat jakýkoli znak ASCII
 * interpunkce a vypíše ho beze změny. Escapuje se proto všechna — žádné
 * výjimky podle polohy v řádku: `1.210,50` i `2. 10. 2026` vyjdou stejně,
 * jen už nemůžou začít číslovaný seznam.
 */
final class MarkdownEscaper
{
    /** Název strategie autoescape v Twigu uživatelských textů. */
    public const STRATEGY = 'markdown';

    private const PUNCTUATION = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    public static function escape(string $value): string
    {
        // Konce řádků hodnoty (adresa, víceřádková poznámka) jako tvrdé
        // zalomení; mezery kolem nich pryč — čtyři mezery na začátku řádku
        // by jinak daly blok kódu.
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace('/[ \t]*\n[ \t]*/', "\n", $value);

        $value = addcslashes($value, self::PUNCTUATION);

        return str_replace("\n", "  \n", $value);
    }
}
