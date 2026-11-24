<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons\Values;

use Shipard\Module\World\Validations\Country;
use Shipard\Module\World\Validations\ValueRules;

/**
 * Čitelný tvar adresy složený ze sloupců (ulice, čísla, PSČ, obec) — pro
 * zobrazení, nikdy k uložení. Sdílí ho adresy osob (buňka sub-tabulky)
 * a adresa místa (base.places, #169 D13), aby se stejná adresa nepsala
 * dvojím způsobem.
 *
 * Řádek adresy nese sloupce jako `base_persons_addresses`: `street`,
 * `house_number`, `orientation_number`, `city`, `zip`, `country`.
 */
final class AddressFormat
{
    /**
     * „Ulice 12/3“: ulice + číslo popisné, orientační za lomítkem; bez
     * ulice jen čísla, bez čehokoli null.
     *
     * @param array<string, mixed> $row
     */
    public static function street(array $row): ?string
    {
        $street = trim((string) ($row['street'] ?? ''));
        $house  = trim((string) ($row['house_number'] ?? ''));
        $orient = trim((string) ($row['orientation_number'] ?? ''));
        $number = $house;
        if ($orient !== '') {
            $number = $number !== '' ? "{$number}/{$orient}" : $orient;
        }
        $text = trim("{$street} {$number}");
        return $text !== '' ? $text : null;
    }

    /**
     * „110 00 Praha“: PSČ v čitelném tvaru podle země adresy (bez ní podle
     * `$fallbackCountry`) a obec; null, když chybí obojí.
     *
     * @param array<string, mixed> $row
     */
    public static function city(array $row, ?string $fallbackCountry = null): ?string
    {
        $zip = trim((string) ($row['zip'] ?? ''));
        if ($zip !== '') {
            $country = PersonValues::text($row['country'] ?? null) ?? PersonValues::text($fallbackCountry);
            $zip = ValueRules::format(ValueRules::ZIP, $zip, $country !== null ? Country::normalize($country) : null);
        }
        $text = trim($zip . ' ' . trim((string) ($row['city'] ?? '')));
        return $text !== '' ? $text : null;
    }

    /**
     * Jednořádková adresa „Ulice 12/3, 110 00 Praha“; null bez ulice,
     * čísel, PSČ i obce. Zemi nevypisuje — má vlastní popisek.
     *
     * @param array<string, mixed> $row
     */
    public static function line(array $row, ?string $fallbackCountry = null): ?string
    {
        $parts = array_values(array_filter(
            [self::street($row), self::city($row, $fallbackCountry)],
            static fn (?string $part): bool => $part !== null,
        ));
        return $parts !== [] ? implode(', ', $parts) : null;
    }
}
