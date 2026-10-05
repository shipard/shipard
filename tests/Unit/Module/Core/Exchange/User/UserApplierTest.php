<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\User;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Exchange\User\UserApplier;

/** Applier, který zápisy jen zaznamenává. */
class RecordingUserApplier extends UserApplier
{
    /** @var list<array<string, mixed>> */
    public array $inserted = [];
    /** @var list<array{user: int, person: int}> */
    public array $personUpdates = [];

    protected function insertUser(array $row): array
    {
        $this->inserted[] = $row;
        return [100 + count($this->inserted), true];
    }

    protected function updateUserPerson(int $userId, int $personId): void
    {
        $this->personUpdates[] = ['user' => $userId, 'person' => $personId];
    }
}

/**
 * Import uživatelů `shpd.system.user.v1` (#93 D7, D13): idempotentní párování
 * a hranice — nikdy aktivní účet, heslo ani admin, existující uživatel se
 * mění jen ve vazbě na Osobu.
 */
class UserApplierTest extends TestCase
{
    /** Osoby 12 a 13 v cílovém zdroji existují; 50 je vazba visící po ds-reset. */
    private const PERSONS = [12, 13];

    private const USERS = [
        ['id' => 1, 'login' => 'admin@example.test', 'email' => 'admin@example.test', 'is_active' => 1, 'is_system' => 0, 'person' => null],
        ['id' => 2, 'login' => 'jana', 'email' => 'Jana@Example.test', 'is_active' => 1, 'is_system' => 0, 'person' => 12],
        ['id' => 3, 'login' => 'import-77', 'email' => 'karel@example.test', 'is_active' => 0, 'is_system' => 0, 'person' => 50],
        ['id' => 4, 'login' => '_robot', 'email' => 'robot@example.test', 'is_active' => 1, 'is_system' => 1, 'person' => null],
        ['id' => 5, 'login' => 'dup-a', 'email' => 'sdileny@example.test', 'is_active' => 1, 'is_system' => 0, 'person' => null],
        ['id' => 6, 'login' => 'dup-b', 'email' => 'sdileny@example.test', 'is_active' => 1, 'is_system' => 0, 'person' => null],
    ];

    /** @var list<string> */
    private array $queries = [];

    private function db(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(function (string $sql, mixed ...$params): ?Row {
            $this->queries[] = $sql;
            if (str_contains($sql, 'base_persons_persons')) {
                return in_array($params[0], self::PERSONS, true) ? new Row(['id' => $params[0]]) : null;
            }
            foreach (self::USERS as $user) {
                if (mb_strtolower($user['login']) === mb_strtolower((string) $params[0])) {
                    return new Row(['id' => $user['id'], 'person' => $user['person']]);
                }
            }
            return null;
        });
        $db->method('fetchAll')->willReturnCallback(function (string $sql, mixed ...$params): array {
            $this->queries[] = $sql;
            $this->assertStringContainsString('[is_active] = 1 AND [is_system] = 0', $sql);
            $rows = [];
            foreach (self::USERS as $user) {
                if ($user['is_active'] === 1 && $user['is_system'] === 0
                    && mb_strtolower($user['email']) === mb_strtolower((string) $params[0])) {
                    $rows[] = new Row(['id' => $user['id'], 'person' => $user['person']]);
                }
            }
            return array_slice($rows, 0, 2);
        });
        return $db;
    }

    private function applier(bool $hasPersonColumn = true): RecordingUserApplier
    {
        return new RecordingUserApplier($this->db(), new SchemaValidator(SchemaLoader::default()), $hasPersonColumn);
    }

    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return $override + [
            'format'   => 'shpd.system.user.v1',
            'login'    => 'novy@example.test',
            'email'    => 'novy@example.test',
            'fullName' => 'Nový Autor',
            'person'   => 13,
        ];
    }

    // ── Založení ────────────────────────────────────────────────────────────

    public function testUnknownUserIsCreatedInactiveWithoutPasswordOrAdmin(): void
    {
        $applier = $this->applier();

        $result = $applier->apply($this->payload());

        $this->assertTrue($result->success);
        $this->assertSame(201, $result->statusCode);
        $this->assertSame(101, $result->savedId);
        $this->assertSame([[
            'login'         => 'novy@example.test',
            'email'         => 'novy@example.test',
            'full_name'     => 'Nový Autor',
            'password_hash' => null,
            'is_active'     => 0,
            'is_admin'      => 0,
            'is_system'     => 0,
            'person'        => 13,
        ]], $applier->inserted);
    }

    public function testSyntheticLoginWithoutEmailIsCreated(): void
    {
        $applier = $this->applier();

        $result = $applier->apply($this->payload(['login' => 'import-91', 'email' => null, 'person' => null]));

        $this->assertSame(201, $result->statusCode);
        $this->assertNull($applier->inserted[0]['email']);
        $this->assertNull($applier->inserted[0]['person']);
    }

    // ── Párování ────────────────────────────────────────────────────────────

    public function testSameLoginReturnsExistingUserEvenWhenInactive(): void
    {
        $applier = $this->applier();

        $result = $applier->apply($this->payload(['login' => 'import-77', 'email' => 'jiny@example.test', 'person' => null]));

        $this->assertSame(200, $result->statusCode);
        $this->assertSame(3, $result->savedId);
        $this->assertSame([], $applier->inserted);
    }

    public function testActiveUserWithSameEmailIsMatchedCaseInsensitively(): void
    {
        $applier = $this->applier();

        $result = $applier->apply($this->payload(['login' => 'jana@example.test', 'email' => 'jana@example.test', 'person' => 12]));

        $this->assertSame(200, $result->statusCode);
        $this->assertSame(2, $result->savedId);
        $this->assertSame([], $applier->inserted);
    }

    public function testInactiveSystemOrAmbiguousEmailDoesNotMatch(): void
    {
        foreach (['karel@example.test', 'robot@example.test', 'sdileny@example.test'] as $email) {
            $applier = $this->applier();

            $result = $applier->apply($this->payload(['login' => 'import-' . md5($email), 'email' => $email, 'person' => null]));

            $this->assertSame(201, $result->statusCode, $email);
            $this->assertCount(1, $applier->inserted, $email);
        }
    }

    public function testApplyIsIdempotent(): void
    {
        // První běh by uživatele založil; druhý běh ho najde podle loginu.
        $applier = $this->applier();
        $first = $applier->apply($this->payload(['login' => 'jana', 'email' => null]));
        $second = $applier->apply($this->payload(['login' => 'jana', 'email' => null]));

        $this->assertSame([2, 2], [$first->savedId, $second->savedId]);
        $this->assertSame([], $applier->inserted);
    }

    // ── Existující uživatel: mění se jen `person` ───────────────────────────

    public function testExistingUserGetsMissingPerson(): void
    {
        $applier = $this->applier();

        $applier->apply($this->payload(['login' => 'admin@example.test', 'email' => 'admin@example.test', 'person' => 13]));

        $this->assertSame([['user' => 1, 'person' => 13]], $applier->personUpdates);
    }

    public function testDanglingPersonAfterResetIsRepaired(): void
    {
        $applier = $this->applier();

        $applier->apply($this->payload(['login' => 'import-77', 'email' => null, 'person' => 13]));

        $this->assertSame([['user' => 3, 'person' => 13]], $applier->personUpdates);
    }

    public function testValidExistingPersonIsKept(): void
    {
        $applier = $this->applier();

        // Jana už je navázaná na existující Osobu 12 — import ji nepřepojí
        // a nezmění ani jméno či e-mail.
        $result = $applier->apply($this->payload(['login' => 'jana', 'email' => 'x@example.test', 'fullName' => 'Jiné Jméno', 'person' => 13]));

        $this->assertSame(2, $result->savedId);
        $this->assertSame([], $applier->personUpdates);
        $this->assertSame([], $applier->inserted);
    }

    public function testPayloadWithoutPersonNeverClearsExistingLink(): void
    {
        $applier = $this->applier();

        $applier->apply($this->payload(['login' => 'import-77', 'email' => null, 'person' => null]));

        $this->assertSame([], $applier->personUpdates);
    }

    // ── Zdroj dat bez Osob ──────────────────────────────────────────────────

    public function testDataSourceWithoutPersonColumnIgnoresPerson(): void
    {
        $applier = $this->applier(hasPersonColumn: false);

        $created = $applier->apply($this->payload(['person' => 999]));
        $existing = $applier->apply($this->payload(['login' => 'jana', 'person' => 999]));

        $this->assertSame(201, $created->statusCode);
        $this->assertArrayNotHasKey('person', $applier->inserted[0]);
        $this->assertSame(2, $existing->savedId);
        $this->assertSame([], $applier->personUpdates);
        foreach ($this->queries as $sql) {
            $this->assertStringNotContainsString('person', $sql);
        }
    }

    public function testCreateDetectsPersonColumnFromTableDefinition(): void
    {
        $columns = [
            ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true, 'autoIncrement' => true],
            ['id' => 'login', 'name' => 'Login', 'type' => 'varchar', 'length' => 100],
        ];
        $with = TableDefinition::fromArray(['tableId' => 1, 'name' => 'Users', 'columns' => [
            ...$columns, ['id' => 'person', 'name' => 'Person', 'type' => 'int', 'nullable' => true],
        ]]);
        $without = TableDefinition::fromArray(['tableId' => 1, 'name' => 'Users', 'columns' => $columns]);

        // S Osobami se neexistující Osoba odmítne, bez nich se pole ignoruje.
        $payload = $this->payload(['person' => 999]);
        $this->assertFalse(UserApplier::create($this->db(), ['core_system_users' => $with])->validate($payload)->success);
        $this->assertTrue(UserApplier::create($this->db(), ['core_system_users' => $without])->validate($payload)->success);
        $this->assertTrue(UserApplier::create($this->db(), [])->validate($payload)->success);
    }

    // ── Validace ────────────────────────────────────────────────────────────

    public function testUnknownPersonIsRejectedWithoutWriting(): void
    {
        $applier = $this->applier();

        $result = $applier->apply($this->payload(['person' => 999]));

        $this->assertFalse($result->success);
        $this->assertSame('validation_failed', $result->errorCode);
        $this->assertSame(422, $result->statusCode);
        $this->assertSame('person_not_found', $result->canonical['_resolve']['issues'][0]['code']);
        $this->assertSame([], $applier->inserted);
    }

    public function testSchemaRejectsPrivilegeFieldsAndBlankIdentity(): void
    {
        $invalid = [
            'pole navíc — admin'  => $this->payload(['isAdmin' => true]),
            'pole navíc — heslo'  => $this->payload(['password' => 'tajne']),
            'pole navíc — aktivní' => $this->payload(['isActive' => true]),
            'prázdný login'       => $this->payload(['login' => '   ']),
            'chybí jméno'         => array_diff_key($this->payload(), ['fullName' => 1]),
            'jiný formát'         => $this->payload(['format' => 'shpd.persons.person']),
            'person jako text'    => $this->payload(['person' => '13']),
        ];
        foreach ($invalid as $label => $payload) {
            $applier = $this->applier();

            $result = $applier->apply($payload);

            $this->assertSame('schema_invalid', $result->errorCode, $label);
            $this->assertSame(400, $result->statusCode, $label);
            $this->assertSame([], $applier->inserted, $label);
        }
    }

    public function testValidateReportsMatchWithoutWriting(): void
    {
        $applier = $this->applier();

        $this->assertSame(2, $applier->validate($this->payload(['login' => 'jana']))->savedId);
        $this->assertNull($applier->validate($this->payload())->savedId);
        // Visící vazbu opravuje až apply.
        $applier->validate($this->payload(['login' => 'import-77', 'email' => null, 'person' => 13]));

        $this->assertSame([], $applier->inserted);
        $this->assertSame([], $applier->personUpdates);
    }

    // ── unq_login ───────────────────────────────────────────────────────────

    public function testConcurrentInsertOfSameLoginReturnsWinner(): void
    {
        $lookups = 0;
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturn([]);
        // 1. párování podle loginu nic nenajde, 2. po kolizi už řádek je.
        $db->method('fetch')->willReturnCallback(static function (string $sql) use (&$lookups): ?Row {
            if (str_contains($sql, 'base_persons_persons')) {
                return new Row(['id' => 13]);
            }
            return ++$lookups === 1 ? null : new Row(['id' => 42]);
        });
        $db->method('insert')->willThrowException(new \Dibi\UniqueConstraintViolationException('Duplicate entry'));

        $applier = new UserApplier($db, new SchemaValidator(SchemaLoader::default()), true);
        $result = $applier->apply($this->payload());

        $this->assertTrue($result->success);
        $this->assertSame(42, $result->savedId);
        $this->assertSame(200, $result->statusCode);
    }
}
