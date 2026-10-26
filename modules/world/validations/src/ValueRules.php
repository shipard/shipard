<?php

declare(strict_types=1);

namespace Shipard\Module\World\Validations;

/**
 * Jeden vstup pro volající řízené cestou nebo jménem tvaru (normalizátor
 * canonicalu, porovnávač evaluace, sdílený fixture): druh hodnoty +
 * země → normalizace a platnost. Konkrétní třídy ({@see Iban},
 * {@see Phone}, {@see DomesticAccount}, …) zůstávají pro přímé použití.
 */
final class ValueRules
{
    public const COUNTRY = 'country';
    public const ZIP = 'zip';
    public const COMPANY_ID = 'companyId';
    public const TAX_ID = 'taxId';
    public const VAT_ID = 'vatId';
    public const PHONE = 'phone';
    public const EMAIL = 'email';
    public const WEB = 'web';
    public const IBAN = 'iban';
    public const BIC = 'bic';
    public const ACCOUNT_NUMBER = 'accountNumber';
    public const SYMBOL = 'symbol';
    public const REFERENCE = 'reference';

    /** @return list<string> */
    public static function kinds(): array
    {
        return [
            self::COUNTRY, self::ZIP, self::COMPANY_ID, self::TAX_ID, self::VAT_ID, self::PHONE,
            self::EMAIL, self::WEB, self::IBAN, self::BIC, self::ACCOUNT_NUMBER, self::SYMBOL, self::REFERENCE,
        ];
    }

    /** Normalizovaný tvar; neznámý druh jen trim. */
    public static function normalize(string $kind, string $value, ?string $country = null): string
    {
        $rules = ValueRulesRegistry::forCountry($country);
        return match ($kind) {
            self::COUNTRY => Country::normalize($value),
            self::ZIP => $rules->normalizeZip($value),
            self::COMPANY_ID => $rules->normalizeCompanyId($value),
            self::TAX_ID => $rules->normalizeTaxId($value),
            self::VAT_ID => VatId::normalize($value, $rules),
            self::PHONE => Phone::normalize($value, $rules),
            self::EMAIL => Contact::email($value),
            self::WEB => Contact::web($value),
            self::IBAN => Iban::normalize($value),
            self::BIC => Bic::normalize($value),
            self::ACCOUNT_NUMBER => DomesticAccount::normalize($value),
            self::SYMBOL => PaymentSymbols::symbol($value),
            self::REFERENCE => PaymentSymbols::reference($value),
            default => trim($value),
        };
    }

    /**
     * Platnost normalizované hodnoty. Druhy bez pravidla (telefon, e-mail,
     * web, symboly, země, `taxId`) jsou vždy platné. Tuzemský účet se
     * pozná tvarem `…/kód` bez ohledu na zemi; stát s tuzemským tvarem
     * účtu neuznává jiný tvar.
     */
    public static function isValid(string $kind, string $value, ?string $country = null): bool
    {
        $rules = ValueRulesRegistry::forCountry($country);
        return match ($kind) {
            self::ZIP => $rules->isValidZip($value),
            self::COMPANY_ID => $rules->isValidCompanyId($value),
            self::VAT_ID => VatId::isValid($value),
            self::IBAN => Iban::isValid($value),
            self::BIC => Bic::isValid($value),
            self::ACCOUNT_NUMBER => self::isValidAccountNumber($value, $rules),
            default => true,
        };
    }

    private static function isValidAccountNumber(string $value, CountryValueRules $rules): bool
    {
        $account = DomesticAccount::parse($value);
        if ($account !== null) {
            return $account->isValid();
        }
        return !$rules->supportsDomesticAccount();
    }
}
