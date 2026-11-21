<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Tři stavy položkového řádku dokladu s DPH (#164 D1, D2, U1) — jediné místo
 * pravidla:
 *
 *  - **v rekapitulaci** — řádek má kód DPH, který není „Mimo DPH“
 *    ({@see inRecap()});
 *  - **mimo rekapitulaci, v částce dokladu** — řádek má kód kategorie
 *    `outOfScope` (`cz-000`): není plněním ({@see isOutOfScope()});
 *  - **nevyplněno** — řádek kód nemá ({@see lacksCode()}); doklad s ním
 *    nejde potvrdit (`vat_code_missing`).
 *
 * `empty($row['vat_code'])` má v kódu dva významy. „Řádek nemá kód“ (kontroly
 * sazby, předmět plnění, odvození procenta) zůstává přímým dotazem na
 * sloupec; „řádek nepatří do rekapitulace“ jde vždy přes tuto třídu — záměna
 * by „Mimo DPH“ poslala do rekapitulace, nebo mu naopak vypnula kontroly.
 *
 * Jediný znak „mimo rekapitulaci“ je kategorie kódu
 * ({@see VatRateResolver::isOutOfScopeDefinition()}). Bez definic kódů
 * (`$vatCodes === null` — země registrace nebo konfigurace se nedohledala)
 * se kategorie určit nedá: řádek s kódem se bere jako řádek rekapitulace,
 * stejně jako před zavedením kódu „Mimo DPH“.
 */
final class VatLineScope
{
    /** Kód chyby řádku bez kódu DPH na dokladu s DPH (#164 U8). */
    public const MISSING_CODE = 'vat_code_missing';

    /** Text nálezu {@see MISSING_CODE} — stejný při potvrzení dokladu i při uložení řádku. */
    public const MISSING_CODE_MESSAGE = 'Řádek nemá kód DPH. Vyberte kód, nebo „Mimo DPH“.';

    /** Kód chyby řádku převzaté rekapitulace s kódem „Mimo DPH“ (#164 U6). */
    public const RECAP_OUT_OF_SCOPE = 'vat_recap_out_of_scope';

    /**
     * Patří řádek dokladu do rekapitulace DPH? Položkový řádek s kódem DPH,
     * který není „Mimo DPH“.
     *
     * @param array<string, mixed> $line řádek dokladu
     * @param array<string, array<string, mixed>>|null $vatCodes definice kódů země dokladu
     */
    public static function inRecap(array $line, ?array $vatCodes): bool
    {
        return self::isItem($line)
            && !empty($line['vat_code'])
            && !self::isOutOfScopeCode($line['vat_code'], $vatCodes);
    }

    /**
     * Je to položkový řádek s kódem „Mimo DPH“ — mimo rekapitulaci, ale
     * vždy v částce dokladu (#164 D7)?
     *
     * @param array<string, mixed> $line
     * @param array<string, array<string, mixed>>|null $vatCodes
     */
    public static function isOutOfScope(array $line, ?array $vatCodes): bool
    {
        return self::isItem($line) && self::isOutOfScopeCode($line['vat_code'] ?? null, $vatCodes);
    }

    /**
     * Položkový řádek bez kódu DPH — „nevyplněno“.
     *
     * @param array<string, mixed> $line
     */
    public static function lacksCode(array $line): bool
    {
        return self::isItem($line) && trim((string) ($line['vat_code'] ?? '')) === '';
    }

    /**
     * Je kód (řádku dokladu nebo řádku rekapitulace) „Mimo DPH“?
     *
     * @param array<string, array<string, mixed>>|null $vatCodes
     */
    public static function isOutOfScopeCode(mixed $vatCode, ?array $vatCodes): bool
    {
        $code = trim((string) ($vatCode ?? ''));
        return $code !== ''
            && $vatCodes !== null
            && VatRateResolver::isOutOfScopeDefinition($vatCodes[$code] ?? null);
    }

    /** @param array<string, mixed> $line */
    private static function isItem(array $line): bool
    {
        return (int) ($line['row_kind'] ?? 1) === 1;
    }
}
