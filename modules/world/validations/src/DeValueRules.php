<?php

declare(strict_types=1);

namespace Shipard\Module\World\Validations;

/**
 * Německo: předvolba 49, národní předčíslí `0`, proměnlivá délka čísla;
 * PSČ 5 číslic; IČO (HRB apod.) jak na dokladu; USt-IdNr `DE` + 9 číslic
 * s kontrolní číslicí ISO 7064 MOD 11,10 (`checks.py::de_vat_check_digit`);
 * IBAN 22 znaků bez tuzemského tvaru účtu.
 */
final class DeValueRules extends AbstractCountryValueRules
{
    public const IBAN_LENGTH = 22;

    public function country(): string
    {
        return 'de';
    }

    public function phonePrefix(): ?string
    {
        return '49';
    }

    public function trunkPrefix(): ?string
    {
        return '0';
    }

    /** `+4930123456` → `+49 30123456` — skupiny německých čísel závisí na předčíslí oblasti, národní číslo zůstává vcelku. */
    public function formatPhone(string $phone): string
    {
        $prefix = '+' . $this->phonePrefix();
        if (!str_starts_with($phone, $prefix) || preg_match('/^[0-9]+$/', substr($phone, strlen($prefix))) !== 1) {
            return $phone;
        }
        return $prefix . ' ' . substr($phone, strlen($prefix));
    }

    public function normalizeZip(string $zip): string
    {
        return (string) preg_replace('/\s+/u', '', trim($zip));
    }

    public function isValidZip(string $zip): bool
    {
        return preg_match('/^[0-9]{5}$/', $zip) === 1;
    }

    public function vatIdPrefix(): ?string
    {
        return 'DE';
    }

    public function isValidVatNumber(string $number): bool
    {
        return preg_match('/^[0-9]{9}$/', $number) === 1
            && self::checkDigit(substr($number, 0, 8)) === (int) $number[8];
    }

    public function ibanLength(): ?int
    {
        return self::IBAN_LENGTH;
    }

    public function isValidIban(string $iban): bool
    {
        return strlen($iban) === self::IBAN_LENGTH;
    }

    /** ISO 7064 MOD 11,10 nad osmi číslicemi USt-IdNr. */
    public static function checkDigit(string $digits8): int
    {
        $product = 10;
        foreach (str_split($digits8) as $d) {
            $sum = ((int) $d + $product) % 10;
            if ($sum === 0) {
                $sum = 10;
            }
            $product = (2 * $sum) % 11;
        }
        return (11 - $product) % 10;
    }
}
