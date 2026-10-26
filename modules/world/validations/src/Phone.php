<?php

declare(strict_types=1);

namespace Shipard\Module\World\Validations;

/**
 * Telefon (#118 D8, D12): `+` a číslice. Mezery a interpunkce pryč,
 * `(0)` pryč, `00` na začátku → `+` (jen když za ním nenásleduje `0` —
 * taková čísla jsou národní zápis, ne mezinárodní). Národní číslo bez
 * `+` u státu s třídou: zahodit národní předčíslí a doplnit předvolku
 * státu; číslo začínající předvolkou státu v plné délce se bere jako
 * mezinárodní bez `+`. U státu bez třídy zůstává bez předvolby —
 * bez znalosti národního předčíslí by vznikla chybná čísla.
 * Telefon nálezy nevyrábí.
 */
final class Phone
{
    public static function normalize(string $phone, ?CountryValueRules $rules = null): string
    {
        $s = trim($phone);
        $international = str_starts_with($s, '+');
        $digits = (string) preg_replace('/[^0-9]/', '', (string) preg_replace('/\(0\)/', '', $s));
        if ($digits === '') {
            return $s;
        }
        if ($international) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '00') && ($digits[2] ?? '0') !== '0') {
            return '+' . substr($digits, 2);
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
        $trunk = $rules->trunkPrefix();
        if ($trunk !== null && str_starts_with($digits, $trunk)) {
            $digits = substr($digits, strlen($trunk));
        }
        return '+' . $prefix . $digits;
    }
}
