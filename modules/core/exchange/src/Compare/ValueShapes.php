<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Compare;

use Shipard\Module\World\Validations\ValueRules;

/**
 * Tvary hodnot (docs/test-corpus.md § 11, #103 D15) — kanonický tvar, na
 * který porovnávač srovná obě strany před porovnáním. Sdílené tvary
 * (země, DIČ, IBAN, BIC, e-mail, web, telefon, PSČ a IČO cz / sk, číslo
 * účtu, symboly) delegují na produkční knihovnu `world.validations`
 * (#118 D6) — porovnávač tak měří stejnou normalizaci, jakou dostane
 * canonical v produkci, a `matchNormalized` u těchto polí hlídá regresi.
 * Pravidla jdou podle jména tvaru ze `shapes` projekce; neznámé jméno
 * dostane obecné pravidlo druhu (regex → trim, check → číslo).
 *
 * Telefon zůstává bez země (rodič listu je `contact`): předvolbu doplní
 * produkce ještě před porovnáním. `digits5` / `digits8` jsou tvary PSČ
 * a IČO jen pro cz / sk (`byCountry`), země z rodičovského objektu.
 */
final class ValueShapes
{
    /**
     * @param array<string, mixed> $projected strom z {@see Projection::project()}
     * @return array<string, mixed>
     */
    public static function normalize(Projection $projection, array $projected): array
    {
        return $projection->map(
            $projected,
            static function (?string $shapeName, mixed $value, ?array $parent) use ($projection): mixed {
                if ($shapeName === null) {
                    return $value;
                }
                [$name, $shape] = $projection->shapeOf($shapeName, $parent);
                $country = is_string($parent['country'] ?? null) ? $parent['country'] : null;
                return self::normalizeValue($name, $shape, $value, $country);
            },
        );
    }

    /**
     * @param array<string, mixed> $shape   konkrétní tvar (po rozpuštění byCountry)
     * @param ?string              $country země rodičovského objektu (PSČ, IČO); bez ní cz / sk pravidla
     */
    public static function normalizeValue(string $name, array $shape, mixed $value, ?string $country = null): mixed
    {
        if ($value === null || array_key_exists('const', $shape) || is_array($value) || is_bool($value)) {
            return $value;
        }
        if (isset($shape['check'])) {
            return self::normalizeNumber((string) $shape['check'], $value);
        }
        if (!is_string($value)) {
            return $value;
        }
        $s = trim($value);
        return match ($name) {
            'country' => ValueRules::normalize(ValueRules::COUNTRY, $s),
            'currency' => strtoupper($s),
            'bic' => ValueRules::normalize(ValueRules::BIC, $s),
            'vatId' => ValueRules::normalize(ValueRules::VAT_ID, $s),
            'iban' => ValueRules::normalize(ValueRules::IBAN, $s),
            'email' => ValueRules::normalize(ValueRules::EMAIL, $s),
            'phone' => ValueRules::normalize(ValueRules::PHONE, $s),
            'web' => ValueRules::normalize(ValueRules::WEB, $s),
            'digits5' => ValueRules::normalize(ValueRules::ZIP, $s, $country ?? 'cz'),
            'digits8' => ValueRules::normalize(ValueRules::COMPANY_ID, $s, $country ?? 'cz'),
            'symbol', 'ean' => ValueRules::normalize(ValueRules::SYMBOL, $s),
            'reference' => ValueRules::normalize(ValueRules::REFERENCE, $s),
            'accountNumber' => ValueRules::normalize(ValueRules::ACCOUNT_NUMBER, $s),
            default => $s,
        };
    }

    private static function normalizeNumber(string $check, mixed $value): mixed
    {
        if (!is_numeric($value)) {
            return $value;
        }
        return match ($check) {
            'amount' => round((float) $value, 2),
            'index' => (int) $value,
            'number' => (float) $value,
            default => $value,
        };
    }
}
