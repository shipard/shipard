<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Compare;

/**
 * Tvary hodnot (docs/test-corpus.md § 11, #103 D15) — kanonický tvar, na
 * který porovnávač srovná obě strany před porovnáním: země malými, měna
 * velkými, DIČ / IBAN / BIC velkými bez mezer, e-mail a web malými, telefon
 * `+` a číslice, PSČ a IČO cz / sk bez mezer, číslo účtu bez vedoucích nul,
 * částky na setiny, texty bez okrajových mezer. Pravidla jdou podle jména
 * tvaru ze `shapes` projekce; neznámé jméno dostane obecné pravidlo druhu
 * (regex → trim, check → číslo). Do dokončení #118 tím porovnávač nahrazuje
 * normalizaci v produkční cestě.
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
                return self::normalizeValue($name, $shape, $value);
            },
        );
    }

    /**
     * @param array<string, mixed> $shape konkrétní tvar (po rozpuštění byCountry)
     */
    public static function normalizeValue(string $name, array $shape, mixed $value): mixed
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
            'country' => strtolower($s),
            'currency', 'bic' => strtoupper($s),
            'vatId', 'iban' => strtoupper(self::noSpaces($s)),
            'email' => strtolower($s),
            'phone' => self::phone($s),
            'web' => self::web($s),
            'digits5', 'digits8', 'symbol', 'ean' => self::noSpaces($s),
            'accountNumber' => self::accountNumber($s),
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

    private static function noSpaces(string $s): string
    {
        return (string) preg_replace('/\s+/u', '', $s);
    }

    /** `+` a číslice; vedoucí `+` zůstává, jiné znaky (mezery, pomlčky, závorky) pryč. */
    private static function phone(string $s): string
    {
        $digits = (string) preg_replace('/[^0-9]/', '', $s);
        return (str_starts_with($s, '+') ? '+' : '') . $digits;
    }

    /** Malými, bez schématu a bez koncového lomítka. */
    private static function web(string $s): string
    {
        $s = strtolower($s);
        $s = (string) preg_replace('~^[a-z][a-z0-9+.-]*://~', '', $s);
        return rtrim($s, '/');
    }

    /**
     * `[předčíslí-]číslo/kód banky` bez mezer a bez vedoucích nul v předčíslí
     * a čísle; jiný tvar (zahraniční účet) jen bez mezer.
     */
    private static function accountNumber(string $s): string
    {
        $s = self::noSpaces($s);
        if (preg_match('~^(?:([0-9]+)-)?([0-9]+)/([0-9]{4})$~', $s, $m) !== 1) {
            return $s;
        }
        $prefix = $m[1] === '' ? null : self::stripZeros($m[1]);
        $number = self::stripZeros($m[2]);
        return ($prefix === null || $prefix === '0' ? '' : $prefix . '-') . $number . '/' . $m[3];
    }

    private static function stripZeros(string $digits): string
    {
        $out = ltrim($digits, '0');
        return $out === '' ? '0' : $out;
    }
}
