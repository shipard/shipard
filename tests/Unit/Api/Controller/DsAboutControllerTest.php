<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\DsAboutController;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Panel „O zdroji dat" (tasks/ds-about-panel.md): tolerance chybějících
 * tabulek (hosting, D6), cache skenu příloh (D4), chybějící att/, auth guard.
 */
final class DsAboutControllerTest extends TestCase
{
    /** Tabulky cizích modulů, které panel čte — na běžném DS všechny existují. */
    private const ALL_TABLES = [
        'base_persons_persons',
        'core_mail_mailboxes',
        'economy_codebooks_vat_registrations',
        'docs_core_heads',
        'core_mail_incoming_messages',
        'core_attachments_files',
    ];

    private string $dsDir;

    /** @var list<string> SQL zachycené mockem (fetchRow + fetchSingle) */
    private array $sqlLog = [];

    protected function setUp(): void
    {
        $this->dsDir = sys_get_temp_dir() . '/shpd_dsabout_' . uniqid('', true);
        mkdir($this->dsDir . '/config', 0755, true);
        file_put_contents($this->dsDir . '/config/main.json', json_encode([
            'id'            => 'test-test-test-test',
            'name'          => 'Testovací firma',
            'database_name' => 'x',
            'database_user' => 'x',
            'created'       => '2026-01-01T00:00:00+00:00',
            'modules'       => ['core.system'],
        ]));
    }

    protected function tearDown(): void
    {
        self::removeDir($this->dsDir);
    }

    // ── Auth ────────────────────────────────────────────────────────────

    public function testAnonymousGets401WithoutTouchingDb(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('fetchSingle');
        $db->expects($this->never())->method('fetchRow');

        $resp = $this->makeController($db, self::ALL_TABLES)->about(AuthContext::anonymous());

        $this->assertSame(401, $this->getStatus($resp));
    }

    // ── Běžný DS: všechny tabulky ───────────────────────────────────────

    public function testFullDataSource(): void
    {
        $db = $this->makeDb(
            settings: ['app.name' => 'Moje firma', 'economy.accountChart' => 'npo'],
            ownPerson: ['id' => 1, 'full_name' => 'Firma s.r.o.', 'company_id' => '12345678', 'tax_id' => 'CZ12345678'],
            mailAddress: 'prijem@example.test',
            vatRegistration: ['taxpayer_kind' => 1],
            counts: ['docs_core_heads' => 12, 'core_mail_incoming_messages' => 34, 'core_attachments_files' => 45],
            databaseBytes: '2048',
        );

        $resp = $this->makeController($db, self::ALL_TABLES)->about($this->auth());
        $data = $resp->getPayload()['data'];

        $this->assertSame(200, $this->getStatus($resp));
        $this->assertSame('Moje firma', $data['identity']['dsName']);
        $this->assertSame(
            ['fullName' => 'Firma s.r.o.', 'companyId' => '12345678', 'taxId' => 'CZ12345678'],
            $data['identity']['ownPerson'],
        );
        $this->assertSame('prijem@example.test', $data['identity']['mailAddress']);
        $this->assertTrue($data['profile']['vatPayer']);
        $this->assertSame(1, $data['profile']['taxpayerKind']);
        $this->assertSame('OSS', $data['profile']['taxpayerKindLabel']);
        $this->assertSame('npo', $data['profile']['accountChart']);
        $this->assertSame('test-test-test-test', $data['profile']['dsId']);
        $this->assertSame('2026-01-01T00:00:00+00:00', $data['profile']['created']);
        $this->assertSame(2048, $data['storage']['databaseBytes']);
        $this->assertSame(
            ['documents' => 12, 'incomingMail' => 34, 'attachmentFiles' => 45],
            $data['storage']['counts'],
        );
    }

    public function testEmptyDataSourceFallsBackGracefully(): void
    {
        // Čerstvý DS: žádná vlastní osoba, schránka ani registrace DPH;
        // app.name nenastaveno → název z main.json; neznámá osnova → null.
        $db = $this->makeDb(settings: ['economy.accountChart' => 'weird']);

        $data = $this->makeController($db, self::ALL_TABLES)->about($this->auth())->getPayload()['data'];

        $this->assertSame('Testovací firma', $data['identity']['dsName']);
        $this->assertNull($data['identity']['ownPerson']);
        $this->assertNull($data['identity']['mailAddress']);
        $this->assertFalse($data['profile']['vatPayer']);
        $this->assertNull($data['profile']['taxpayerKind']);
        $this->assertNull($data['profile']['taxpayerKindLabel']);
        $this->assertNull($data['profile']['accountChart']);
        $this->assertSame(0, $data['storage']['databaseBytes']);
    }

    // ── Hosting profil: tabulky neexistují (D6) ─────────────────────────

    public function testMissingTablesYieldNullsAndNoQueriesOnThem(): void
    {
        // counts by vrátily 99 — ale bez tabulky v registry se ptát nesmí.
        $db = $this->makeDb(counts: ['docs_core_heads' => 99]);

        $resp = $this->makeController($db, [])->about($this->auth());
        $data = $resp->getPayload()['data'];

        $this->assertSame(200, $this->getStatus($resp));
        $this->assertNull($data['identity']['ownPerson']);
        $this->assertNull($data['identity']['mailAddress']);
        $this->assertFalse($data['profile']['vatPayer']);
        $this->assertSame(
            ['documents' => 0, 'incomingMail' => 0, 'attachmentFiles' => 0],
            $data['storage']['counts'],
        );

        $this->assertNotEmpty($this->sqlLog); // settings + information_schema se ptát smí
        foreach ($this->sqlLog as $sql) {
            foreach (self::ALL_TABLES as $table) {
                $this->assertStringNotContainsString($table, $sql, "SQL na chybějící tabulku: {$sql}");
            }
        }
    }

    // ── Přílohy: sken + cache (D4) ──────────────────────────────────────

    public function testAttachmentsScanWritesCache(): void
    {
        mkdir($this->dsDir . '/att/sub', 0755, true);
        file_put_contents($this->dsDir . '/att/a.bin', str_repeat('x', 100));
        file_put_contents($this->dsDir . '/att/sub/b.bin', str_repeat('y', 250));

        $db = $this->makeDb();
        $db->expects($this->once())->method('execute')->with(
            $this->stringContains('core_system_settings'),
            DsAboutController::ATTACHMENTS_CACHE_KEY,
            $this->callback(static function (string $json): bool {
                $v = json_decode($json, true);
                return ($v['bytes'] ?? null) === 350
                    && ($v['files'] ?? null) === 2
                    && is_string($v['computedAt'] ?? null);
            }),
            $this->anything(),
        );

        $data = $this->makeController($db, self::ALL_TABLES)->about($this->auth())->getPayload()['data'];

        $this->assertSame(350, $data['storage']['attachments']['bytes']);
        $this->assertSame(2, $data['storage']['attachments']['files']);
        $this->assertNotEmpty($data['storage']['attachments']['computedAt']);
    }

    public function testFreshCacheSkipsScan(): void
    {
        // att/ NEEXISTUJE — kdyby se skenovalo, přišly by nuly, ne cache.
        $computedAt = date(DATE_ATOM, time() - 600);
        $db = $this->makeDb(settings: [
            DsAboutController::ATTACHMENTS_CACHE_KEY => ['bytes' => 777, 'files' => 3, 'computedAt' => $computedAt],
        ]);
        $db->expects($this->never())->method('execute');

        $data = $this->makeController($db, self::ALL_TABLES)->about($this->auth())->getPayload()['data'];

        $this->assertSame(
            ['bytes' => 777, 'files' => 3, 'computedAt' => $computedAt],
            $data['storage']['attachments'],
        );
    }

    public function testExpiredCacheIsRecomputed(): void
    {
        mkdir($this->dsDir . '/att', 0755, true);
        file_put_contents($this->dsDir . '/att/a.bin', str_repeat('x', 10));

        $stale = date(DATE_ATOM, time() - DsAboutController::ATTACHMENTS_CACHE_TTL - 60);
        $db = $this->makeDb(settings: [
            DsAboutController::ATTACHMENTS_CACHE_KEY => ['bytes' => 777, 'files' => 3, 'computedAt' => $stale],
        ]);
        $db->expects($this->once())->method('execute');

        $data = $this->makeController($db, self::ALL_TABLES)->about($this->auth())->getPayload()['data'];

        $this->assertSame(10, $data['storage']['attachments']['bytes']);
        $this->assertSame(1, $data['storage']['attachments']['files']);
        $this->assertNotSame($stale, $data['storage']['attachments']['computedAt']);
    }

    public function testMissingAttDirYieldsZerosWithoutCacheWrite(): void
    {
        $db = $this->makeDb();
        $db->expects($this->never())->method('execute');

        $data = $this->makeController($db, self::ALL_TABLES)->about($this->auth())->getPayload()['data'];

        $this->assertSame(0, $data['storage']['attachments']['bytes']);
        $this->assertSame(0, $data['storage']['attachments']['files']);
        $this->assertDirectoryDoesNotExist($this->dsDir . '/att'); // žádné mutace FS
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>  $settings        klíč core_system_settings → hodnota
     * @param array<string, int>    $counts          tabulka → COUNT(*)
     */
    private function makeDb(
        array $settings = [],
        ?array $ownPerson = null,
        ?string $mailAddress = null,
        ?array $vatRegistration = null,
        array $counts = [],
        string|int|null $databaseBytes = 0,
    ): MockObject&DataSourceConnection {
        $this->sqlLog = [];
        $db = $this->createMock(DataSourceConnection::class);

        $db->method('fetchRow')->willReturnCallback(
            function (mixed ...$args) use ($ownPerson, $vatRegistration): ?array {
                $sql = (string) $args[0];
                $this->sqlLog[] = $sql;
                if (str_contains($sql, 'base_persons_persons')) {
                    return $ownPerson;
                }
                if (str_contains($sql, 'economy_codebooks_vat_registrations')) {
                    return $vatRegistration;
                }
                $this->fail("Neočekávaný fetchRow: {$sql}");
            },
        );

        $db->method('fetchSingle')->willReturnCallback(
            function (mixed ...$args) use ($settings, $mailAddress, $counts, $databaseBytes): mixed {
                $sql = (string) $args[0];
                $this->sqlLog[] = $sql;
                if (str_contains($sql, 'core_system_settings')) {
                    $key = (string) ($args[1] ?? '');
                    return array_key_exists($key, $settings) ? json_encode($settings[$key]) : null;
                }
                if (str_contains($sql, 'core_mail_mailboxes')) {
                    return $mailAddress;
                }
                if (str_contains($sql, 'information_schema')) {
                    return $databaseBytes;
                }
                if (str_starts_with($sql, 'SELECT COUNT(*) FROM ')) {
                    return $counts[substr($sql, strlen('SELECT COUNT(*) FROM '))] ?? 0;
                }
                $this->fail("Neočekávaný fetchSingle: {$sql}");
            },
        );

        return $db;
    }

    /** @param list<string> $tables názvy tabulek v registry (panel zkoumá jen existenci klíče) */
    private function makeController(MockObject&DataSourceConnection $db, array $tables): DsAboutController
    {
        $registry = array_fill_keys($tables, true);
        return new DsAboutController($db, $this->makeConfig(), new DataSourceConfig($this->dsDir), 'cs', $registry);
    }

    private function makeConfig(): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $id === 'economy.codebooks.vatTaxpayerKinds'
                ? ['0' => ['name' => 'Klasický plátce'], '1' => ['name' => 'OSS']]
                : null,
        );
        return $config;
    }

    private function auth(): AuthContext
    {
        return new AuthContext(true, 1, 'session', 'shpd_st_test');
    }

    private function getStatus(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        return $ref->getProperty('status')->getValue($response);
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($dir);
    }
}
