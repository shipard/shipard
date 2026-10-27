<?php

declare(strict_types=1);

namespace Shipard\Module\World\Validations;

/**
 * Společná pravidla Česka a Slovenska: osmimístné IČO s kontrolním
 * součtem, pětimístné PSČ, tuzemský účet `[předčíslí-]číslo/kód banky`
 * s mod 11 (neprojití je podezření, ne neplatnost — #118 D30) a BBAN =
 * kód 4 + předčíslí 6 + číslo 10 (IBAN 24 znaků), DIČ s prefixem státu.
 * Liší se jen předvolba, národní předčíslí a tvar DIČ.
 */
abstract class CzSkValueRulesBase extends AbstractCountryValueRules
{
    public const IBAN_LENGTH = 24;

    /** Váhy kontrolního součtu IČO pro prvních sedm číslic. */
    private const COMPANY_ID_WEIGHTS = [8, 7, 6, 5, 4, 3, 2];

    public function nationalNumberLength(): ?int
    {
        return 9;
    }

    /** `+420577123456` → `+420 577 123 456`; jiný tvar (jiný stát, jiná délka) beze změny. */
    public function formatPhone(string $phone): string
    {
        $prefix = '+' . $this->phonePrefix();
        if (!str_starts_with($phone, $prefix) || preg_match('/^[0-9]{9}$/', substr($phone, strlen($prefix))) !== 1) {
            return $phone;
        }
        return $prefix . ' ' . implode(' ', str_split(substr($phone, strlen($prefix)), 3));
    }

    public function normalizeZip(string $zip): string
    {
        return (string) preg_replace('/\s+/u', '', trim($zip));
    }

    public function isValidZip(string $zip): bool
    {
        return preg_match('/^[0-9]{5}$/', $zip) === 1;
    }

    /** `76001` → `760 01`; jiný tvar beze změny. */
    public function formatZip(string $zip): string
    {
        return $this->isValidZip($zip) ? substr($zip, 0, 3) . ' ' . substr($zip, 3) : $zip;
    }

    /** Bez mezer; samé číslice do osmi míst doplní vedoucí nuly, jiný obsah zůstává. */
    public function normalizeCompanyId(string $companyId): string
    {
        $s = (string) preg_replace('/\s+/u', '', trim($companyId));
        if ($s !== '' && strlen($s) <= 8 && ctype_digit($s)) {
            return str_pad($s, 8, '0', STR_PAD_LEFT);
        }
        return $s;
    }

    public function isValidCompanyId(string $companyId): bool
    {
        if (preg_match('/^[0-9]{8}$/', $companyId) !== 1) {
            return false;
        }
        $sum = 0;
        foreach (self::COMPANY_ID_WEIGHTS as $i => $weight) {
            $sum += ((int) $companyId[$i]) * $weight;
        }
        return (11 - $sum % 11) % 10 === (int) $companyId[7];
    }

    /** DIČ je zároveň národní daňové číslo (#103 D115). */
    public function normalizeTaxId(string $taxId): string
    {
        return VatId::normalize($taxId, $this);
    }

    public function vatIdPrefix(): ?string
    {
        return strtoupper($this->country());
    }

    public function supportsDomesticAccount(): bool
    {
        return true;
    }

    public function ibanLength(): ?int
    {
        return self::IBAN_LENGTH;
    }

    /** Jen délka 24 — mod 11 na čísle účtu uvnitř je podezření, ne neplatnost (D30; přísně `Iban::isValidWithDomesticAccount()`). */
    public function isValidIban(string $iban): bool
    {
        return strlen($iban) === self::IBAN_LENGTH;
    }

    public function ibanFromDomesticAccount(DomesticAccount $account): ?string
    {
        $country = strtoupper($this->country());
        $bban = $account->toBban();
        return $country . Iban::checkDigits($country, $bban) . $bban;
    }

    public function domesticAccountFromIban(string $iban): ?DomesticAccount
    {
        if (strlen($iban) !== self::IBAN_LENGTH || strtolower(substr($iban, 0, 2)) !== $this->country()) {
            return null;
        }
        return DomesticAccount::fromBban(substr($iban, 4));
    }
}
