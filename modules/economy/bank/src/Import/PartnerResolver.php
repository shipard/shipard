<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank\Import;

use Shipard\Module\World\Validations\BankAccountKey;

/**
 * Dohledání partnera (osoby) podle čísla protiúčtu — port POSLEDNÍHO bloku
 * `checkRowPerson` ze starého Shipardu (saldo-nezávislé). Hledá v bankovních
 * spojeních kontaktů (`base_persons_bank_accounts`) přes klíč účtu
 * ({@see BankAccountKey}, #118 D23) z **obou** sloupců — IBAN uložený ve
 * sloupci čísla účtu se tak napáruje na tuzemské číslo z výpisu a naopak.
 * Právě jedna shoda → vrátí id osoby; jinak null.
 *
 * Žádné saldo (VS → otevřené faktury) — to je pozdější fáze.
 */
class PartnerResolver
{
    private const ACTIVE_STATES = [10, 40, 80];

    public function __construct(private readonly \Dibi\Connection $db)
    {
    }

    public function resolve(?string $counterpartyAccount): ?int
    {
        $key = $counterpartyAccount !== null ? BankAccountKey::of($counterpartyAccount) : null;
        if ($key === null) {
            return null;
        }

        $matches = [];
        foreach ($this->loadAccounts() as $row) {
            foreach (['account_number', 'iban'] as $column) {
                $value = $row[$column] ?? null;
                if (is_string($value) && BankAccountKey::of($value) === $key) {
                    $matches[(int) $row['person']] = true;
                    break;
                }
            }
        }

        // Právě jedna osoba → spárovat; víc/žádná → nechat prázdné.
        return count($matches) === 1 ? (int) array_key_first($matches) : null;
    }

    /**
     * Aktivní bankovní spojení osob — šev pro testy.
     *
     * @return list<array<string, mixed>>
     */
    protected function loadAccounts(): array
    {
        $rows = $this->db->query(
            'SELECT [person], [account_number], [iban] FROM [base_persons_bank_accounts]'
            . ' WHERE [docState] IN %in',
            self::ACTIVE_STATES,
        )->fetchAll();
        return array_map(static fn ($row): array => (array) $row, $rows);
    }
}
