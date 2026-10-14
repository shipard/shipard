<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Resolve;

use Dibi\Connection;

/**
 * Resolves bank accounts. Two flavours depending on whose account it is:
 *
 *   - **Partner bank** — `base_persons_bank_accounts` filtered by the
 *     resolved partner `person_id`. Match path: IBAN → accountNumber.
 *     No match + known partner → `canCreate` (Applier attaches new row
 *     to partner). No match + partner pending creation → `canCreate`
 *     (Applier defers until partner side-create finishes).
 *   - **Own bank** — `economy_codebooks_bank_accounts` (our codebook).
 *     Match path: IBAN → accountNumber + currency filter. No match →
 *     `notFound` (own accounts are managed exclusively via codebook UI;
 *     applier never creates them implicitly).
 */
class BankAccountResolver
{
    private const ACTIVE_STATES = [10, 40, 80];

    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * @param array<string, mixed> $bankAccount Canonical Party.bankAccount block.
     * @param int|null $partnerPersonId  Resolved partner id (null = partner is
     *                                    pending side-create; Applier handles).
     */
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

    public function resolvePartnerBank(array $bankAccount, ?int $partnerPersonId): ResolveResult
    {
        $iban = $this->normalize($bankAccount['iban'] ?? null);
        $accountNumber = $this->normalize($bankAccount['accountNumber'] ?? null);
        if ($iban === null && $accountNumber === null) {
            return ResolveResult::notFound();
        }

        if ($partnerPersonId !== null) {
            if ($iban !== null) {
                $row = $this->db->fetch(
                    'SELECT [id] FROM [base_persons_bank_accounts]
                     WHERE [person] = %i AND [iban] = %s
                       AND [docState] IN (%i, %i, %i)
                     LIMIT 1',
                    $partnerPersonId, $iban,
                    self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
                );
                if ($row !== null) {
                    return ResolveResult::matched((int) $row['id'], 'iban');
                }
            }
            if ($accountNumber !== null) {
                $row = $this->db->fetch(
                    'SELECT [id] FROM [base_persons_bank_accounts]
                     WHERE [person] = %i AND [account_number] = %s
                       AND [docState] IN (%i, %i, %i)
                     LIMIT 1',
                    $partnerPersonId, $accountNumber,
                    self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
                );
                if ($row !== null) {
                    return ResolveResult::matched((int) $row['id'], 'accountNumber');
                }
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

        if ($iban !== null) {
            $row = $this->db->fetch(
                'SELECT [id] FROM [economy_codebooks_bank_accounts]
                 WHERE [iban] = %s AND [docState] IN (%i, %i, %i)
                 LIMIT 1',
                $iban,
                self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
            );
            if ($row !== null) {
                return ResolveResult::matched((int) $row['id'], 'iban');
            }
        }
        if ($accountNumber !== null) {
            $row = $this->db->fetch(
                'SELECT [id] FROM [economy_codebooks_bank_accounts]
                 WHERE [account_number] = %s AND [docState] IN (%i, %i, %i)
                 LIMIT 1',
                $accountNumber,
                self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
            );
            if ($row !== null) {
                return ResolveResult::matched((int) $row['id'], 'accountNumber');
            }
        }

        return ResolveResult::notFound();
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
