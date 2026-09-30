<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation;

/** Zaokrouhlení a zápis částek účetního okruhu (D36). */
final class Amounts
{
    /** Rozdíl, pod kterým se dvě částky považují za shodné. */
    public const EPSILON = 0.005;

    /**
     * Nahoru na celé koruny. Hodnota se nejdřív srovná na 4 místa, jinak
     * chyba plovoucí čárky přidá korunu navíc (viz CzTaxDepreciationRules).
     */
    public static function ceil(float $amount): float
    {
        return ceil(round($amount, 4));
    }

    public static function isWhole(float $amount): bool
    {
        return abs(self::ceil($amount) - $amount) < self::EPSILON;
    }

    public static function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ');
    }
}
