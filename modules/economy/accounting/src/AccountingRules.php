<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Docs\Core\OwnCompanyResolver;

/**
 * Účtovací předpis DS — jediné místo, kde se dohledává (cfgItem
 * `economy.accounting.rules.{country}` dle země vlastní firmy, fallback
 * `cz`), kde z něj vzniká efektivní předpis pro variantu osnovy zdroje
 * dat (sekce `chartOverrides`, #122 D1) a kde se z jeho sekce `accounts`
 * bere maska kategorie bez řádkového kontextu. Sdílí dokladový engine,
 * bankovní engine, contributoři deníku (#79 D3b) i zaúčtování přiznání
 * DPH (#122 D3), aby „účet se nikde nezadává, vzniká z masky předpisu“
 * mělo jeden zdroj pravdy.
 *
 * Země předpisu = účetní jurisdikce vlastní firmy (sídlo), ne země
 * registrace DPH: registrace řídí režim DPH dokladu (kódy, výkaz, správce
 * daně, analytiky kódů), knihy vede firma podle své osnovy.
 */
final class AccountingRules
{
    /** Parametr vrstvy C s variantou osnovy (`default` / `npo` / `none`). */
    public const SETTING_CHART_VARIANT = 'economy.accountChart';

    /**
     * Efektivní předpis dle země vlastní firmy (fallback cz) a varianty
     * osnovy zdroje dat; null bez konfigurace nebo bez předpisu.
     *
     * @return array<string, mixed>|null
     */
    public static function resolve(?ConfigRuntime $config, \Dibi\Connection $db): ?array
    {
        if ($config === null) {
            return null;
        }
        $address = (new OwnCompanyResolver($db))->getOwnHeadquartersAddress();
        $country = is_array($address) ? strtolower(trim((string) ($address['country'] ?? ''))) : '';
        $country = $country !== '' ? $country : 'cz';

        $rules = $config->cfgItem("economy.accounting.rules.{$country}");
        if (!is_array($rules)) {
            $rules = $config->cfgItem('economy.accounting.rules.cz');
        }
        if (!is_array($rules)) {
            return null;
        }
        return self::effective($rules, self::chartVariant($db));
    }

    /**
     * Efektivní předpis pro variantu osnovy (#122 D1): každá kategorie
     * uvedená v `chartOverrides.{varianta}.accounts` nahradí VŠECHNY obecné
     * záznamy téže kategorie v `accounts` (na místě prvního z nich; pořadí
     * ostatních kategorií se nemění), ostatní kategorie zůstávají. Sekce
     * `chartOverrides` se z výsledku odebere. Varianta bez přepisů
     * (`default`, `none`, nerozhodnuto) dostane předpis beze změny.
     *
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     * @throws \LogicException přepis uvádí kategorii, kterou obecný předpis nemá
     */
    public static function effective(array $rules, ?string $chartVariant): array
    {
        $overrides = $rules['chartOverrides'] ?? null;
        unset($rules['chartOverrides']);
        if ($chartVariant === null || !is_array($overrides)) {
            return $rules;
        }
        $variantAccounts = $overrides[$chartVariant]['accounts'] ?? null;
        if (!is_array($variantAccounts)) {
            return $rules;
        }

        $accounts = is_array($rules['accounts'] ?? null) ? array_values($rules['accounts']) : [];
        $known = [];
        foreach ($accounts as $entry) {
            if (is_array($entry) && is_string($entry['cat'] ?? null)) {
                $known[$entry['cat']] = true;
            }
        }

        /** @var array<string, list<array<string, mixed>>> $replacement */
        $replacement = [];
        foreach ($variantAccounts as $entry) {
            $cat = is_array($entry) ? ($entry['cat'] ?? null) : null;
            if (!is_string($cat) || $cat === '') {
                throw new \LogicException("Přepis osnovy '{$chartVariant}': záznam accounts bez kategorie (cat)");
            }
            if (!isset($known[$cat])) {
                throw new \LogicException(
                    "Přepis osnovy '{$chartVariant}' uvádí kategorii '{$cat}', kterou obecný předpis nemá",
                );
            }
            $replacement[$cat][] = $entry;
        }

        $result = [];
        $emitted = [];
        foreach ($accounts as $entry) {
            $cat = is_array($entry) ? ($entry['cat'] ?? null) : null;
            if (is_string($cat) && isset($replacement[$cat])) {
                if (!isset($emitted[$cat])) {
                    foreach ($replacement[$cat] as $override) {
                        $result[] = $override;
                    }
                    $emitted[$cat] = true;
                }
                continue;
            }
            $result[] = $entry;
        }
        $rules['accounts'] = $result;
        return $rules;
    }

    /**
     * Varianta osnovy zdroje dat (`economy.accountChart`, vrstva C); null
     * = nerozhodnuto nebo nečitelné → předpis bez přepisů.
     */
    private static function chartVariant(\Dibi\Connection $db): ?string
    {
        $variant = (new SettingsStore(new DataSourceConnection($db)))->get(self::SETTING_CHART_VARIANT);
        return is_string($variant) && $variant !== '' ? $variant : null;
    }

    /**
     * První maska v sekci `accounts` předpisu se shodnou kategorií. `query`
     * záznamu se tu nehodnotí (není řádek, nad kterým by se vyhodnotila);
     * řetěz masek (pole) dá první z nich. Chybějící kategorie → ''.
     *
     * @param array<string, mixed>|null $rules
     */
    public static function firstMaskForCategory(?array $rules, string $cat): string
    {
        foreach ($rules['accounts'] ?? [] as $entry) {
            if (is_array($entry) && ($entry['cat'] ?? null) === $cat) {
                $mask = $entry['accountMask'] ?? '';
                if (is_array($mask)) {
                    $mask = $mask[0] ?? '';
                }
                return (string) $mask;
            }
        }
        return '';
    }
}
