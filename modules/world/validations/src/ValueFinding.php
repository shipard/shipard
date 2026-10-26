<?php

declare(strict_types=1);

namespace Shipard\Module\World\Validations;

/**
 * Nález validace jedné hodnoty (#118 D13, D19): kód, druh hodnoty, hodnota
 * a česká věta důvodu. Jediný zdroj textů nálezů — skládá z nich zprávu
 * validátor canonicalu (`IČO dodavatele „x“ nemá platný kontrolní
 * součet.`) i formuláře adresáře (`IČO „x“ nemá platný kontrolní součet.`).
 * Důvod rozlišuje příčinu (D27: u IBAN tvar / kontrolní číslice / délka /
 * číslo účtu uvnitř, u čísla účtu znaky / kód banky / mod 11).
 *
 * `suggestion` je **návrh opravy** téže hodnoty z redundance (IČO
 * z osmimístného českého DIČ, IBAN z platného tuzemského čísla účtu
 * a naopak — vzor D4), který {@see ValueChecks} přidá, kde to jde;
 * nikdy se neprovádí, jen vypisuje (`values-normalize --verbose`).
 * Do zprávy nálezu nepatří.
 */
final readonly class ValueFinding
{
    public const COMPANY_ID_INVALID = 'company_id_invalid';
    public const VAT_ID_INVALID = 'vat_id_invalid';
    public const VAT_ID_COMPANY_ID_MISMATCH = 'vat_id_company_id_mismatch';
    public const ZIP_INVALID = 'zip_invalid';
    public const ACCOUNT_NUMBER_INVALID = 'account_number_invalid';
    public const IBAN_INVALID = 'iban_invalid';
    public const BIC_INVALID = 'bic_invalid';
    public const IBAN_ACCOUNT_MISMATCH = 'iban_account_mismatch';

    /** @var list<string> kódy, které {@see ValueChecks} vyrábí */
    public const CODES = [
        self::COMPANY_ID_INVALID, self::VAT_ID_INVALID, self::VAT_ID_COMPANY_ID_MISMATCH, self::ZIP_INVALID,
        self::ACCOUNT_NUMBER_INVALID, self::IBAN_INVALID, self::BIC_INVALID, self::IBAN_ACCOUNT_MISMATCH,
    ];

    /** @var array<string, string> druh hodnoty → podmět zprávy */
    private const SUBJECTS = [
        ValueRules::COMPANY_ID     => 'IČO',
        ValueRules::VAT_ID         => 'DIČ',
        ValueRules::ZIP            => 'PSČ',
        ValueRules::ACCOUNT_NUMBER => 'Číslo účtu',
        ValueRules::IBAN           => 'IBAN',
        ValueRules::BIC            => 'BIC',
    ];

    /**
     * @param string $kind   druh hodnoty (`ValueRules::*`)
     * @param string $code   kód nálezu (`self::*`)
     * @param string $value  hodnota, jak byla kontrolována (normalizovaná)
     * @param string $reason důvod bez podmětu a bez tečky („nemá platný kontrolní součet“)
     * @param ?string $suggestion návrh opravy téže hodnoty z redundance; `null` = bez návrhu
     */
    public function __construct(
        public string $kind,
        public string $code,
        public string $value,
        public string $reason,
        public ?string $suggestion = null,
    ) {}

    public function withSuggestion(string $suggestion): self
    {
        return new self($this->kind, $this->code, $this->value, $this->reason, $suggestion);
    }

    /**
     * Zpráva nálezu; `$owner` je vlastník hodnoty v genitivu („dodavatele“)
     * nebo `null` bez vlastníka (formulář osoby).
     */
    public function message(?string $owner = null): string
    {
        $subject = (self::SUBJECTS[$this->kind] ?? $this->kind) . ($owner !== null ? " {$owner}" : '');
        return "{$subject} „{$this->value}“ {$this->reason}.";
    }
}
