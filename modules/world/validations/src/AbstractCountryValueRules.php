<?php

declare(strict_types=1);

namespace Shipard\Module\World\Validations;

/**
 * Výchozí chování pravidel státu: nic nehádat — trim, žádná kontrola,
 * žádná předvolba, žádný převod účtu. Třída státu přepisuje jen to,
 * co stát skutečně určuje.
 */
abstract class AbstractCountryValueRules implements CountryValueRules
{
    private const VAT_NUMBER_SHAPE = '/^[0-9A-Z]{2,13}$/';

    public function isGeneric(): bool
    {
        return false;
    }

    public function phonePrefix(): ?string
    {
        return null;
    }

    public function trunkPrefix(): ?string
    {
        return null;
    }

    public function nationalNumberLength(): ?int
    {
        return null;
    }

    public function formatPhone(string $phone): string
    {
        return $phone;
    }

    public function normalizeZip(string $zip): string
    {
        return trim($zip);
    }

    public function isValidZip(string $zip): bool
    {
        return true;
    }

    public function formatZip(string $zip): string
    {
        return $zip;
    }

    public function normalizeCompanyId(string $companyId): string
    {
        return trim($companyId);
    }

    public function isValidCompanyId(string $companyId): bool
    {
        return true;
    }

    public function normalizeTaxId(string $taxId): string
    {
        return trim($taxId);
    }

    public function vatIdPrefix(): ?string
    {
        return null;
    }

    public function completeVatId(string $digits): ?string
    {
        return null;
    }

    public function isValidVatNumber(string $number): bool
    {
        return preg_match(self::VAT_NUMBER_SHAPE, $number) === 1;
    }

    public function companyIdFromVatId(string $vatId): ?string
    {
        return null;
    }

    public function supportsDomesticAccount(): bool
    {
        return false;
    }

    public function ibanLength(): ?int
    {
        return null;
    }

    public function isValidIban(string $iban): bool
    {
        return true;
    }

    public function ibanFromDomesticAccount(DomesticAccount $account): ?string
    {
        return null;
    }

    public function domesticAccountFromIban(string $iban): ?DomesticAccount
    {
        return null;
    }
}
