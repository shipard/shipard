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
 * oprava D4 v canonicalu). Návrh musí sám projít validací **včetně
 * mod 11**; nic se neopravuje.
 *
 * **Mod 11 je podezření, ne jistá chyba** (D30): tuzemské číslo účtu se
 * správným tvarem, které neprojde mod 11, dostane
 * `account_number_checksum` — jednou na účet: u čísla účtu, nebo (jen
 * s IBAN) u IBAN. IBAN sám je platný s tvarem, kontrolní číslicí a délkou
 * státu. Podezření nikdy nenese návrh opravy a z podezřelé hodnoty se
 * nic nedopočítává.
 */
final class ValueChecks
{
    /** Důvod podezření (D30) — za podmětem, bez tečky. */
    private const CHECKSUM_REASON = 'neprojde kontrolou mod 11 — ověřte ho';

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
     * Tuzemský tvar se pozná bez ohledu na zemi; jiný tvar je nález jen
     * u strany ze státu s tuzemským tvarem účtu (`$domesticExpected`).
     * Neprojitý mod 11 je podezření (`account_number_checksum`, D30),
     * číslo ze samých nul jistá chyba.
     */
    public static function accountNumber(string $accountNumber, bool $domesticExpected): ?ValueFinding
    {
        $account = DomesticAccount::parse($accountNumber);
        if ($account !== null) {
            if ($account->number === '0') {
                return new ValueFinding(ValueRules::ACCOUNT_NUMBER, ValueFinding::ACCOUNT_NUMBER_INVALID, $accountNumber, 'má číslo účtu ze samých nul');
            }
            return $account->checksumOk()
                ? null
                : new ValueFinding(ValueRules::ACCOUNT_NUMBER, ValueFinding::ACCOUNT_NUMBER_CHECKSUM, $accountNumber, self::CHECKSUM_REASON);
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

    /** Jisté chyby IBAN: tvar, kontrolní číslice, pravidlo státu (délka). Číslo účtu uvnitř hodnotí {@see ibanAccountChecksum()}. */
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

    /** Kontrolní číslice sedí, pravidlo státu ne (D27): chybná délka. */
    private static function ibanCountryReason(string $iban, CountryValueRules $rules): string
    {
        $country = strtoupper((string) Iban::countryOf($iban));
        $length = $rules->ibanLength();
        if ($length !== null && strlen($iban) !== $length) {
            return "má pro stát {$country} chybnou délku ({$length} znaků, zapsáno " . strlen($iban) . ')';
        }
        return "neodpovídá pravidlu státu {$country}";
    }

    /**
     * Platný IBAN státu s tuzemským tvarem (cz, sk), jehož číslo účtu uvnitř
     * neprojde mod 11 — podezření `account_number_checksum` u IBAN (D30).
     * Tvar, kontrolní číslici a délku hodnotí {@see iban()}.
     */
    public static function ibanAccountChecksum(string $iban): ?ValueFinding
    {
        if (!Iban::isValid($iban)) {
            return null;
        }
        $account = ValueRulesRegistry::forIban($iban)->domesticAccountFromIban($iban);
        if ($account === null || $account->checksumOk()) {
            return null;
        }
        return new ValueFinding(
            ValueRules::IBAN,
            ValueFinding::ACCOUNT_NUMBER_CHECKSUM,
            $iban,
            "má platnou kontrolní číslici, ale číslo účtu uvnitř „{$account}“ " . self::CHECKSUM_REASON,
        );
    }

    public static function bic(string $bic): ?ValueFinding
    {
        return Bic::isValid($bic)
            ? null
            : new ValueFinding(ValueRules::BIC, ValueFinding::BIC_INVALID, $bic, 'nemá tvar 8 nebo 11 znaků');
    }

    /**
     * Nesoulad IBAN a tuzemského čísla účtu — IBAN platný, číslo s tuzemským
     * tvarem; mod 11 nerozhoduje (D30): dva různé účty jsou jistá chyba.
     */
    public static function ibanAccount(string $iban, string $accountNumber): ?ValueFinding
    {
        $account = DomesticAccount::parse($accountNumber);
        if ($account === null || !Iban::isValid($iban)) {
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
     * Podezření na číslo účtu (D30) jednou na účet: u čísla účtu; u IBAN jen
     * bez čísla účtu, nebo když IBAN nese jiný účet než zapsané číslo.
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
            if (!self::present($accountNumber) || !self::sameAccount($iban, $accountNumber)) {
                $findings[] = self::ibanAccountChecksum($iban);
            }
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

    /** IBAN a tuzemské číslo nesou týž účet (stejný BBAN) — podezření se pak hlásí jen u čísla. */
    private static function sameAccount(string $iban, string $accountNumber): bool
    {
        $account = DomesticAccount::parse($accountNumber);
        if ($account === null || !Iban::hasShape($iban)) {
            return false;
        }
        $fromIban = ValueRulesRegistry::forIban($iban)->domesticAccountFromIban($iban);
        return $fromIban !== null && $fromIban->toBban() === $account->toBban();
    }

    /** Tuzemské číslo z IBAN, který projde vším včetně mod 11 uvnitř (D30) — z podezřelého čísla nic. */
    private static function accountNumberImpliedByIban(string $iban): ?string
    {
        if (!Iban::isValidWithDomesticAccount($iban)) {
            return null;
        }
        $account = ValueRulesRegistry::forIban($iban)->domesticAccountFromIban($iban);
        return $account !== null ? (string) $account : null;
    }

    /**
     * IBAN z tuzemského čísla účtu, které projde vším včetně mod 11, podle
     * státu z prefixu neplatného IBAN (cz / sk; bez rozpoznatelného prefixu
     * bez návrhu) — jen když je sám platný.
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
        return $derived !== null && Iban::isValidWithDomesticAccount($derived) ? $derived : null;
    }

    /** @phpstan-assert-if-true string $value */
    private static function present(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
