<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons\Values;

use Shipard\Module\World\Validations\BankAccountShape;
use Shipard\Module\World\Validations\Country;
use Shipard\Module\World\Validations\ValueRules;

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
 */
final class PersonValues
{
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
