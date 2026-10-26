<?php

declare(strict_types=1);

namespace Shipard\Module\World\Validations;

/**
 * Kontroly platnosti jednotlivých hodnot s důvodem nálezu (#118 D13, D19).
 * Hodnotu nemění a čeká ji **normalizovanou** (volající normalizuje stejně
 * jako při uložení). Kde pravidlo není (stát bez třídy, telefon, e-mail,
 * web, `taxId`), nález nevzniká. Volá je validátor canonicalu
 * (`core.exchange`) i formuláře adresáře (`base.persons`,
 * `economy.codebooks`) — texty jsou tak na jednom místě.
 */
final class ValueChecks
{
    /** Popis tvaru čísla DIČ za prefixem u států s třídou (do zprávy nálezu). */
    private const VAT_NUMBER_RULES = [
        'CZ' => '8–10 číslic',
        'SK' => '10 číslic',
        'DE' => '9 číslic s kontrolní číslicí',
    ];

    public static function companyId(string $companyId, CountryValueRules $rules): ?ValueFinding
    {
        if ($rules->isValidCompanyId($companyId)) {
            return null;
        }
        $reason = preg_match('/^[0-9]{8}$/', $companyId) === 1 ? 'nemá platný kontrolní součet' : 'nemá tvar osmi číslic';
        return new ValueFinding(ValueRules::COMPANY_ID, ValueFinding::COMPANY_ID_INVALID, $companyId, $reason);
    }

    public static function vatId(string $vatId): ?ValueFinding
    {
        if (VatId::isValid($vatId)) {
            return null;
        }
        $prefix = VatId::prefixOf($vatId);
        $reason = $prefix !== null && isset(self::VAT_NUMBER_RULES[$prefix])
            ? "neodpovídá pravidlu státu {$prefix} (" . self::VAT_NUMBER_RULES[$prefix] . ')'
            : 'nemá tvar: prefix státu a 2–13 znaků';
        return new ValueFinding(ValueRules::VAT_ID, ValueFinding::VAT_ID_INVALID, $vatId, $reason);
    }

    /** Nesoulad DIČ a IČO (cz: osmimístné DIČ = `CZ` + IČO) — jen když jsou obě hodnoty platné. */
    public static function vatIdCompanyId(string $vatId, string $companyId, CountryValueRules $rules): ?ValueFinding
    {
        if (!VatId::isValid($vatId) || !$rules->isValidCompanyId($companyId)) {
            return null;
        }
        $implied = $rules->companyIdFromVatId($vatId);
        if ($implied === null || $implied === $companyId) {
            return null;
        }
        return new ValueFinding(
            ValueRules::VAT_ID,
            ValueFinding::VAT_ID_COMPANY_ID_MISMATCH,
            $vatId,
            "neodpovídá IČO „{$companyId}“ — osmimístné DIČ je {$rules->vatIdPrefix()} + IČO",
        );
    }

    /**
     * IČO, DIČ a jejich soulad jedné strany.
     *
     * @return list<ValueFinding>
     */
    public static function identity(?string $companyId, ?string $vatId, CountryValueRules $rules): array
    {
        $findings = [];
        if (self::present($companyId)) {
            $findings[] = self::companyId($companyId, $rules);
        }
        if (self::present($vatId)) {
            $findings[] = self::vatId($vatId);
        }
        if (self::present($companyId) && self::present($vatId)) {
            $findings[] = self::vatIdCompanyId($vatId, $companyId, $rules);
        }
        return array_values(array_filter($findings));
    }

    public static function zip(string $zip, CountryValueRules $rules): ?ValueFinding
    {
        if ($rules->isValidZip($zip)) {
            return null;
        }
        return new ValueFinding(ValueRules::ZIP, ValueFinding::ZIP_INVALID, $zip, "nemá pět číslic ({$rules->country()})");
    }

    /**
     * Tuzemský tvar se pozná bez ohledu na zemi (mod 11); jiný tvar je nález
     * jen u strany ze státu s tuzemským tvarem účtu (`$domesticExpected`).
     */
    public static function accountNumber(string $accountNumber, bool $domesticExpected): ?ValueFinding
    {
        $account = DomesticAccount::parse($accountNumber);
        if ($account !== null) {
            return $account->isValid()
                ? null
                : new ValueFinding(ValueRules::ACCOUNT_NUMBER, ValueFinding::ACCOUNT_NUMBER_INVALID, $accountNumber, 'nesplňuje kontrolu mod 11');
        }
        return $domesticExpected
            ? new ValueFinding(ValueRules::ACCOUNT_NUMBER, ValueFinding::ACCOUNT_NUMBER_INVALID, $accountNumber, 'nemá tvar [předčíslí-]číslo/kód banky')
            : null;
    }

    public static function iban(string $iban): ?ValueFinding
    {
        if (Iban::isValid($iban)) {
            return null;
        }
        if (!Iban::hasShape($iban)) {
            $reason = 'nemá tvar IBAN';
        } elseif (!Iban::checksumOk($iban)) {
            $reason = 'nemá platnou kontrolní číslici';
        } else {
            $reason = 'neodpovídá pravidlu státu ' . strtoupper((string) Iban::countryOf($iban)) . ' (délka nebo číslo účtu uvnitř)';
        }
        return new ValueFinding(ValueRules::IBAN, ValueFinding::IBAN_INVALID, $iban, $reason);
    }

    public static function bic(string $bic): ?ValueFinding
    {
        return Bic::isValid($bic)
            ? null
            : new ValueFinding(ValueRules::BIC, ValueFinding::BIC_INVALID, $bic, 'nemá tvar 8 nebo 11 znaků');
    }

    /** Nesoulad IBAN a tuzemského čísla účtu — jen když jsou obě hodnoty platné. */
    public static function ibanAccount(string $iban, string $accountNumber): ?ValueFinding
    {
        $account = DomesticAccount::parse($accountNumber);
        if ($account === null || !$account->isValid() || !Iban::isValid($iban)) {
            return null;
        }
        $fromIban = ValueRulesRegistry::forIban($iban)->domesticAccountFromIban($iban);
        if ($fromIban === null || $fromIban->toBban() === $account->toBban()) {
            return null;
        }
        return new ValueFinding(
            ValueRules::IBAN,
            ValueFinding::IBAN_ACCOUNT_MISMATCH,
            $iban,
            "neodpovídá číslu účtu „{$accountNumber}“ — podle IBAN je účet „{$fromIban}“",
        );
    }

    /**
     * Číslo účtu, IBAN, BIC a soulad IBAN × číslo účtu jednoho spojení.
     *
     * @return list<ValueFinding>
     */
    public static function bankAccount(?string $accountNumber, ?string $iban, ?string $bic, bool $domesticExpected): array
    {
        $findings = [];
        if (self::present($accountNumber)) {
            $findings[] = self::accountNumber($accountNumber, $domesticExpected);
        }
        if (self::present($iban)) {
            $findings[] = self::iban($iban);
        }
        if (self::present($bic)) {
            $findings[] = self::bic($bic);
        }
        if (self::present($iban) && self::present($accountNumber)) {
            $findings[] = self::ibanAccount($iban, $accountNumber);
        }
        return array_values(array_filter($findings));
    }

    /** @phpstan-assert-if-true string $value */
    private static function present(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
