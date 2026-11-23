<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Statické čtení atributů typu dokladu z cfgItem `docs.core.docTypes`
 * (`config/docTypes.jsonc`), které nemají jinou autoritu.
 *
 * `tax_document` (#79 D1): `false` = doklad není daňový (zálohová faktura
 * vydaná) — DPH se na něm počítá jen informativně, nemá DUZP ani DPPD
 * a nevstupuje do tvrzení DPH. Chybějící atribut = daňový doklad (všechny
 * dosavadní typy). Kód nikdy neporovnává `doc_type === 'invpo'` — vždy
 * tenhle helper.
 *
 * `total_rounding_auto` (#73 D2): `true` = prodejní typ s automatickým
 * zaokrouhlením celkové částky podle způsobu úhrady (`invno`, `invpo`,
 * `cashreg`, `cash`). Zda automatika na konkrétním dokladu platí (u `cash`
 * jen příjem), říká `TotalRoundingAuto::applies()`.
 *
 * `corrective` (#92 D1): `true` = typ, který jde označit jako opravný
 * daňový doklad (`invno`). Opravný doklad je příznak na hlavičce, ne nový
 * typ; původní doklad musí být typu s příznakem. Chybějící atribut = typ bez
 * opravných dokladů (`DocDocument::applyCorrectiveColumns` příznak vynuluje).
 *
 * `vat` (#166 U1): `false` = typ bez DPH (`cmnbkp`) — nový doklad má režim
 * „Bez DPH“ a registraci DPH nedostává. `own_bank_account` (#166 U1):
 * `false` = doklad vlastní bankovní účet nenese (`cmnbkp`, `cash`,
 * `cashreg`). `series_binding: "cash_desk"` = typ vázaný na pokladnu
 * (výchozí úhrada Hotově, měna pokladny). Výchozí hodnoty nového dokladu
 * z nich skládá `Defaults\DocHeadDefaults` — formulář ani pokyn se podle
 * typu nevětví samy.
 *
 * Směr obchodu má vlastní autoritu `DocDocument::resolveTradeDir()`.
 */
final class DocTypes
{
    /**
     * Bez configu, u neznámého typu i bez atributu = typ bez opravných
     * dokladů; s nimi jen při explicitním `true`.
     */
    public static function isCorrective(?ConfigRuntime $config, string $docType): bool
    {
        $docTypes = $config?->cfgItem('docs.core.docTypes');
        if (!is_array($docTypes) || !is_array($docTypes[$docType] ?? null)) {
            return false;
        }
        return ($docTypes[$docType]['corrective'] ?? false) === true;
    }

    /**
     * Bez configu, u neznámého typu i bez atributu = typ bez automatiky;
     * s automatikou jen při explicitním `true`.
     */
    public static function hasTotalRoundingAuto(?ConfigRuntime $config, string $docType): bool
    {
        $docTypes = $config?->cfgItem('docs.core.docTypes');
        if (!is_array($docTypes) || !is_array($docTypes[$docType] ?? null)) {
            return false;
        }
        return ($docTypes[$docType]['total_rounding_auto'] ?? false) === true;
    }

    /**
     * Bez configu, u neznámého typu i bez atributu = daňový doklad (dnešní
     * chování); nedaňový jen při explicitním `false`.
     */
    public static function isTaxDocument(?ConfigRuntime $config, string $docType): bool
    {
        $docTypes = $config?->cfgItem('docs.core.docTypes');
        if (!is_array($docTypes) || !is_array($docTypes[$docType] ?? null)) {
            return true;
        }
        return ($docTypes[$docType]['tax_document'] ?? true) !== false;
    }

    /**
     * Typ s DPH? Bez configu, u neznámého typu i bez atributu ano (dnešní
     * chování); bez DPH jen při explicitním `vat: false`.
     */
    public static function hasVat(?ConfigRuntime $config, string $docType): bool
    {
        return (self::attributes($config, $docType)['vat'] ?? true) !== false;
    }

    /**
     * Nese doklad vlastní bankovní účet? Bez configu, u neznámého typu i bez
     * atributu ano; ne jen při explicitním `own_bank_account: false`.
     */
    public static function usesOwnBankAccount(?ConfigRuntime $config, string $docType): bool
    {
        return (self::attributes($config, $docType)['own_bank_account'] ?? true) !== false;
    }

    /** Typ s řadou vázanou na pokladnu (`series_binding: "cash_desk"` — pokladní doklad, prodejka). */
    public static function isCashDeskBound(?ConfigRuntime $config, string $docType): bool
    {
        return (self::attributes($config, $docType)['series_binding'] ?? null) === 'cash_desk';
    }

    /** @return array<string, mixed> atributy typu; bez configu a u neznámého typu prázdné */
    private static function attributes(?ConfigRuntime $config, string $docType): array
    {
        $docTypes = $config?->cfgItem('docs.core.docTypes');
        return is_array($docTypes) && is_array($docTypes[$docType] ?? null) ? $docTypes[$docType] : [];
    }

    /**
     * Typy s `tax_document: false` — pro SQL pojistky (`doc_type NOT IN`).
     *
     * @return list<string>
     */
    public static function nonTaxDocTypes(?ConfigRuntime $config): array
    {
        $docTypes = $config?->cfgItem('docs.core.docTypes');
        if (!is_array($docTypes)) {
            return [];
        }
        $result = [];
        foreach ($docTypes as $key => $docType) {
            if (is_array($docType) && ($docType['tax_document'] ?? true) === false) {
                $result[] = (string) $key;
            }
        }
        return $result;
    }
}
