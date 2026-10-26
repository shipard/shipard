<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Values;

use Shipard\Module\World\Validations\Country;
use Shipard\Module\World\Validations\DomesticAccount;
use Shipard\Module\World\Validations\Iban;
use Shipard\Module\World\Validations\ValueRules;
use Shipard\Module\World\Validations\ValueRulesRegistry;

/**
 * Normalizace hodnot canonicalu `shpd.docs.document` (#118 D1–D4): čistá
 * funkce `array → array`, idempotentní. Zná cesty canonicalu, pravidla
 * tvarů bere z `world.validations` (podle země strany).
 *
 * - {@see normalizeShapes()} sjednocuje jen tvar (mezery, velikost
 *   písmen, vedoucí nuly IČO, prefix DIČ, předvolba telefonu) — obsah
 *   nemění, nic nedoplňuje. Validátor nad ní kontroluje i persistované
 *   canonicaly z doby před normalizací.
 * - {@see normalize()} navíc opraví IBAN ↔ tuzemské číslo účtu
 *   z redundance na dokladu (D4): je-li z dvojice jeden platný a druhý
 *   ne, neplatný se dopočte z platného; dopočtená hodnota musí sama
 *   projít validací. Oprava se zapíše do `_resolve.valueRepairs`
 *   (D14) — opakovaný běh existující záznamy zachová a nic nepřidá.
 *
 * Běží po kontrole schématu a před obohacením, dedupem ISDOC
 * a `DocumentValidator` (D2): AI `/result`, sběr ISDOC a všechny vstupy
 * `DocumentApplier` včetně importního módu. `null`, chybějící klíče
 * a ne-řetězce zůstávají, jak jsou.
 */
final class CanonicalValueNormalizer
{
    public const PARTIES = ['supplier', 'customer', 'balanceParty'];
    public const REPAIRS_KEY = 'valueRepairs';
    public const BASIS_ACCOUNT_NUMBER = 'accountNumber';
    public const BASIS_IBAN = 'iban';

    private const PARTY_FIELDS = [
        'companyId' => ValueRules::COMPANY_ID,
        'taxId'     => ValueRules::TAX_ID,
        'vatId'     => ValueRules::VAT_ID,
    ];
    private const CONTACT_FIELDS = [
        'email' => ValueRules::EMAIL,
        'phone' => ValueRules::PHONE,
        'web'   => ValueRules::WEB,
    ];
    private const BANK_FIELDS = [
        'accountNumber' => ValueRules::ACCOUNT_NUMBER,
        'iban'          => ValueRules::IBAN,
        'bic'           => ValueRules::BIC,
    ];
    private const SYMBOL_FIELDS = [
        'paymentReference' => ValueRules::REFERENCE,
        'specificSymbol'   => ValueRules::SYMBOL,
        'constantSymbol'   => ValueRules::SYMBOL,
    ];

    /**
     * Tvary bez opravy D4.
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    public static function normalizeShapes(array $canonical): array
    {
        foreach (self::PARTIES as $key) {
            if (is_array($canonical[$key] ?? null)) {
                $canonical[$key] = self::normalizeParty($canonical[$key]);
            }
        }
        if (is_array($canonical['payment'] ?? null)) {
            $canonical['payment'] = self::normalizeFields($canonical['payment'], self::SYMBOL_FIELDS, null);
        }
        if (is_array($canonical['rows'] ?? null)) {
            foreach ($canonical['rows'] as $i => $row) {
                if (is_array($row)) {
                    $canonical['rows'][$i] = self::normalizeFields($row, self::SYMBOL_FIELDS, null);
                }
            }
        }
        return $canonical;
    }

    /**
     * Tvary + oprava IBAN ↔ číslo účtu (D4) se záznamem do `_resolve.valueRepairs`.
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    public static function normalize(array $canonical): array
    {
        $canonical = self::normalizeShapes($canonical);
        foreach (self::PARTIES as $key) {
            $party = $canonical[$key] ?? null;
            if (!is_array($party) || !is_array($party['bankAccount'] ?? null)) {
                continue;
            }
            $repair = self::repairBankAccount($party['bankAccount'], self::partyCountry($party), $key);
            if ($repair === null) {
                continue;
            }
            $canonical[$key]['bankAccount'][$repair['field']] = $repair['to'];
            $canonical = self::recordRepair($canonical, $repair);
        }
        return $canonical;
    }

    /**
     * Země strany: `country`, jinak `address.country`; malými, bez země `null`.
     *
     * @param array<string, mixed> $party
     */
    public static function partyCountry(array $party): ?string
    {
        foreach ([$party['country'] ?? null, $party['address']['country'] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return Country::normalize($value);
            }
        }
        return null;
    }

    /**
     * Záznamy oprav z canonicalu (bez bloku prázdný seznam).
     *
     * @param array<string, mixed> $canonical
     * @return list<array{path: string, from: string, to: string, basis: string}>
     */
    public static function repairsOf(array $canonical): array
    {
        $repairs = $canonical['_resolve'][self::REPAIRS_KEY] ?? null;
        if (!is_array($repairs)) {
            return [];
        }
        $out = [];
        foreach ($repairs as $repair) {
            if (is_array($repair) && is_string($repair['path'] ?? null) && is_string($repair['to'] ?? null)) {
                $out[] = [
                    'path'  => $repair['path'],
                    'from'  => (string) ($repair['from'] ?? ''),
                    'to'    => $repair['to'],
                    'basis' => (string) ($repair['basis'] ?? ''),
                ];
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $party
     * @return array<string, mixed>
     */
    private static function normalizeParty(array $party): array
    {
        if (is_string($party['country'] ?? null)) {
            $party['country'] = Country::normalize($party['country']);
        }
        if (is_array($party['address'] ?? null) && is_string($party['address']['country'] ?? null)) {
            $party['address']['country'] = Country::normalize($party['address']['country']);
        }
        $country = self::partyCountry($party);

        $party = self::normalizeFields($party, self::PARTY_FIELDS, $country);
        if (is_array($party['address'] ?? null)) {
            $zipCountry = $party['address']['country'] ?? null;
            $zipCountry = is_string($zipCountry) && $zipCountry !== '' ? $zipCountry : $country;
            $party['address'] = self::normalizeFields($party['address'], ['zip' => ValueRules::ZIP], $zipCountry);
        }
        if (is_array($party['contact'] ?? null)) {
            $party['contact'] = self::normalizeFields($party['contact'], self::CONTACT_FIELDS, $country);
        }
        if (is_array($party['bankAccount'] ?? null)) {
            $party['bankAccount'] = self::normalizeFields($party['bankAccount'], self::BANK_FIELDS, $country);
        }
        return $party;
    }

    /**
     * @param array<string, mixed> $object
     * @param array<string, string> $fields klíč → druh hodnoty
     * @return array<string, mixed>
     */
    private static function normalizeFields(array $object, array $fields, ?string $country): array
    {
        foreach ($fields as $field => $kind) {
            if (is_string($object[$field] ?? null)) {
                $object[$field] = ValueRules::normalize($kind, $object[$field], $country);
            }
        }
        return $object;
    }

    /**
     * Oprava D4 nad normalizovaným `bankAccount`: země účtu podle prefixu
     * IBAN, jinak podle strany; jen stát s tuzemským tvarem účtu (cz, sk).
     *
     * @param array<string, mixed> $bank
     * @return array{field: string, path: string, from: string, to: string, basis: string}|null
     */
    private static function repairBankAccount(array $bank, ?string $partyCountry, string $partyKey): ?array
    {
        $iban = $bank['iban'] ?? null;
        $accountNumber = $bank['accountNumber'] ?? null;
        if (!is_string($iban) || $iban === '' || !is_string($accountNumber) || $accountNumber === '') {
            return null;
        }
        $rules = Iban::countryOf($iban) !== null
            ? ValueRulesRegistry::forIban($iban)
            : ValueRulesRegistry::forCountry($partyCountry);
        if (!$rules->supportsDomesticAccount()) {
            return null;
        }

        $ibanValid = Iban::isValid($iban);
        $account = DomesticAccount::parse($accountNumber);
        $accountValid = $account?->isValid() ?? false;
        if ($ibanValid === $accountValid) {
            return null; // oba platné (shodu hlídá validátor) nebo oba neplatné
        }

        if ($accountValid) {
            $derived = $rules->ibanFromDomesticAccount($account);
            if ($derived === null || !Iban::isValid($derived)) {
                return null;
            }
            return [
                'field' => 'iban',
                'path'  => "{$partyKey}.bankAccount.iban",
                'from'  => $iban,
                'to'    => $derived,
                'basis' => self::BASIS_ACCOUNT_NUMBER,
            ];
        }

        $derived = $rules->domesticAccountFromIban($iban);
        if ($derived === null || !$derived->isValid()) {
            return null;
        }
        return [
            'field' => 'accountNumber',
            'path'  => "{$partyKey}.bankAccount.accountNumber",
            'from'  => $accountNumber,
            'to'    => (string) $derived,
            'basis' => self::BASIS_IBAN,
        ];
    }

    /**
     * Idempotentní zápis: stejný záznam (cesta, původní i nová hodnota)
     * se podruhé nepřidá, ostatní záznamy zůstávají.
     *
     * @param array<string, mixed> $canonical
     * @param array{field: string, path: string, from: string, to: string, basis: string} $repair
     * @return array<string, mixed>
     */
    private static function recordRepair(array $canonical, array $repair): array
    {
        $existing = is_array($canonical['_resolve'][self::REPAIRS_KEY] ?? null)
            ? $canonical['_resolve'][self::REPAIRS_KEY]
            : [];
        foreach ($existing as $record) {
            if (is_array($record)
                && ($record['path'] ?? null) === $repair['path']
                && ($record['from'] ?? null) === $repair['from']
                && ($record['to'] ?? null) === $repair['to']
            ) {
                return $canonical;
            }
        }
        $existing[] = [
            'path'  => $repair['path'],
            'from'  => $repair['from'],
            'to'    => $repair['to'],
            'basis' => $repair['basis'],
        ];
        if (!is_array($canonical['_resolve'] ?? null)) {
            $canonical['_resolve'] = [];
        }
        $canonical['_resolve'][self::REPAIRS_KEY] = $existing;
        return $canonical;
    }
}
