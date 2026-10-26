<?php

declare(strict_types=1);

namespace Shipard\Module\World\Validations;

/**
 * Telefon (#118 D8, D12, D26): `+` a číslice. Mezery a interpunkce pryč,
 * `(0)` pryč, `00` na začátku → `+` (jen když za ním nenásleduje `0` —
 * taková čísla jsou národní zápis, ne mezinárodní). Národní číslo bez
 * `+` u státu s třídou: zahodit národní předčíslí a doplnit předvolbu
 * státu; číslo začínající předvolbou státu v plné délce se bere jako
 * mezinárodní bez `+`. U státu bez třídy zůstává bez předvolby —
 * bez znalosti národního předčíslí by vznikla chybná čísla.
 *
 * Normalizuje se jen hodnota, která vypadá jako jedno číslo (D26);
 * jinak zůstává beze změny, jen oříznutá: víc než 15 číslic (E.164)
 * vždy; mezinárodní zápis jen se 7–15 číslicemi; národní zápis u státu
 * se známou délkou jen když po odebrání předčíslí délka sedí (cz, sk 9),
 * u státu bez délky (de) jen když výsledek padne do okna 7–15 číslic.
 * Dvě čísla v jednom poli ani číslo s linkou se tak neslijí; písmena
 * kolem jednoho čísla (`tel. 577 123 456`) normalizaci nebrání.
 * Telefon nálezy nevyrábí.
 */
final class Phone
{
    /** Okno délky čísla E.164 bez `+` (D26). */
    private const MIN_DIGITS = 7;
    private const MAX_DIGITS = 15;

    public static function normalize(string $phone, ?CountryValueRules $rules = null): string
    {
        $s = trim($phone);
        $digits = (string) preg_replace('/[^0-9]/', '', (string) preg_replace('/\(0\)/', '', $s));
        if ($digits === '' || strlen($digits) > self::MAX_DIGITS) {
            return $s;
        }
        if (str_starts_with($s, '+')) {
            return self::fits($digits) ? '+' . $digits : $s;
        }
        if (str_starts_with($digits, '00') && ($digits[2] ?? '0') !== '0') {
            $number = substr($digits, 2);
            return self::fits($number) ? '+' . $number : $s;
        }

        $prefix = $rules?->phonePrefix();
        if ($prefix === null) {
            return $digits;
        }
        $nationalLength = $rules->nationalNumberLength();
        if ($nationalLength !== null
            && str_starts_with($digits, $prefix)
            && strlen($digits) === strlen($prefix) + $nationalLength
        ) {
            return '+' . $digits;
        }
        $national = $digits;
        $trunk = $rules->trunkPrefix();
        if ($trunk !== null && str_starts_with($national, $trunk)) {
            $national = substr($national, strlen($trunk));
        }
        if ($nationalLength !== null) {
            return strlen($national) === $nationalLength ? '+' . $prefix . $national : $s;
        }
        return self::fits($prefix . $national) ? '+' . $prefix . $national : $s;
    }

    private static function fits(string $digits): bool
    {
        $length = strlen($digits);
        return $length >= self::MIN_DIGITS && $length <= self::MAX_DIGITS;
    }
}
