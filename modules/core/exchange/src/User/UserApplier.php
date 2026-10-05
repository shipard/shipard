<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\User;

use Dibi\Connection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * Import uživatele — formát `shpd.system.user.v1` (#93 D7, D13).
 *
 * Migrace ze starého systému zakládá uživatele pro Osoby, které „něco
 * udělaly“ (autor dokladu, autor záznamu spisovny), a jejich id pak posílá
 * jako `applyOptions.author` / `createdBy`. Applier je idempotentní —
 * uživatelé přežívají `ds-reset` (`keepOnReset`), opakovaný import je proto
 * běžný stav, ne chyba.
 *
 * Párování:
 *   1. uživatel se stejným `login` → ten;
 *   2. jinak právě jeden **aktivní ne-systémový** uživatel se stejným
 *      `email` (bez ohledu na velikost písmen) → ten — skutečný účet, který
 *      mezitím založil admin;
 *   3. jinak se založí nový.
 *
 * Bezpečnostní hranice (endpoint smí volat i API klíč): nový uživatel je
 * vždy **neaktivní, bez hesla a bez práv admina**; u nalezeného se nemění
 * nic kromě `person`. Voláním tedy nejde získat přihlášení. Aktivace je
 * ruční akce admina (D8).
 *
 * `person` u nalezeného uživatele: nastaví se, jen když je prázdná nebo
 * ukazuje na neexistující Osobu (po `ds-reset` mají Osoby nová id, uživatelé
 * zůstali). Sloupec přidává rozšíření z base.persons — zdroj dat bez Osob ho
 * nemá a `person` se tam ignoruje.
 */
class UserApplier
{
    public const FORMAT_ID = 'shpd.system.user';
    public const FORMAT_VERSION = '1';

    private const USERS_TABLE   = 'core_system_users';
    private const PERSONS_TABLE = 'base_persons_persons';

    public function __construct(
        private readonly Connection $db,
        private readonly SchemaValidator $schemaValidator,
        private readonly bool $hasPersonColumn,
    ) {}

    /**
     * @param array<string, TableDefinition> $tables
     */
    public static function create(Connection $db, array $tables): self
    {
        $hasPerson = false;
        foreach (($tables[self::USERS_TABLE] ?? null)?->columns ?? [] as $column) {
            if ($column->id === 'person') {
                $hasPerson = true;
                break;
            }
        }

        return new self($db, new SchemaValidator(SchemaLoader::default()), $hasPerson);
    }

    /**
     * Kontrola bez zápisu. `savedId` = uživatel, kterého by `apply` vrátil
     * (null = `apply` by založil nového).
     *
     * @param array<string, mixed> $canonical
     */
    public function validate(array $canonical): ApplyResult
    {
        $failure = $this->check($canonical);
        if ($failure !== null) {
            return $failure;
        }

        $existing = $this->match($this->normalize($canonical));
        return ApplyResult::ok($canonical, $existing !== null ? (int) $existing['id'] : null);
    }

    /**
     * Status 201 = uživatel založen, 200 = vrácen existující.
     *
     * @param array<string, mixed> $canonical
     */
    public function apply(array $canonical): ApplyResult
    {
        $failure = $this->check($canonical);
        if ($failure !== null) {
            return $failure;
        }

        $user = $this->normalize($canonical);
        $existing = $this->match($user);
        if ($existing !== null) {
            $this->repairPerson($existing, $user['person']);
            return ApplyResult::ok($canonical, (int) $existing['id'], 200);
        }

        $row = [
            'login'         => $user['login'],
            'email'         => $user['email'],
            'full_name'     => $user['fullName'],
            'password_hash' => null,
            'is_active'     => 0,
            'is_admin'      => 0,
            'is_system'     => 0,
        ];
        if ($this->hasPersonColumn) {
            $row['person'] = $user['person'];
        }

        [$userId, $created] = $this->insertUser($row);
        return ApplyResult::ok($canonical, $userId, $created ? 201 : 200);
    }

    /**
     * Schéma + sémantika (existence Osoby). Null = v pořádku.
     *
     * @param array<string, mixed> $canonical
     */
    private function check(array $canonical): ?ApplyResult
    {
        $schemaIssues = $this->schemaValidator->validate($canonical, self::FORMAT_ID, self::FORMAT_VERSION);
        if ($schemaIssues !== []) {
            return ApplyResult::error(
                code: 'schema_invalid',
                message: 'Struktura uživatele neodpovídá schématu.',
                canonical: $this->withIssues($canonical, $schemaIssues),
                statusCode: 400,
            );
        }

        $person = $canonical['person'] ?? null;
        if ($this->hasPersonColumn && $person !== null && !$this->personExists((int) $person)) {
            return ApplyResult::error(
                code: 'validation_failed',
                message: 'Validace uživatele selhala.',
                canonical: $this->withIssues($canonical, [[
                    'severity' => 'error',
                    'path'     => 'person',
                    'code'     => 'person_not_found',
                    'message'  => 'Osoba (person) v tomto zdroji dat neexistuje.',
                ]]),
                statusCode: 422,
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $canonical
     * @return array{login: string, email: ?string, fullName: string, person: ?int}
     */
    private function normalize(array $canonical): array
    {
        $email = trim((string) ($canonical['email'] ?? ''));
        $person = $canonical['person'] ?? null;

        return [
            'login'    => trim((string) $canonical['login']),
            'email'    => $email !== '' ? $email : null,
            'fullName' => trim((string) $canonical['fullName']),
            'person'   => $this->hasPersonColumn && $person !== null ? (int) $person : null,
        ];
    }

    /**
     * @param array{login: string, email: ?string, fullName: string, person: ?int} $user
     * @return array<string, mixed>|null
     */
    private function match(array $user): ?array
    {
        $columns = $this->hasPersonColumn ? '[id], [person]' : '[id]';

        $byLogin = $this->db->fetch(
            'SELECT ' . $columns . ' FROM [' . self::USERS_TABLE . '] WHERE [login] = %s',
            $user['login'],
        );
        if ($byLogin !== null && $byLogin !== false) {
            return (array) $byLogin;
        }

        if ($user['email'] === null) {
            return null;
        }
        // LIMIT 2: víc aktivních účtů se stejným e-mailem = nejednoznačné,
        // páruje se jen právě jeden.
        $byEmail = $this->db->fetchAll(
            'SELECT ' . $columns . ' FROM [' . self::USERS_TABLE . ']'
            . ' WHERE [is_active] = 1 AND [is_system] = 0 AND LOWER([email]) = LOWER(%s)'
            . ' ORDER BY [id] LIMIT 2',
            $user['email'],
        );
        return count($byEmail) === 1 ? (array) $byEmail[0] : null;
    }

    /**
     * Jediná změna, kterou import u existujícího uživatele smí udělat.
     *
     * @param array<string, mixed> $existing
     */
    private function repairPerson(array $existing, ?int $person): void
    {
        if (!$this->hasPersonColumn || $person === null) {
            return;
        }
        $current = (int) ($existing['person'] ?? 0);
        if ($current === $person) {
            return;
        }
        if ($current > 0 && $this->personExists($current)) {
            return;
        }
        $this->updateUserPerson((int) $existing['id'], $person);
    }

    private function personExists(int $personId): bool
    {
        $row = $this->db->fetch(
            'SELECT [id] FROM [' . self::PERSONS_TABLE . '] WHERE [id] = %i',
            $personId,
        );
        return $row !== null && $row !== false;
    }

    /**
     * Souběžný import téhož loginu: unikátní index vyhraje jeden, druhý si
     * vítězův řádek přečte — výsledek je pro oba stejný uživatel.
     *
     * @param array<string, mixed> $row
     * @return array{0: int, 1: bool} id uživatele, true = založen tímto voláním
     */
    protected function insertUser(array $row): array
    {
        try {
            $this->db->insert(self::USERS_TABLE, $row)->execute();
        } catch (\Dibi\UniqueConstraintViolationException $e) {
            $existing = $this->db->fetch(
                'SELECT [id] FROM [' . self::USERS_TABLE . '] WHERE [login] = %s',
                $row['login'],
            );
            if ($existing === null || $existing === false) {
                throw $e;
            }
            return [(int) $existing['id'], false];
        }
        return [(int) $this->db->getInsertId(), true];
    }

    protected function updateUserPerson(int $userId, int $personId): void
    {
        $this->db->update(self::USERS_TABLE, ['person' => $personId])->where('[id] = %i', $userId)->execute();
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<string, mixed>
     */
    private function withIssues(array $canonical, array $issues): array
    {
        $canonical['_resolve'] = ['issues' => $issues];
        return $canonical;
    }
}
