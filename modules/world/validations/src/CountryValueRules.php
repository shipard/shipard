<?php

declare(strict_types=1);

namespace Shipard\Module\World\Validations;

/**
 * Pravidla hodnot jednoho státu (#118 D5, D11): tvar a platnost PSČ, IČO,
 * DIČ, tuzemského čísla účtu, IBAN a telefonu. Obecná pravidla bez země
 * (IBAN mod 97, BIC, obecný tvar DIČ, e-mail, web) jsou ve statických
 * třídách vedle; tohle rozhraní zná jen to, co se mezi státy liší.
 *
 * Normalizace sjednocuje tvar mlčky a idempotentně, obsah nemění (D1);
 * validace hodnotu nemění. Stát bez vlastní třídy dostane
 * {@see GenericValueRules} — jen obecná pravidla, nic se nehádá.
 */
interface CountryValueRules
{
    /** ISO 3166-1 alpha-2 malými (`cz`); u generických pravidel země, jak přišla, nebo ''. */
    public function country(): string;

    /** Pravidla státu bez vlastní třídy — jen obecná pravidla. */
    public function isGeneric(): bool;

    // ── Telefon (D12) ───────────────────────────────────────────────────

    /** Mezinárodní předvolba bez `+` (`420`); `null` = nedoplňovat. */
    public function phonePrefix(): ?string;

    /** Národní předčíslí, které národní zápis nese a mezinárodní ne (`0`); `null` = žádné. */
    public function trunkPrefix(): ?string;

    /** Délka národního čísla bez předvolby (cz 9); `null` = proměnlivá. */
    public function nationalNumberLength(): ?int;

    /**
     * Čitelný tvar normalizovaného telefonu (D21): cz / sk `+420 577 123 456`,
     * de předvolba oddělená mezerou; jiný tvar beze změny. Jen pro zobrazení.
     */
    public function formatPhone(string $phone): string;

    // ── PSČ ─────────────────────────────────────────────────────────────

    public function normalizeZip(string $zip): string;

    /** Platnost normalizovaného PSČ; bez pravidla vždy `true`. */
    public function isValidZip(string $zip): bool;

    /** Čitelný tvar normalizovaného PSČ (D21): cz / sk `760 01`; jinde beze změny. Jen pro zobrazení. */
    public function formatZip(string $zip): string;

    // ── IČO ─────────────────────────────────────────────────────────────

    public function normalizeCompanyId(string $companyId): string;

    /** Platnost normalizovaného IČO; bez pravidla vždy `true`. */
    public function isValidCompanyId(string $companyId): bool;

    // ── DIČ / VAT ID ────────────────────────────────────────────────────

    /** Národní daňové číslo (`taxId`): cz/sk = DIČ, jinde jen trim. */
    public function normalizeTaxId(string $taxId): string;

    /** Prefix DIČ (`CZ`); `null` = neznámý. */
    public function vatIdPrefix(): ?string;

    /** DIČ zapsané jen číslicemi doplnit o prefix státu; `null` = nedoplňovat. */
    public function completeVatId(string $digits): ?string;

    /** Platnost části DIČ za prefixem státu. */
    public function isValidVatNumber(string $number): bool;

    /** IČO vyplývající z DIČ (cz: 8místné DIČ = `CZ` + IČO); `null` = vazba neplatí. */
    public function companyIdFromVatId(string $vatId): ?string;

    // ── Účet a IBAN ─────────────────────────────────────────────────────

    /** Stát má tuzemský tvar čísla účtu převoditelný na IBAN (cz, sk). */
    public function supportsDomesticAccount(): bool;

    /** Délka IBAN státu (cz / sk 24, de 22); `null` = stát ji neurčuje. Důvod nálezu „chybná délka“ (#118 D27). */
    public function ibanLength(): ?int;

    /** Platnost IBAN podle pravidel státu (délka); mod 11 na čísle uvnitř je podezření (D30), obecné kontroly dělá {@see Iban}. */
    public function isValidIban(string $iban): bool;

    public function ibanFromDomesticAccount(DomesticAccount $account): ?string;

    public function domesticAccountFromIban(string $iban): ?DomesticAccount;
}
