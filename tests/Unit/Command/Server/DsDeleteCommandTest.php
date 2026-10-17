<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\DsDeleteCommand;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DatabaseManager;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobState;
use Shipard\Core\DevJobs\JobStatus;
use Shipard\Core\DevJobs\JobStep;
use Shipard\Core\DevJobs\JobStore;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDsDeleteCommand extends DsDeleteCommand
{
    public bool $terminal = true;

    public function __construct(
        ServerConfig $config,
        DatabaseManager $db,
        private readonly string $dataSourcesDir,
        private readonly JobStore $jobs,
    ) {
        parent::__construct($config, $db);
    }

    protected function getDataSourcesDir(ServerConfig $config): string
    {
        return $this->dataSourcesDir;
    }

    protected function jobStore(): JobStore
    {
        return $this->jobs;
    }

    protected function stdinIsTerminal(): bool
    {
        return $this->terminal;
    }
}

/**
 * `shpd-server ds-delete` (tasks/ds-delete.md, #103 D68–D69): preflight
 * (produkce, nečitelný main.json, reálná kopie, běžící úloha) nic nemění,
 * `--dry-run` nic nemění, potvrzení opsáním ID, pořadí mazání granty →
 * databáze → uživatel → adresář, varování u grantů, opakovatelnost po
 * částečném selhání.
 */
final class DsDeleteCommandTest extends TestCase
{
    private const ID = 'abcd-efgh-ijkl-mnop';
    private const DB = 'abcd_efgh_ijkl_mnop';
    private const USER = 'shpd_abcdefgh';

    private string $root;
    private string $dataSourcesDir;
    private string $dsDir;
    private JobStore $jobs;
    private MockObject&ServerConfig $config;
    private MockObject&DatabaseManager $db;
    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/shpd_ds_delete_' . bin2hex(random_bytes(5));
        $this->dataSourcesDir = $this->root . '/data-sources';
        $this->dsDir = $this->dataSourcesDir . '/' . self::ID;
        mkdir($this->dataSourcesDir, 0700, true);
        $this->jobs = new JobStore(
            $this->root . '/jobs',
            static fn (): bool => true,
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-17T10:00:00Z'),
        );
        $this->config = $this->createMock(ServerConfig::class);
        $this->config->method('load');
        $this->config->method('getMode')->willReturn('development');
        $this->db = $this->createMock(DatabaseManager::class);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    // ── Pomocné ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $extra */
    private function createDs(array $extra = [], bool $withUser = true): void
    {
        mkdir($this->dsDir . '/config', 0700, true);
        mkdir($this->dsDir . '/secrets', 0700, true);
        mkdir($this->dsDir . '/att/sub', 0700, true);
        file_put_contents($this->dsDir . '/secrets/db.password', 'secret');
        file_put_contents($this->dsDir . '/att/sub/file.pdf', 'pdf');
        $main = ['id' => self::ID, 'name' => 'Zkušební zdroj', 'database_name' => self::DB, 'dataClass' => 'free', 'created' => '2026-10-17T00:00:00Z'];
        if ($withUser) {
            $main['database_user'] = self::USER;
        }
        file_put_contents($this->dsDir . '/config/main.json', json_encode(array_merge($main, $extra), JSON_UNESCAPED_UNICODE));
    }

    private function tester(): CommandTester
    {
        $command = new TestableDsDeleteCommand($this->config, $this->db, $this->dataSourcesDir, $this->jobs);
        $app = new Application();
        $app->add($command);
        return new CommandTester($command);
    }

    private function expectNoMutation(): void
    {
        $this->db->expects($this->never())->method('revokeDatabasePrivileges');
        $this->db->expects($this->never())->method('dropDatabase');
        $this->db->expects($this->never())->method('dropUser');
    }

    /** Záznam volání mutačních metod v pořadí. */
    private function recordMutations(): void
    {
        $this->db->method('revokeDatabasePrivileges')->willReturnCallback(function (string $db, string $user, string $host, bool $grant): void {
            $this->calls[] = "revoke:{$user}@{$host}:{$db}" . ($grant ? ':grant' : '');
        });
        $this->db->method('dropDatabase')->willReturnCallback(function (string $db): void {
            $this->calls[] = "dropDatabase:{$db}";
        });
        $this->db->method('dropUser')->willReturnCallback(function (string $user): void {
            $this->calls[] = "dropUser:{$user}";
        });
    }

    private function runningJob(): string
    {
        $id = JobRecord::newId(new \DateTimeImmutable('2026-10-17T09:59:00Z'));
        $record = new JobRecord(
            $id,
            JobRecord::TYPE_RESEED,
            self::ID,
            'Zkušební zdroj',
            'cz-vat-payer',
            null,
            [],
            [new JobStep('dataset-seed', ['/usr/bin/php', 'shpd-ds', 'dataset-seed'], $this->dsDir)],
            '2026-10-17T09:59:00Z',
            null,
            60,
        );
        $this->jobs->ensureDir();
        $this->jobs->create($record);
        $state = JobState::pending(1);
        $state->status = JobStatus::RUNNING;
        $state->pid = 4242;
        $state->startedAt = '2026-10-17T09:59:01Z';
        $this->jobs->writeState($id, $state);
        return $id;
    }

    private function rrmdir(string $dir): void
    {
        if (is_link($dir) || !is_dir($dir)) {
            @unlink($dir);
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->rrmdir($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }

    // ── Preflight ───────────────────────────────────────────────────────────

    public function testInvalidIdIsRefusedBeforeAnything(): void
    {
        $this->expectNoMutation();
        $this->db->expects($this->never())->method('listDatabaseGrants');
        $tester = $this->tester();

        $this->assertSame(1, $tester->execute(['id' => 'nope', '--yes' => true]));
        $this->assertStringContainsString('Invalid data source ID', $tester->getDisplay());
    }

    public function testProductionIsRefusedEvenWithYes(): void
    {
        $this->createDs();
        $config = $this->createMock(ServerConfig::class);
        $config->method('load');
        $config->method('getMode')->willReturn('production');
        $this->config = $config;
        $this->expectNoMutation();
        $tester = $this->tester();

        $this->assertSame(1, $tester->execute(['id' => self::ID, '--yes' => true]));
        $this->assertStringContainsString('not available in production mode', $tester->getDisplay());
        $this->assertDirectoryExists($this->dsDir);
    }

    public function testMissingDataSourceIsRefused(): void
    {
        $this->expectNoMutation();
        $tester = $this->tester();

        $this->assertSame(1, $tester->execute(['id' => self::ID, '--yes' => true]));
        $this->assertStringContainsString('Data source not found', $tester->getDisplay());
    }

    public function testSymlinkedDirectoryIsRefused(): void
    {
        mkdir($this->root . '/elsewhere/config', 0700, true);
        file_put_contents($this->root . '/elsewhere/config/main.json', '{"dataClass":"free"}');
        symlink($this->root . '/elsewhere', $this->dsDir);
        $this->expectNoMutation();
        $tester = $this->tester();

        $this->assertSame(1, $tester->execute(['id' => self::ID, '--yes' => true]));
        $this->assertStringContainsString('symlink', $tester->getDisplay());
        $this->assertFileExists($this->root . '/elsewhere/config/main.json');
    }

    public function testUnreadableMainJsonIsRefused(): void
    {
        mkdir($this->dsDir . '/config', 0700, true);
        file_put_contents($this->dsDir . '/config/main.json', '{ not json');
        $this->expectNoMutation();
        $tester = $this->tester();

        $this->assertSame(1, $tester->execute(['id' => self::ID, '--yes' => true]));
        $this->assertStringContainsString('no readable config/main.json', $tester->getDisplay());
        $this->assertDirectoryExists($this->dsDir);
    }

    public function testRealCopyIsRefusedWithHint(): void
    {
        $this->createDs(['dataClass' => 'realCopy', 'purpose' => 'účtárna']);
        $this->expectNoMutation();
        $tester = $this->tester();

        $this->assertSame(1, $tester->execute(['id' => self::ID, '--yes' => true]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('not a free data source (data class realCopy)', $display);
        $this->assertStringContainsString('shpd-ds ds-class set free', $display);
        $this->assertDirectoryExists($this->dsDir);
    }

    public function testMissingDataClassCountsAsRealCopy(): void
    {
        mkdir($this->dsDir . '/config', 0700, true);
        file_put_contents($this->dsDir . '/config/main.json', json_encode(['id' => self::ID, 'name' => 'Bez třídy', 'database_name' => self::DB]));
        $this->expectNoMutation();
        $tester = $this->tester();

        $this->assertSame(1, $tester->execute(['id' => self::ID, '--yes' => true]));
        $this->assertStringContainsString('data class realCopy, not set in main.json', $tester->getDisplay());
        $this->assertDirectoryExists($this->dsDir);
    }

    public function testRunningDashboardJobIsRefused(): void
    {
        $this->createDs();
        $jobId = $this->runningJob();
        $this->expectNoMutation();
        $tester = $this->tester();

        $this->assertSame(1, $tester->execute(['id' => self::ID, '--yes' => true]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('running developer-dashboard job ' . $jobId, $display);
        $this->assertStringContainsString('reseed, running', $display);
        $this->assertDirectoryExists($this->dsDir);
    }

    public function testOwnDashboardJobPassedInJobOptionIsIgnored(): void
    {
        $this->createDs();
        $jobId = $this->runningJob();
        $this->recordMutations();
        $tester = $this->tester();

        $this->assertSame(0, $tester->execute(['id' => self::ID, '--yes' => true, '--job' => $jobId]));
        $this->assertSame(['dropDatabase:' . self::DB, 'dropUser:' . self::USER], $this->calls);
        $this->assertDirectoryDoesNotExist($this->dsDir);
    }

    public function testJobOptionDoesNotExcuseAnotherRunningJob(): void
    {
        $this->createDs();
        $jobId = $this->runningJob();
        $this->expectNoMutation();
        $tester = $this->tester();

        $this->assertSame(1, $tester->execute(['id' => self::ID, '--yes' => true, '--job' => '20261017-100000-zzzzzz']));
        $this->assertStringContainsString('running developer-dashboard job ' . $jobId, $tester->getDisplay());
        $this->assertDirectoryExists($this->dsDir);
    }

    public function testDatabaseServerFailureIsReportedBeforeConfirmation(): void
    {
        $this->createDs();
        $this->db->method('listDatabaseGrants')->willThrowException(new \RuntimeException('Access denied'));
        $this->expectNoMutation();
        $tester = $this->tester();

        $this->assertSame(1, $tester->execute(['id' => self::ID, '--yes' => true]));
        $this->assertStringContainsString('Cannot inspect the database server: Access denied', $tester->getDisplay());
        $this->assertDirectoryExists($this->dsDir);
    }

    // ── Dry run a potvrzení ─────────────────────────────────────────────────

    public function testDryRunListsPlanAndChangesNothing(): void
    {
        $this->createDs();
        $this->db->method('listDatabaseGrants')->willReturn([
            ['user' => self::USER, 'host' => 'localhost', 'db' => self::DB, 'grantOption' => false],
            ['user' => 'claude_ro', 'host' => 'localhost', 'db' => 'abcd\\_efgh\\_ijkl\\_mnop', 'grantOption' => true],
        ]);
        $this->db->method('databaseExists')->willReturn(true);
        $this->db->method('userExists')->willReturn(true);
        $this->expectNoMutation();
        $tester = $this->tester();

        $this->assertSame(0, $tester->execute(['id' => self::ID, '--dry-run' => true]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Dry run — this would be deleted', $display);
        $this->assertStringContainsString('Database:      ' . self::DB, $display);
        $this->assertStringContainsString('DB user:       ' . self::USER . '@localhost', $display);
        $this->assertStringContainsString('claude_ro@localhost on abcd\\_efgh\\_ijkl\\_mnop (with GRANT OPTION)', $display);
        $this->assertStringNotContainsString(self::USER . '@localhost on', $display, 'DS user is dropped whole, not listed as a grant');
        $this->assertStringContainsString('nothing deleted', $display);
        $this->assertDirectoryExists($this->dsDir);
        $this->assertFileExists($this->dsDir . '/secrets/db.password');
    }

    public function testDryRunMarksAlreadyGoneDatabaseAndUser(): void
    {
        $this->createDs();
        $this->db->method('databaseExists')->willReturn(false);
        $this->db->method('userExists')->willReturn(false);
        $tester = $this->tester();

        $this->assertSame(0, $tester->execute(['id' => self::ID, '--dry-run' => true]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString(self::DB . ' (already gone)', $display);
        $this->assertStringContainsString(self::USER . '@localhost (already gone)', $display);
        $this->assertStringContainsString('Other grants:  none', $display);
    }

    public function testNonInteractiveRunWithoutYesIsRefused(): void
    {
        $this->createDs();
        $this->expectNoMutation();
        $tester = $this->tester();

        $this->assertSame(1, $tester->execute(['id' => self::ID], ['interactive' => false]));
        $this->assertStringContainsString('pass --yes for a non-interactive run', $tester->getDisplay());
        $this->assertDirectoryExists($this->dsDir);
    }

    public function testRedirectedStdinWithoutYesIsRefused(): void
    {
        $this->createDs();
        $this->expectNoMutation();
        $command = new TestableDsDeleteCommand($this->config, $this->db, $this->dataSourcesDir, $this->jobs);
        $command->terminal = false;
        $app = new Application();
        $app->add($command);
        $tester = new CommandTester($command);

        $this->assertSame(1, $tester->execute(['id' => self::ID]));
        $this->assertStringContainsString('pass --yes for a non-interactive run', $tester->getDisplay());
        $this->assertDirectoryExists($this->dsDir);
    }

    public function testMismatchedConfirmationAborts(): void
    {
        $this->createDs();
        $this->expectNoMutation();
        $tester = $this->tester();
        $tester->setInputs(['abcd-efgh-ijkl-xxxx']);

        $this->assertSame(1, $tester->execute(['id' => self::ID]));
        $this->assertStringContainsString('Aborted — the ID does not match', $tester->getDisplay());
        $this->assertDirectoryExists($this->dsDir);
    }

    public function testTypedIdConfirmsAndDeletes(): void
    {
        $this->createDs();
        $this->recordMutations();
        $tester = $this->tester();
        $tester->setInputs([self::ID]);

        $this->assertSame(0, $tester->execute(['id' => self::ID]));
        $this->assertStringContainsString('Type the data source ID (' . self::ID . ') to confirm', $tester->getDisplay());
        $this->assertSame(['dropDatabase:' . self::DB, 'dropUser:' . self::USER], $this->calls);
        $this->assertDirectoryDoesNotExist($this->dsDir);
    }

    // ── Mazání ──────────────────────────────────────────────────────────────

    public function testYesDeletesInOrderAndRevokesOtherGrantsOnly(): void
    {
        $this->createDs();
        $this->db->method('listDatabaseGrants')->willReturn([
            ['user' => self::USER, 'host' => 'localhost', 'db' => self::DB, 'grantOption' => false],
            ['user' => 'claude_ro', 'host' => 'localhost', 'db' => 'abcd\\_efgh\\_ijkl\\_mnop', 'grantOption' => false],
            ['user' => 'reporting', 'host' => '', 'db' => self::DB, 'grantOption' => true],
        ]);
        $this->db->method('databaseExists')->willReturn(true);
        $this->db->method('userExists')->willReturn(true);
        $this->recordMutations();
        $tester = $this->tester();

        $this->assertSame(0, $tester->execute(['id' => self::ID, '--yes' => true]));
        $this->assertSame([
            'revoke:claude_ro@localhost:abcd\\_efgh\\_ijkl\\_mnop',
            'revoke:reporting@:' . self::DB . ':grant',
            'dropDatabase:' . self::DB,
            'dropUser:' . self::USER,
        ], $this->calls);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Revoked privileges of claude_ro@localhost on abcd\\_efgh\\_ijkl\\_mnop', $display);
        $this->assertStringContainsString('Revoked privileges of reporting on ' . self::DB, $display);
        $this->assertStringContainsString('Dropped database ' . self::DB, $display);
        $this->assertStringContainsString('Dropped DB user ' . self::USER . '@localhost', $display);
        $this->assertStringContainsString('Removed directory ' . $this->dsDir, $display);
        $this->assertStringContainsString('Data source ' . self::ID . ' deleted', $display);
        $this->assertDirectoryDoesNotExist($this->dsDir);
        $this->assertDirectoryExists($this->dataSourcesDir);
    }

    public function testRevokeFailureIsOnlyAWarning(): void
    {
        $this->createDs();
        $this->db->method('listDatabaseGrants')->willReturn([
            ['user' => 'claude_ro', 'host' => 'localhost', 'db' => self::DB, 'grantOption' => false],
        ]);
        $this->db->method('revokeDatabasePrivileges')->willThrowException(new \RuntimeException('There is no such grant'));
        $this->db->expects($this->once())->method('dropDatabase');
        $this->db->expects($this->once())->method('dropUser');
        $tester = $this->tester();

        $this->assertSame(0, $tester->execute(['id' => self::ID, '--yes' => true]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('[WARN] Cannot revoke privileges of claude_ro@localhost', $display);
        $this->assertStringContainsString('There is no such grant', $display);
        $this->assertDirectoryDoesNotExist($this->dsDir);
    }

    public function testDatabaseUserFallsBackToIdWhenMainJsonLacksIt(): void
    {
        $this->createDs([], withUser: false);
        $this->recordMutations();
        $tester = $this->tester();

        $this->assertSame(0, $tester->execute(['id' => self::ID, '--yes' => true]));
        $this->assertContains('dropUser:' . self::USER, $this->calls);
    }

    public function testDropDatabaseFailureKeepsDirectoryAndIsRepeatable(): void
    {
        $this->createDs();
        $this->db->method('databaseExists')->willReturn(true);
        $this->db->method('dropDatabase')->willThrowException(new \RuntimeException('Lock wait timeout'));
        $this->db->expects($this->never())->method('dropUser');
        $tester = $this->tester();

        $this->assertSame(1, $tester->execute(['id' => self::ID, '--yes' => true]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Failed to drop database ' . self::DB . ': Lock wait timeout', $display);
        $this->assertStringContainsString('run ds-delete again', $display);
        $this->assertDirectoryExists($this->dsDir);
        $this->assertFileExists($this->dsDir . '/config/main.json');

        // Druhý běh: databáze už neexistuje — není to chyba, smaže se zbytek.
        $this->db = $this->createMock(DatabaseManager::class);
        $this->db->method('databaseExists')->willReturn(false);
        $this->db->method('userExists')->willReturn(true);
        $this->recordMutations();
        $tester = $this->tester();

        $this->assertSame(0, $tester->execute(['id' => self::ID, '--yes' => true]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Database ' . self::DB . ' was already gone', $display);
        $this->assertStringContainsString('Dropped DB user ' . self::USER . '@localhost', $display);
        $this->assertSame(['dropDatabase:' . self::DB, 'dropUser:' . self::USER], $this->calls);
        $this->assertDirectoryDoesNotExist($this->dsDir);
    }

    public function testDirectoryRemovalDoesNotFollowSymlinks(): void
    {
        $this->createDs();
        mkdir($this->root . '/outside', 0700, true);
        file_put_contents($this->root . '/outside/keep.txt', 'keep');
        symlink($this->root . '/outside', $this->dsDir . '/att/linked');
        $tester = $this->tester();

        $this->assertSame(0, $tester->execute(['id' => self::ID, '--yes' => true]));
        $this->assertDirectoryDoesNotExist($this->dsDir);
        $this->assertFileExists($this->root . '/outside/keep.txt');
    }
}
