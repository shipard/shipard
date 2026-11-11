<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Automatické zaokrouhlení celkové částky podle způsobu úhrady (#73 D2–D6).
 *
 * Čistá logika nad konfigurací, bez databáze. Pravidlo běží v
 * `DocDocument::computeAmounts()` (uložení i náhled applieru sdílejí
 * výpočet); formulář jím jen předpočítá mód, aby ho uživatel viděl před
 * uložením, a applier podle něj nastaví příznak novému dokladu (D8).
 *
 *  - Typ dokladu: `docTypes[].total_rounding_auto` (`DocTypes`). Platí jen
 *    pro prodejní doklad — směr obchodu výstup podle
 *    `DocDocument::resolveTradeDir()`, u `cash` tedy jen příjem.
 *  - Hotovostní způsob úhrady: `paymentMethods[].cashRounding` (Hotovost,
 *    Dobírkou).
 *  - Krok hotovosti podle měny dokladu a země zdroje dat:
 *    `world.base.cashRounding` (CZ + CZK na koruny, SK + EUR na 0,05).
 *    `world` kódy módů nezná, krok překládá `RoundingModes::forStep()`.
 *
 * Bez konfigurace (unit testy, zdroj dat před `ds-upgrade`) vrací
 * `modeFor()` null a volající mód nemění — nikdy tiché Na haléře.
 */
final class TotalRoundingAuto
{
    /**
     * Doklad, na kterém automatika platí: typ s příznakem a směr obchodu
     * výstup. Bez konfigurace false.
     */
    public static function applies(?ConfigRuntime $config, string $docType, int $cashDir): bool
    {
        if (!DocTypes::hasTotalRoundingAuto($config, $docType)) {
            return false;
        }
        $tradeDir = DocDocument::resolveTradeDir(['doc_type' => $docType, 'cash_dir' => $cashDir], $config);
        return $tradeDir === 1;
    }

    /** Způsob úhrady s příznakem `cashRounding` (hotovost, dobírka). Bez konfigurace false. */
    public static function isCashPayment(?ConfigRuntime $config, int $paymentMethod): bool
    {
        $methods = $config?->cfgItem('docs.core.paymentMethods');
        if (!is_array($methods) || !is_array($methods[$paymentMethod] ?? null)) {
            return false;
        }
        return ($methods[$paymentMethod]['cashRounding'] ?? false) === true;
    }

    /**
     * Mód zaokrouhlení celkové částky pro způsob úhrady, měnu dokladu a zemi
     * zdroje dat. Null = nelze rozhodnout (chybí konfigurace způsobů úhrady
     * či hotovosti, nebo země) — volající mód nemění.
     *
     * Nehotovostní úhrada → na haléře. Hotovostní: pravidlo měny se zemí
     * v `countries` → mód pro jeho krok; měna bez pravidla, země mimo seznam
     * nebo krok bez módu → na haléře.
     */
    public static function modeFor(?ConfigRuntime $config, int $paymentMethod, string $currency, ?string $country): ?int
    {
        $methods = $config?->cfgItem('docs.core.paymentMethods');
        $rules = $config?->cfgItem('world.base.cashRounding');
        if (!is_array($methods) || !is_array($rules) || $country === null || $country === '') {
            return null;
        }
        if (!self::isCashPayment($config, $paymentMethod)) {
            return RoundingModes::ON_CENT;
        }

        $rule = $rules[strtolower($currency)] ?? null;
        if (!is_array($rule)) {
            return RoundingModes::ON_CENT;
        }
        $countries = array_map(
            static fn (mixed $c): string => strtolower((string) $c),
            is_array($rule['countries'] ?? null) ? $rule['countries'] : [],
        );
        if (!in_array(strtolower($country), $countries, true)) {
            return RoundingModes::ON_CENT;
        }

        return RoundingModes::forStep((float) ($rule['step'] ?? 0)) ?? RoundingModes::ON_CENT;
    }
}
