<?php

declare(strict_types=1);

namespace Shipard\Module\World\Validations;

/**
 * Tvar trojice číslo účtu / IBAN / BIC při uložení do adresáře (#118 D18,
 * D20): každá hodnota normalizovaná, a IBAN zapsaný ve sloupci čísla účtu
 * se přesune do `iban` — u cz a sk se do čísla účtu dopočte tuzemské číslo,
 * jinde zůstane prázdné. Přesun jen když je sloupec `iban` prázdný, nebo
 * nese tentýž účet ({@see BankAccountKey}); jiný účet v `iban` = beze
 * změny (validace nahlásí nesoulad). Číslo účtu bez IBANu se IBANem
 * nedoplňuje (D1).
 *
 * `null` zůstává `null`, prázdný řetězec prázdný; vyprázdněné číslo účtu
 * po přesunu je `''` (sloupec účtů osob je NOT NULL).
 */
final class BankAccountShape
{
    /** Názvy sloupců společné pro `base_persons_bank_accounts` a `economy_codebooks_bank_accounts`. */
    public const COLUMN_ACCOUNT_NUMBER = 'account_number';
    public const COLUMN_IBAN = 'iban';
    public const COLUMN_BIC = 'bic';

    /** @return array{accountNumber: ?string, iban: ?string, bic: ?string} */
    public static function normalize(?string $accountNumber, ?string $iban, ?string $bic): array
    {
        $account = self::shape($accountNumber, ValueRules::ACCOUNT_NUMBER);
        $ibanShaped = self::shape($iban, ValueRules::IBAN);
        $bicShaped = self::shape($bic, ValueRules::BIC);

        $candidate = $accountNumber !== null ? Iban::normalize($accountNumber) : '';
        if ($candidate !== '' && Iban::hasShape($candidate)) {
            $ibanEmpty = $ibanShaped === null || $ibanShaped === '';
            if ($ibanEmpty || BankAccountKey::of($ibanShaped) === BankAccountKey::of($candidate)) {
                $rules = ValueRulesRegistry::forIban($candidate);
                $domestic = $rules->supportsDomesticAccount() ? $rules->domesticAccountFromIban($candidate) : null;
                return [
                    'accountNumber' => $domestic !== null ? (string) $domestic : '',
                    'iban'          => $ibanEmpty ? $candidate : $ibanShaped,
                    'bic'           => $bicShaped,
                ];
            }
        }
        return ['accountNumber' => $account, 'iban' => $ibanShaped, 'bic' => $bicShaped];
    }

    /**
     * Totéž nad řádkem se sloupci `account_number`, `iban`, `bic`: mění jen
     * přítomné klíče; přesun IBAN (D20) jen když jsou přítomné oba sloupce
     * `account_number` i `iban` (částečný update mění jen tvary).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function normalizeRow(array $row): array
    {
        $hasAccount = array_key_exists(self::COLUMN_ACCOUNT_NUMBER, $row);
        $hasIban = array_key_exists(self::COLUMN_IBAN, $row);
        $hasBic = array_key_exists(self::COLUMN_BIC, $row);
        if ($hasAccount && $hasIban) {
            $shaped = self::normalize(
                self::stringOrNull($row[self::COLUMN_ACCOUNT_NUMBER]),
                self::stringOrNull($row[self::COLUMN_IBAN]),
                $hasBic ? self::stringOrNull($row[self::COLUMN_BIC]) : null,
            );
            $row[self::COLUMN_ACCOUNT_NUMBER] = self::keepType($row[self::COLUMN_ACCOUNT_NUMBER], $shaped['accountNumber']);
            $row[self::COLUMN_IBAN] = self::keepType($row[self::COLUMN_IBAN], $shaped['iban']);
            if ($hasBic) {
                $row[self::COLUMN_BIC] = self::keepType($row[self::COLUMN_BIC], $shaped['bic']);
            }
            return $row;
        }
        foreach ([self::COLUMN_ACCOUNT_NUMBER => ValueRules::ACCOUNT_NUMBER, self::COLUMN_IBAN => ValueRules::IBAN, self::COLUMN_BIC => ValueRules::BIC] as $column => $kind) {
            if (array_key_exists($column, $row)) {
                $row[$column] = self::keepType($row[$column], self::shape(self::stringOrNull($row[$column]), $kind));
            }
        }
        return $row;
    }

    private static function shape(?string $value, string $kind): ?string
    {
        if ($value === null || trim($value) === '') {
            return $value;
        }
        return ValueRules::normalize($kind, $value);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Řetězec dostane normalizovaný tvar; `null` nebo číslo zůstává, jak
     * bylo, pokud tvar nic nepřinesl (přesunutý IBAN do prázdného sloupce
     * se zapíše vždy).
     */
    private static function keepType(mixed $original, ?string $shaped): mixed
    {
        if (is_string($original) || ($shaped !== null && $shaped !== '')) {
            return (string) $shaped;
        }
        return $original;
    }
}
