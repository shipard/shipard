<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Resolve;

use Dibi\Connection;
use Shipard\Module\Base\Persons\Send\SendPurposes;

/**
 * Resolves a canonical Contact object to a `base_persons_contacts.id`.
 *
 * Per docs/exchange-format-persons.md §8.3, the match-key priority is:
 *
 *   1. `(person, name, email)` exact — when email is supplied.
 *   2. `(person, name)` exact — fallback without email.
 *   3. No match → `canCreate`.
 *
 * Contacts duplicate easily across persons (same secretary listed under
 * two companies, generic „Účtárna" entries, …). The resolver therefore
 * prefers creating a new row over overwriting an existing one on
 * uncertain matches — the (name, email) probe is intentionally strict
 * and only the exact fallback by name catches near-duplicates.
 *
 * When called with `personId = null` (header is canCreate), the resolver
 * short-circuits to `canCreate` without DB probes.
 *
 * All SQL probes filter `docState IN (10, 40, 80)`.
 */
class ContactResolver
{
    private const ACTIVE_STATES = [10, 40, 80];

    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * @param array<string, mixed> $contact
     */
    public function resolve(array $contact, ?int $personId): ResolveResult
    {
        if ($personId === null) {
            return ResolveResult::canCreate($this->buildCreatePayload($contact, null));
        }

        $name = $this->normalize($contact['name'] ?? null);
        if ($name === null) {
            // No name → nothing to probe by, but contact is invalid in the
            // first place (PersonValidator enforces non-empty name at the
            // schema/validator level). Fall through to canCreate.
            return ResolveResult::canCreate($this->buildCreatePayload($contact, $personId));
        }

        $email = $this->normalize($contact['email'] ?? null);
        if ($email !== null) {
            $row = $this->fetchByNameAndEmail($personId, $name, $email);
            if ($row !== null) {
                return ResolveResult::matched((int) $row['id'], 'nameEmail');
            }
        }

        $row = $this->fetchByName($personId, $name);
        if ($row !== null) {
            return ResolveResult::matched((int) $row['id'], 'name');
        }

        return ResolveResult::canCreate($this->buildCreatePayload($contact, $personId));
    }

    private function fetchByNameAndEmail(int $personId, string $name, string $email): ?array
    {
        $row = $this->db->fetch(
            'SELECT [id] FROM [base_persons_contacts]
             WHERE [person] = %i AND [name] = %s AND [email] = %s
               AND [docState] IN (%i, %i, %i)
             LIMIT 1',
            $personId, $name, $email,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        return $row !== null ? $row->toArray() : null;
    }

    private function fetchByName(int $personId, string $name): ?array
    {
        $row = $this->db->fetch(
            'SELECT [id] FROM [base_persons_contacts]
             WHERE [person] = %i AND [name] = %s
               AND [docState] IN (%i, %i, %i)
             LIMIT 1',
            $personId, $name,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        return $row !== null ? $row->toArray() : null;
    }

    /**
     * @param array<string, mixed> $contact
     * @return array<string, mixed>
     */
    private function buildCreatePayload(array $contact, ?int $personId): array
    {
        $payload = [
            'person'     => $personId,
            'name'       => $this->normalize($contact['name'] ?? null) ?? '',
            'role'       => $this->normalize($contact['role'] ?? null),
            'email'      => $this->normalize($contact['email'] ?? null),
            'phone'      => $this->normalize($contact['phone'] ?? null),
            'note'       => $this->normalize($contact['note'] ?? null),
            'order_pos'  => $this->intOrNull($contact['orderPos'] ?? null) ?? 0,
            'valid_from' => $this->normalize($contact['validFrom'] ?? null),
            'valid_to'   => $this->normalize($contact['validTo'] ?? null),
        ];

        return $payload + self::sendPurposesPayload($contact);
    }

    /**
     * Účely odesílání kontaktu (formát 1.2, #90 D34). Klíč `sendPurposes`
     * v payloadu chybí → sloupec se nezapisuje (starší payload účely
     * nesmaže); klíč je → platí, prázdný seznam = kontakt bez účelu.
     *
     * @param array<string, mixed> $contact
     * @return array{send_purposes?: ?string}
     */
    public static function sendPurposesPayload(array $contact): array
    {
        if (!is_array($contact['sendPurposes'] ?? null)) {
            return [];
        }
        return ['send_purposes' => SendPurposes::encode(SendPurposes::decode($contact['sendPurposes']) ?? [])];
    }

    private function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) return $value;
        if (is_string($value) && ctype_digit($value)) return (int) $value;
        return null;
    }
}
