<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons\Values;

use Dibi\Connection;
use Shipard\Module\World\Validations\Country;

/**
 * Co o osobě potřebují pravidla hodnot (#118 D18, D19): **země osoby**
 * = země adresy sídla (`address_type` 1), jinak první adresy se zemí,
 * jinak země zdroje dat; a zda je to **vlastní firma** (`is_own`) — u ní
 * nevalidní hodnota uložení blokuje, u partnera jen varuje.
 *
 * Bez databáze (unit test, dokument bez `setDb`) vrací jen zemi zdroje
 * dat a `is_own` false. Dotazy jsou v chráněných metodách — testy je
 * přepíší.
 */
class PersonContext
{
    public const ADDRESS_TYPE_HEADQUARTERS = 1;

    public function __construct(
        private readonly ?Connection $db,
        private readonly string $dsCountry,
    ) {}

    /** Země zdroje dat (`DataSourceConfig::getCountry()`), poslední záchrana. */
    public function dsCountry(): string
    {
        return $this->dsCountry;
    }

    /**
     * Země z řádků adres v témže zápisu (nová osoba nese `addresses`):
     * sídlo se zemí, jinak první adresa se zemí; bez země `null`.
     *
     * @param list<array<string, mixed>> $addresses
     */
    public static function countryFromAddresses(array $addresses): ?string
    {
        $first = null;
        foreach ($addresses as $address) {
            $country = PersonValues::text($address['country'] ?? null);
            if ($country === null) {
                continue;
            }
            if ((int) ($address['address_type'] ?? 0) === self::ADDRESS_TYPE_HEADQUARTERS) {
                return Country::normalize($country);
            }
            $first ??= Country::normalize($country);
        }
        return $first;
    }

    /** Země osoby podle uložených adres (sídlo → první), jinak země zdroje dat. */
    public function country(int $personId): string
    {
        return $this->storedCountry($personId) ?? $this->dsCountry;
    }

    public function isOwn(int $personId): bool
    {
        return $this->db !== null && $this->fetchIsOwn($personId);
    }

    protected function storedCountry(int $personId): ?string
    {
        if ($this->db === null || $personId <= 0) {
            return null;
        }
        $country = $this->db->fetchSingle(
            'SELECT [country] FROM [base_persons_addresses]'
            . ' WHERE [person] = %i AND [docState] <> 90 AND [country] IS NOT NULL AND [country] <> %s'
            . ' ORDER BY ([address_type] = %i) DESC, [order_pos], [id] LIMIT 1',
            $personId,
            '',
            self::ADDRESS_TYPE_HEADQUARTERS,
        );
        return is_string($country) && $country !== '' ? Country::normalize($country) : null;
    }

    protected function fetchIsOwn(int $personId): bool
    {
        if ($personId <= 0) {
            return false;
        }
        return (int) $this->db->fetchSingle('SELECT [is_own] FROM [base_persons_persons] WHERE [id] = %i', $personId) === 1;
    }
}
