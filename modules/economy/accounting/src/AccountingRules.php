<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Docs\Core\OwnCompanyResolver;

/**
 * Účtovací předpis DS — jediné místo, kde se dohledává (cfgItem
 * `economy.accounting.rules.{country}` dle země vlastní firmy, fallback
 * `cz`) a kde se z jeho sekce `accounts` bere maska kategorie bez
 * řádkového kontextu. Sdílí dokladový engine, bankovní engine i
 * contributoři deníku (#79 D3b), aby „účet se nikde nezadává, vzniká
 * z masky předpisu“ mělo jeden zdroj pravdy.
 */
final class AccountingRules
{
    /**
     * Předpis dle země vlastní firmy, fallback cz; null bez konfigurace
     * nebo bez předpisu.
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
        return is_array($rules) ? $rules : null;
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
