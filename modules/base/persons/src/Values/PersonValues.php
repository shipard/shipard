<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons\Values;

use Shipard\Module\World\Validations\BankAccountShape;
use Shipard\Module\World\Validations\Country;
use Shipard\Module\World\Validations\ValueChecks;
use Shipard\Module\World\Validations\ValueFinding;
use Shipard\Module\World\Validations\ValueRules;
use Shipard\Module\World\Validations\ValueRulesRegistry;

/**
 * Kanonický tvar údajů adresáře při uložení (#118 D18, D20) — jedna čistá
 * funkce pro každou tabulku nad názvy jejích sloupců, kterou volají
 * všechny cesty zápisu: třídy dokumentů (formulář, `PersonApplier`
 * hlavičku), přímé zápisy applierů (podřízené záznamy, `bankCreate`)
 * i číselník vlastních účtů.
 *
 * Mění jen přítomné klíče (částečný update), `null` a prázdné hodnoty
 * nechává. Tvar určuje knihovna `world.validations`; země jde zvenku —
 * osoba podle D18 ({@see PersonContext}), adresa podle vlastního
 * `country`, kontakt podle osoby, účet zemi nepotřebuje (IBAN podle
 * prefixu).
 *
 * Kontroly (D19, `check*`) vracejí nálezy `{column, code, message}` nad
 * normalizovanou kopií — kódy a texty z knihovny ({@see ValueChecks}),
 * tytéž jako u canonicalu dokladu. Zda je nález chyba, nebo varování,
 * rozhoduje dokument (vlastní firma × partner).
 */
final class PersonValues
{
    /** @var array<string, string> druh hodnoty nálezu → sloupec */
    private const FINDING_COLUMNS = [
        ValueRules::COMPANY_ID     => 'company_id',
        ValueRules::VAT_ID         => 'vat_id',
        ValueRules::ZIP            => 'zip',
        ValueRules::ACCOUNT_NUMBER => 'account_number',
        ValueRules::IBAN           => 'iban',
        ValueRules::BIC            => 'bic',
    ];

    /** @var array<string, string> sloupec `base_persons_persons` → druh hodnoty */
    public const PERSON_COLUMNS = [
        'company_id' => ValueRules::COMPANY_ID,
        'vat_id'     => ValueRules::VAT_ID,
        'tax_id'     => ValueRules::TAX_ID,
        'phone'      => ValueRules::PHONE,
        'email'      => ValueRules::EMAIL,
        'web'        => ValueRules::WEB,
    ];

    /** @var array<string, string> sloupec `base_persons_contacts` → druh hodnoty */
    public const CONTACT_COLUMNS = [
        'email' => ValueRules::EMAIL,
        'phone' => ValueRules::PHONE,
    ];

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function normalizePerson(array $data, ?string $country): array
    {
        return self::normalizeColumns($data, self::PERSON_COLUMNS, $country);
    }

    /**
     * Země malými; PSČ podle země adresy — chybí-li v řádku, podle
     * `$storedCountry` (uložený řádek při částečném updatu), jinak obecná
     * pravidla (jen trim, nic se nehádá).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function normalizeAddress(array $data, ?string $storedCountry = null): array
    {
        $country = self::text($data['country'] ?? null);
        if ($country !== null) {
            $data['country'] = Country::normalize($country);
        }
        $zipCountry = $country !== null ? $data['country'] : self::text($storedCountry);
        if (self::text($data['zip'] ?? null) !== null) {
            $data['zip'] = ValueRules::normalize(ValueRules::ZIP, $data['zip'], $zipCountry);
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function normalizeContact(array $data, ?string $country): array
    {
        return self::normalizeColumns($data, self::CONTACT_COLUMNS, $country);
    }

    /**
     * Tvar čísla účtu, IBAN a BIC + přesun IBAN ze sloupce čísla účtu
     * (D20, {@see BankAccountShape::normalizeRow}).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function normalizeBankAccount(array $data): array
    {
        return BankAccountShape::normalizeRow($data);
    }

    /**
     * IČO, DIČ a jejich soulad (D19).
     *
     * @param array<string, mixed> $data
     * @return list<array{column: string, code: string, message: string}>
     */
    public static function checkPerson(array $data, ?string $country): array
    {
        $shaped = self::normalizePerson($data, $country);
        return self::findings(ValueChecks::identity(
            self::text($shaped['company_id'] ?? null),
            self::text($shaped['vat_id'] ?? null),
            ValueRulesRegistry::forCountry($country),
        ));
    }

    /**
     * PSČ podle země adresy (cz, sk, de); adresa bez země nález nemá.
     *
     * @param array<string, mixed> $data
     * @return list<array{column: string, code: string, message: string}>
     */
    public static function checkAddress(array $data, ?string $storedCountry = null): array
    {
        $shaped = self::normalizeAddress($data, $storedCountry);
        $zip = self::text($shaped['zip'] ?? null);
        if ($zip === null) {
            return [];
        }
        $country = self::text($shaped['country'] ?? null) ?? self::text($storedCountry);
        return self::findings([ValueChecks::zip($zip, ValueRulesRegistry::forCountry($country))]);
    }

    /**
     * Číslo účtu, IBAN, BIC a soulad IBAN × číslo účtu; jiný než tuzemský
     * tvar čísla účtu je nález jen u osoby ze státu s tuzemským tvarem.
     *
     * @param array<string, mixed> $data
     * @return list<array{column: string, code: string, message: string}>
     */
    public static function checkBankAccount(array $data, ?string $country): array
    {
        $shaped = self::normalizeBankAccount($data);
        return self::findings(ValueChecks::bankAccount(
            self::text($shaped['account_number'] ?? null),
            self::text($shaped['iban'] ?? null),
            self::text($shaped['bic'] ?? null),
            ValueRulesRegistry::forCountry($country)->supportsDomesticAccount(),
        ));
    }

    /**
     * @param list<ValueFinding|null> $findings
     * @return list<array{column: string, code: string, message: string}>
     */
    private static function findings(array $findings): array
    {
        $out = [];
        foreach ($findings as $finding) {
            if ($finding instanceof ValueFinding) {
                $out[] = ['column' => self::FINDING_COLUMNS[$finding->kind] ?? $finding->kind, 'code' => $finding->code, 'message' => $finding->message()];
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $columns
     * @return array<string, mixed>
     */
    private static function normalizeColumns(array $data, array $columns, ?string $country): array
    {
        foreach ($columns as $column => $kind) {
            if (self::text($data[$column] ?? null) !== null) {
                $data[$column] = ValueRules::normalize($kind, $data[$column], $country);
            }
        }
        return $data;
    }

    /** Neprázdný řetězec, jinak `null`. */
    public static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
