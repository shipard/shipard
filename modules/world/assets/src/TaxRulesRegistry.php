<?php

declare(strict_types=1);

namespace Shipard\Module\World\Assets;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Pravidla daňových odpisů podle státu zdroje dat
 * (`DataSourceConfig::getCountry()`).
 *
 * Stát dostane vlastní pravidla, jen když má třídu se vzorci i cfgItem
 * `world.assets.{country}`; jinak `AccountingOnlyTaxRules`.
 */
final class TaxRulesRegistry
{
    /** @var array<string, class-string<TaxDepreciationRules>> */
    private const CLASSES = [
        'cz' => CzTaxDepreciationRules::class,
    ];

    public static function forCountry(?ConfigRuntime $config, string $country): TaxDepreciationRules
    {
        $country = strtolower($country);
        $class = self::CLASSES[$country] ?? null;
        $cfg = $config?->cfgItem("world.assets.{$country}");

        if ($class === null || !is_array($cfg)) {
            return new AccountingOnlyTaxRules($country);
        }
        return new $class($cfg);
    }
}
