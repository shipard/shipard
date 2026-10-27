<?php

declare(strict_types=1);

namespace Shipard\Module\World\Validations;

/**
 * IBAN: velkými bez mezer; platnost = tvar, kontrolní číslice ISO 7064
 * MOD 97-10 a pravidlo státu podle prefixu (délka). Mod 11 na čísle účtu
 * uvnitř (cz, sk) je jen podezření (#118 D30) — {@see isValid()} ho
 * nevyžaduje, přísná {@see isValidWithDomesticAccount()} ano (dopočet
 * druhé hodnoty z redundance). Aritmetika po číslicích, bez `bcmath`.
 */
final class Iban
{
    public const SHAPE = '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/';

    public static function normalize(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/u', '', trim($iban)));
    }

    public static function hasShape(string $iban): bool
    {
        return preg_match(self::SHAPE, $iban) === 1;
    }

    /** Čitelný tvar po čtveřicích (`CZ65 0800 0000 1920 0014 5399`, D21); hodnota bez tvaru IBAN jen normalizovaná. */
    public static function format(string $iban): string
    {
        $s = self::normalize($iban);
        return self::hasShape($s) ? implode(' ', str_split($s, 4)) : $s;
    }

    /** Země z prefixu normalizovaného IBAN malými; bez tvaru IBAN `null`. */
    public static function countryOf(string $iban): ?string
    {
        return self::hasShape($iban) ? strtolower(substr($iban, 0, 2)) : null;
    }

    /** Tvar + MOD 97-10 + pravidlo státu (`CountryValueRules::isValidIban`, délka); číslo účtu uvnitř se nehodnotí. */
    public static function isValid(string $iban): bool
    {
        if (!self::hasShape($iban) || !self::checksumOk($iban)) {
            return false;
        }
        return ValueRulesRegistry::forIban($iban)->isValidIban($iban);
    }

    /**
     * Platný IBAN, jehož číslo účtu uvnitř (stát s tuzemským tvarem — cz, sk)
     * navíc projde mod 11; u ostatních států totéž co {@see isValid()}.
     * Jen pro dopočet druhé hodnoty z redundance (D4, D27, D29) — nikdy
     * z podezřelého čísla.
     */
    public static function isValidWithDomesticAccount(string $iban): bool
    {
        if (!self::isValid($iban)) {
            return false;
        }
        $rules = ValueRulesRegistry::forIban($iban);
        return !$rules->supportsDomesticAccount() || ($rules->domesticAccountFromIban($iban)?->isValid() ?? false);
    }

    /** Jen tvar a MOD 97-10, bez pravidla státu. */
    public static function checksumOk(string $iban): bool
    {
        return self::hasShape($iban) && self::mod97(self::toDigits(substr($iban, 4) . substr($iban, 0, 4))) === 1;
    }

    /** Kontrolní číslice pro zemi a BBAN (ISO 7064 MOD 97-10). */
    public static function checkDigits(string $country, string $bban): string
    {
        $remainder = self::mod97(self::toDigits($bban . strtoupper($country) . '00'));
        return str_pad((string) (98 - $remainder), 2, '0', STR_PAD_LEFT);
    }

    private static function toDigits(string $alnum): string
    {
        $out = '';
        foreach (str_split($alnum) as $ch) {
            $out .= ctype_alpha($ch) ? (string) (ord($ch) - ord('A') + 10) : $ch;
        }
        return $out;
    }

    private static function mod97(string $digits): int
    {
        $remainder = 0;
        foreach (str_split($digits) as $d) {
            $remainder = ($remainder * 10 + (int) $d) % 97;
        }
        return $remainder;
    }
}
