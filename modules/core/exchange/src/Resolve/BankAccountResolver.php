<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Resolve;

use Dibi\Connection;

/**
 * Resolves bank accounts. Two flavours depending on whose account it is:
 *
 *   - **Partner bank** — `base_persons_bank_accounts` filtered by the
 *     resolved partner `person_id`. No match + known partner → `canCreate`
 *     (Applier attaches new row to partner). No match + partner pending
 *     creation → `canCreate` (Applier defers until partner side-create
 *     finishes).
 *   - **Own bank** — `economy_codebooks_bank_accounts` (our codebook).
 *     No match → `notFound` (own accounts are managed exclusively via
 *     codebook UI; applier never creates them implicitly).
 *
 * Párování (#118 D16): účty osoby (resp. číselník) se načtou a porovnají
 * v PHP přes {@see BankAccountKey} z **obou** sloupců (`iban`
 * i `account_number` — u starých dat nese číslo účtu IBAN). IBAN
 * z dokladu má přednost (`matchedBy: iban`), pak tuzemské číslo
 * (`accountNumber`). Vstup se bere, jak přišel — canonical je v applieru
 * už normalizovaný, klíč si mezery a velikost písmen srovná sám.
 */
class BankAccountResolver
{
    private const ACTIVE_STATES = [10, 40, 80];

    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * Bankovní spojení nese identifikátor (IBAN nebo číslo účtu). Objekt se
     * samými `null` (projekce dokladu bez účtu — účtenka, platba kartou) je
     * „bez spojení“, ne reference k rozhodnutí (#103 F3 nález A).
     *
     * @param array<string, mixed> $bankAccount
     */
    public static function hasIdentifier(array $bankAccount): bool
    {
        foreach (['iban', 'accountNumber'] as $key) {
            $value = $bankAccount[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $bankAccount Canonical Party.bankAccount block.
     * @param int|null $partnerPersonId  Resolved partner id (null = partner is
     *                                    pending side-create; Applier handles).
     */
    public function resolvePartnerBank(array $bankAccount, ?int $partnerPersonId): ResolveResult
    {
        $iban = $this->normalize($bankAccount['iban'] ?? null);
        $accountNumber = $this->normalize($bankAccount['accountNumber'] ?? null);
        if ($iban === null && $accountNumber === null) {
            return ResolveResult::notFound();
        }

        if ($partnerPersonId !== null) {
            $rows = $this->db->fetchAll(
                'SELECT [id], [account_number], [iban] FROM [base_persons_bank_accounts]
                 WHERE [person] = %i AND [docState] IN (%i, %i, %i)
                 ORDER BY [id]',
                $partnerPersonId,
                self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
            );
            $match = $this->matchRows($rows, $iban, $accountNumber);
            if ($match !== null) {
                return ResolveResult::matched($match[0], $match[1]);
            }
        }

        return ResolveResult::canCreate($this->buildPartnerCreatePayload($bankAccount, $partnerPersonId));
    }

    /**
     * @param array<string, mixed> $bankAccount Canonical Party.bankAccount block.
     */
    public function resolveOwnBank(array $bankAccount): ResolveResult
    {
        $iban = $this->normalize($bankAccount['iban'] ?? null);
        $accountNumber = $this->normalize($bankAccount['accountNumber'] ?? null);
        if ($iban === null && $accountNumber === null) {
            return ResolveResult::notFound();
        }

        $rows = $this->db->fetchAll(
            'SELECT [id], [account_number], [iban] FROM [economy_codebooks_bank_accounts]
             WHERE [docState] IN (%i, %i, %i)
             ORDER BY [id]',
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        $match = $this->matchRows($rows, $iban, $accountNumber);
        return $match !== null ? ResolveResult::matched($match[0], $match[1]) : ResolveResult::notFound();
    }

    /**
     * Klíč z dokladu (IBAN přednostně, pak číslo účtu) proti klíčům obou
     * sloupců každého řádku.
     *
     * @param iterable<mixed> $rows
     * @return array{0: int, 1: string}|null [id, matchedBy]
     */
    private function matchRows(iterable $rows, ?string $iban, ?string $accountNumber): ?array
    {
        $wanted = [];
        $ibanKey = $iban !== null ? BankAccountKey::of($iban) : null;
        if ($ibanKey !== null) {
            $wanted[$ibanKey] = 'iban';
        }
        $accountKey = $accountNumber !== null ? BankAccountKey::of($accountNumber) : null;
        if ($accountKey !== null && !isset($wanted[$accountKey])) {
            $wanted[$accountKey] = 'accountNumber';
        }
        if ($wanted === []) {
            return null;
        }

        $stored = [];
        foreach ($rows as $row) {
            $arr = $row instanceof \Dibi\Row ? $row->toArray() : (array) $row;
            foreach (['iban', 'account_number'] as $column) {
                $value = $arr[$column] ?? null;
                $key = is_string($value) ? BankAccountKey::of($value) : null;
                if ($key !== null && !isset($stored[$key])) {
                    $stored[$key] = (int) $arr['id'];
                }
            }
        }
        foreach ($wanted as $key => $matchedBy) {
            if (isset($stored[$key])) {
                return [$stored[$key], $matchedBy];
            }
        }
        return null;
    }

    private function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param array<string, mixed> $bankAccount
     * @return array<string, mixed>
     */
    private function buildPartnerCreatePayload(array $bankAccount, ?int $partnerPersonId): array
    {
        $payload = [
            'account_number' => $this->normalize($bankAccount['accountNumber'] ?? null) ?? '',
            'iban'           => $this->normalize($bankAccount['iban'] ?? null) ?? '',
            'bic'            => $this->normalize($bankAccount['bic'] ?? null) ?? '',
            'currency'       => $this->normalizeCurrency($bankAccount['currency'] ?? null),
        ];
        if ($partnerPersonId !== null) {
            $payload['person'] = $partnerPersonId;
        }
        return $payload;
    }

    private function normalizeCurrency(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : strtolower($trimmed);
    }
}
