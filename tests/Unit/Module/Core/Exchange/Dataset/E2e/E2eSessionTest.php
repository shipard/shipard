<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\E2e;

use PHPUnit\Framework\TestCase;
use Shipard\Api\SessionService;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2eException;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2eSession;

/**
 * Session proklikání (tasks/testing-phase7a.md D92, I6): uživatel `e2e`
 * administrátor + systémový + bez hesla, existující se nemění (varování),
 * `storageState` 0600 s tokenem jen v souboru, `close()` idempotentní.
 */
final class E2eSessionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shpd_e2e_sess_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->dir);
    }

    public function testEnsureUserCreatesTheSystemAdministratorWithoutPassword(): void
    {
        $db = E2eFakeConnection::create();
        $session = new E2eSession($db, new SessionService());

        $user = $session->ensureUser();

        $this->assertTrue($user['created']);
        $this->assertSame([], $user['warnings']);
        $this->assertSame(500, $user['id']);
        $this->assertSame('e2e', $user['login']);
        $this->assertTrue($user['is_admin']);
        $this->assertSame('e2e', $db->fetchedRows[0][1]);
        $this->assertCount(1, $db->inserted);
        $this->assertSame('core_system_users', $db->inserted[0]['table']);
        $this->assertSame([
            'login' => 'e2e',
            'password_hash' => null,
            'full_name' => E2eSession::FULL_NAME,
            'email' => null,
            'is_active' => 1,
            'is_admin' => 1,
            'is_system' => 1,
        ], $db->inserted[0]['data']);
        $this->assertSame(['id' => 500, 'login' => 'e2e', 'full_name' => E2eSession::FULL_NAME, 'is_admin' => true], $session->user());
    }

    public function testExistingUserIsReusedUnchangedWithWarnings(): void
    {
        $db = E2eFakeConnection::create();
        $db->userRow = ['id' => 7, 'login' => 'e2e', 'full_name' => 'Starý e2e', 'is_admin' => 0, 'is_active' => 1, 'is_system' => 0];
        $session = new E2eSession($db, new SessionService());

        $user = $session->ensureUser();

        $this->assertFalse($user['created']);
        $this->assertSame(7, $user['id']);
        $this->assertFalse($user['is_admin']);
        $this->assertCount(2, $user['warnings']);
        $this->assertStringContainsString('není administrátor', $user['warnings'][0]);
        $this->assertStringContainsString('není systémový', $user['warnings'][1]);
        $this->assertSame([], $db->inserted, 'existující uživatel se nepřepisuje');

        $db->userRow = ['id' => 7, 'login' => 'e2e', 'full_name' => 'e2e', 'is_admin' => 1, 'is_active' => 1, 'is_system' => 1];
        $this->assertSame([], (new E2eSession($db, new SessionService()))->ensureUser()['warnings']);
    }

    public function testInactiveUserIsRefused(): void
    {
        $db = E2eFakeConnection::create();
        $db->userRow = ['id' => 7, 'login' => 'e2e', 'full_name' => 'e2e', 'is_admin' => 1, 'is_active' => 0, 'is_system' => 1];

        $this->expectException(E2eException::class);
        $this->expectExceptionMessage('neaktivní');
        (new E2eSession($db, new SessionService()))->ensureUser();
    }

    public function testOpenWritesStorageStateWithTheTokenOnlyInTheFile(): void
    {
        $db = E2eFakeConnection::create();
        $session = new E2eSession($db, new SessionService());
        $session->ensureUser();
        $this->assertSame(1, $session->invalidateAll());
        $this->assertCount(1, $db->sessionDeletes(), 'úklid sessions z minulého běhu');

        $path = $session->open('http://127.0.0.1', $this->dir . '/', 5400);

        $this->assertSame($this->dir . '/storage-state.json', $path);
        $this->assertSame($path, $session->storagePath());
        $this->assertFileExists($path);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        $this->assertSame([], array_values(array_diff(scandir($this->dir) ?: [], ['.', '..', 'storage-state.json'])), 'dočasný soubor zmizel');

        $tokens = $db->sessionTokens();
        $this->assertCount(1, $tokens);
        $token = $tokens[0];
        $this->assertStringStartsWith('shpd_st_', $token);
        $sessionRow = $db->inserted[1];
        $this->assertSame('core_system_sessions', $sessionRow['table']);
        $this->assertSame(500, $sessionRow['data']['user_id']);
        $this->assertEqualsWithDelta(time() + 5400, strtotime((string) $sessionRow['data']['expires']), 5, 'TTL podle počtu karet (I7)');

        $state = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $state['cookies']);
        $this->assertCount(1, $state['origins']);
        $this->assertSame('http://127.0.0.1', $state['origins'][0]['origin']);
        $local = array_column($state['origins'][0]['localStorage'], 'value', 'name');
        $this->assertSame($token, $local['shpd_token']);
        $this->assertSame('cs', $local['shpd_language']);
        $this->assertSame(
            ['id' => 500, 'login' => 'e2e', 'full_name' => E2eSession::FULL_NAME, 'is_admin' => true, 'has_password' => false],
            json_decode($local['shpd_user'], true),
        );
        $this->assertStringNotContainsString($token, $path, 'token se nevrací ani v cestě');
    }

    public function testCloseInvalidatesSessionsRemovesTheFileAndIsIdempotent(): void
    {
        $db = E2eFakeConnection::create();
        $session = new E2eSession($db, new SessionService());
        $session->ensureUser();
        $path = $session->open('https://shipard.example:8443/', $this->dir, 3600);
        $this->assertSame('https://shipard.example:8443', json_decode((string) file_get_contents($path), true)['origins'][0]['origin']);

        $session->close();
        $this->assertFileDoesNotExist($path);
        $this->assertNull($session->storagePath());
        $this->assertCount(1, $db->sessionDeletes());
        $this->assertSame(500, $db->executed[0][1]);

        $session->close();
        $this->assertCount(1, $db->sessionDeletes(), 'druhé zavření nic nedělá');

        // close() bez open(): sessions uživatele se zneplatní i tak (výjimka mezi ensureUser a open).
        $other = new E2eSession($db, new SessionService());
        $other->ensureUser();
        $other->close();
        $this->assertCount(2, $db->sessionDeletes());

        // close() před ensureUser(): nic, žádná chyba.
        (new E2eSession($db, new SessionService()))->close();
        $this->assertCount(2, $db->sessionDeletes());
    }

    public function testOpenRequiresEnsureUserAndAWritableDirectory(): void
    {
        $db = E2eFakeConnection::create();
        $session = new E2eSession($db, new SessionService());
        try {
            $session->open('http://127.0.0.1', $this->dir, 3600);
            $this->fail('open() bez ensureUser()');
        } catch (\LogicException) {
        }

        $session->ensureUser();
        try {
            $session->open('http://127.0.0.1', $this->dir . '/missing', 3600);
            $this->fail('neexistující adresář');
        } catch (E2eException $e) {
            $this->assertStringContainsString('neexistuje nebo nejde zapisovat', $e->getMessage());
        }
        $this->assertSame([], $db->sessionTokens(), 'bez souboru žádná session');
    }

    public function testStorageStateShape(): void
    {
        $state = E2eSession::storageState('http://localhost:8080/', 'shpd_st_x', ['id' => 3, 'login' => 'e2e', 'full_name' => 'E', 'is_admin' => false]);

        $this->assertSame(['cookies', 'origins'], array_keys($state));
        $this->assertSame('http://localhost:8080', $state['origins'][0]['origin']);
        $this->assertSame(['shpd_token', 'shpd_user', 'shpd_language'], array_column($state['origins'][0]['localStorage'], 'name'));
        $this->assertSame('{"id":3,"login":"e2e","full_name":"E","is_admin":false,"has_password":false}', $state['origins'][0]['localStorage'][1]['value']);
    }
}
