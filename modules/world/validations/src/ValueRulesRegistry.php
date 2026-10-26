<?php

declare(strict_types=1);

namespace Shipard\Module\World\Validations;

/**
 * Pravidla hodnot podle státu (vzor `TaxRulesRegistry` ve `world.assets`).
 * Stát bez třídy, neznámá nebo chybějící země → {@see GenericValueRules}
 * (jen obecná pravidla). Nový stát = nová třída a záznam v {@see CLASSES},
 * jádro se nemění (D5).
 */
final class ValueRulesRegistry
{
    /** @var array<string, class-string<CountryValueRules>> */
    private const CLASSES = [
        'cz' => CzValueRules::class,
        'sk' => SkValueRules::class,
        'de' => DeValueRules::class,
    ];

    /** @var array<string, CountryValueRules> */
    private static array $instances = [];

    public static function forCountry(?string $country): CountryValueRules
    {
        $key = $country === null ? '' : Country::normalize($country);
        if (!isset(self::$instances[$key])) {
            $class = self::CLASSES[$key] ?? null;
            self::$instances[$key] = $class !== null ? new $class() : new GenericValueRules($key);
        }
        return self::$instances[$key];
    }

    /** Pravidla podle prefixu IBAN; IBAN bez rozpoznatelného prefixu → generická bez země. */
    public static function forIban(string $iban): CountryValueRules
    {
        return self::forCountry(Iban::countryOf(Iban::normalize($iban)));
    }

    /** Pravidla podle prefixu DIČ (`CZ` → cz); prefix bez třídy → generická. */
    public static function forVatPrefix(string $prefix): CountryValueRules
    {
        return self::forCountry($prefix);
    }

    public static function hasRules(?string $country): bool
    {
        return $country !== null && isset(self::CLASSES[Country::normalize($country)]);
    }

    /** @return list<string> státy s vlastní třídou */
    public static function countries(): array
    {
        return array_keys(self::CLASSES);
    }
}
