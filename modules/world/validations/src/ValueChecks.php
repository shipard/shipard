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
 *
 * Důvod rozlišuje příčinu (D27) a složené kontroly ({@see identity},
 * {@see bankAccount}) přidají k nálezu **návrh opravy** z redundance,
 * kde druhá hodnota je platná: IČO z osmimístného českého DIČ, tuzemské
 * číslo účtu z platného IBAN a IBAN z platného tuzemského čísla (jako
 * oprava D4 v canonicalu). Návrh musí sám projít validací; nic se
 * neopravuje.
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
        $findings = array_values(array_filter($findings));

        $implied = self::present($vatId) ? self::companyIdImpliedByVatId($vatId, $rules) : null;
        if ($implied !== null) {
            foreach ($findings as $i => $finding) {
                if ($finding->code === ValueFinding::COMPANY_ID_INVALID) {
                    $findings[$i] = $finding->withSuggestion($implied);
                }
            }
        }
        return $findings;
    }

    /** IČO vyplývající z platného DIČ (cz: osmimístné = `CZ` + IČO), jen když samo projde kontrolou. */
    private static function companyIdImpliedByVatId(string $vatId, CountryValueRules $rules): ?string
    {
        if (!VatId::isValid($vatId)) {
            return null;
        }
        $implied = $rules->companyIdFromVatId($vatId);
        return $implied !== null && $rules->isValidCompanyId($implied) ? $implied : null;
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
            ? new ValueFinding(ValueRules::ACCOUNT_NUMBER, ValueFinding::ACCOUNT_NUMBER_INVALID, $accountNumber, self::accountShapeReason($accountNumber))
            : null;
    }

    /** Proč hodnota není tuzemské číslo účtu (D27): cizí znaky, chybějící kód banky, jiný tvar. */
    private static function accountShapeReason(string $accountNumber): string
    {
        $compact = (string) preg_replace('/\s+/u', '', $accountNumber);
        if (preg_match('/[^0-9\/-]/', $compact) === 1) {
            return 'obsahuje nepovolené znaky (jen číslice, pomlčka a lomítko)';
        }
        if (!str_contains($compact, '/')) {
            return 'nemá kód banky (tvar [předčíslí-]číslo/kód banky)';
        }
        return 'nemá tvar [předčíslí-]číslo/kód banky';
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
            $reason = self::ibanCountryReason($iban, ValueRulesRegistry::forIban($iban));
        }
        return new ValueFinding(ValueRules::IBAN, ValueFinding::IBAN_INVALID, $iban, $reason);
    }

    /** Kontrolní číslice sedí, pravidlo státu ne (D27): chybná délka, nebo číslo účtu uvnitř bez mod 11 (cz / sk). */
    private static function ibanCountryReason(string $iban, CountryValueRules $rules): string
    {
        $country = strtoupper((string) Iban::countryOf($iban));
        $length = $rules->ibanLength();
        if ($length !== null && strlen($iban) !== $length) {
            return "má pro stát {$country} chybnou délku ({$length} znaků, zapsáno " . strlen($iban) . ')';
        }
        if ($rules->supportsDomesticAccount()) {
            $account = $rules->domesticAccountFromIban($iban);
            return 'má platnou kontrolní číslici, ale číslo účtu uvnitř'
                . ($account !== null ? " „{$account}“" : '') . ' neprojde kontrolou mod 11';
        }
        return "neodpovídá pravidlu státu {$country}";
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
        $findings = array_values(array_filter($findings));

        if (self::present($iban) && self::present($accountNumber)) {
            foreach ($findings as $i => $finding) {
                $suggestion = match ($finding->code) {
                    ValueFinding::ACCOUNT_NUMBER_INVALID => self::accountNumberImpliedByIban($iban),
                    ValueFinding::IBAN_INVALID           => self::ibanImpliedByAccountNumber($accountNumber, $iban),
                    default                              => null,
                };
                if ($suggestion !== null) {
                    $findings[$i] = $finding->withSuggestion($suggestion);
                }
            }
        }
        return $findings;
    }

    /** Tuzemské číslo z platného IBAN státu s tuzemským tvarem — jen když samo projde mod 11. */
    private static function accountNumberImpliedByIban(string $iban): ?string
    {
        if (!Iban::isValid($iban)) {
            return null;
        }
        $account = ValueRulesRegistry::forIban($iban)->domesticAccountFromIban($iban);
        return $account !== null && $account->isValid() ? (string) $account : null;
    }

    /**
     * IBAN z platného tuzemského čísla účtu podle státu z prefixu neplatného
     * IBAN (cz / sk; bez rozpoznatelného prefixu bez návrhu) — jen když je
     * sám platný.
     */
    private static function ibanImpliedByAccountNumber(string $accountNumber, string $invalidIban): ?string
    {
        $account = DomesticAccount::parse($accountNumber);
        if ($account === null || !$account->isValid()) {
            return null;
        }
        $rules = ValueRulesRegistry::forIban($invalidIban);
        if (!$rules->supportsDomesticAccount()) {
            return null;
        }
        $derived = $rules->ibanFromDomesticAccount($account);
        return $derived !== null && Iban::isValid($derived) ? $derived : null;
    }

    /** @phpstan-assert-if-true string $value */
    private static function present(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
