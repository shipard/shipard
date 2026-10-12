<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\DsSecretsRotateCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Server\FileOwnership;
use Shipard\Tests\Fixtures\Core\Server\RecordingFileOwnership;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDsSecretsRotateCommand extends DsSecretsRotateCommand
{
    public function __construct(
        DataSourceConfig $cfg,
        ?DataSourceConnection $conn,
        private readonly string $modulesPath,
        private readonly string $dsDir,
        ?FileOwnership $ownership = null,
    ) {
        parent::__construct($cfg, $conn, null, $ownership);
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }

    protected function getModulePathResolver(): ModulePathResolver
    {
        return new ModulePathResolver([$this->modulesPath]);
    }
}

class DsSecretsRotateCommandTest extends TestCase
{
    private string $tempDir;
    private string $modulesPath;
    private string $dsDir;
    private MockObject $dsConfig;
    private MockObject $dsConnection;

    /** @var array<int, string>  In-memory storage: id → ciphertext, simulates one encrypted column. */
    private array $rowStorage = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd-secrets-rotate-' . bin2hex(random_bytes(8));
        $this->modulesPath = $this->tempDir . '/modules';
        $this->dsDir = $this->tempDir . '/ds';
        mkdir($this->modulesPath, 0755, true);
        mkdir($this->dsDir . '/config', 0755, true);
        $this->writeModuleWithEncryptedColumn();

        $this->dsConfig = $this->createMock(DataSourceConfig::class);
        $this->dsConfig->method('getModules')->willReturn(['test.unit']);
        $this->dsConfig->method('getDataSourceDir')->willReturn($this->dsDir);

        $this->dsConnection = $this->createMock(DataSourceConnection::class);
        $this->wireStatefulMock();

        DsSecretCipher::resetCache();
    }

    protected function tearDown(): void
    {
        DsSecretCipher::resetCache();
        $this->rmdirRecursive($this->tempDir);
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writeModuleWithEncryptedColumn(): void
    {
        $moduleDir = $this->modulesPath . '/test/unit';
        mkdir($moduleDir . '/tables', 0755, true);
        file_put_contents($moduleDir . '/module.jsonc', json_encode([
            'id' => 'test.unit',
            'name' => 'Test',
            'dependencies' => [],
            'tables' => ['test_unit_secret'],
            'extensions' => [],
            'config' => [],
        ]));
        file_put_contents($moduleDir . '/tables/test_unit_secret.jsonc', json_encode([
            'tableId' => 1,
            'name' => 'test_unit_secret',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true, 'autoIncrement' => true, 'nullable' => false],
                ['id' => 'api_key', 'name' => 'API key', 'type' => 'encrypted_text', 'nullable' => true],
            ],
        ]));
    }

    private function wireStatefulMock(): void
    {
        $this->dsConnection->method('fetchAll')->willReturnCallback(function (string $sql): array {
            if (str_contains($sql, 'COUNT(*)')) {
                return [['c' => count($this->rowStorage)]];
            }
            $rows = [];
            foreach ($this->rowStorage as $id => $val) {
                $rows[] = ['id' => $id, 'val' => $val];
            }
            return $rows;
        });
        $this->dsConnection->method('updateWhere')->willReturnCallback(
            function (string $table, array $data, string $where, ...$params): void {
                $id = $params[0];
                $this->rowStorage[$id] = $data['api_key'];
            },
        );
    }

    private function makeTester(?FileOwnership $ownership = null): CommandTester
    {
        $command = new TestableDsSecretsRotateCommand(
            $this->dsConfig,
            $this->dsConnection,
            $this->modulesPath,
            $this->dsDir,
            $ownership,
        );
        $app = new Application();
        $app->add($command);
        return new CommandTester($command);
    }

    private function seedStorage(array $plaintexts): void
    {
        $cipher = DsSecretCipher::forConfig($this->dsConfig);
        foreach ($plaintexts as $id => $plain) {
            $this->rowStorage[$id] = $cipher->encrypt($plain);
        }
    }

    private function listKeyFiles(): array
    {
        $secretsDir = $this->dsDir . '/secrets';
        if (!is_dir($secretsDir)) {
            return [];
        }
        $files = [];
        foreach (scandir($secretsDir) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $files[] = $entry;
            }
        }
        sort($files);
        return $files;
    }

    public function testHappyPathRotatesKeyAndReEncryptsRows(): void
    {
        DsSecretCipher::generateKey($this->dsDir);
        $oldKeyContent = file_get_contents($this->dsDir . '/secrets/secrets.key');

        $plaintexts = [1 => 'secret-1', 2 => 'secret-2', 7 => 'secret-7', 11 => 'secret-11', 42 => 'secret-42'];
        $this->seedStorage($plaintexts);
        $oldCiphertexts = $this->rowStorage;

        $this->dsConnection->expects($this->once())->method('begin');
        $this->dsConnection->expects($this->once())->method('commit');
        $this->dsConnection->expects($this->never())->method('rollback');

        $tester = $this->makeTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Re-encrypted test_unit_secret.api_key (5 rows)', $tester->getDisplay());
        $this->assertStringContainsString('Rotation complete: 5 rows re-encrypted', $tester->getDisplay());

        // New key file present, old backed up
        $files = $this->listKeyFiles();
        $this->assertContains('secrets.key', $files);
        $bakFiles = array_values(array_filter($files, fn(string $f) => str_ends_with($f, '.bak')));
        $this->assertCount(1, $bakFiles);
        $this->assertNotContains('secrets.key.tmp', $files);

        // .bak contains the OLD key
        $this->assertSame($oldKeyContent, file_get_contents($this->dsDir . '/secrets/' . $bakFiles[0]));
        // secrets.key contains the NEW key (different content)
        $this->assertNotSame($oldKeyContent, file_get_contents($this->dsDir . '/secrets/secrets.key'));
        $this->assertSame(0600, fileperms($this->dsDir . '/secrets/secrets.key') & 0777);

        // Each row re-encrypted: ciphertext changed, but new key can decrypt to original plaintext
        DsSecretCipher::resetCache();
        $newCipher = DsSecretCipher::forConfig($this->dsConfig);
        foreach ($plaintexts as $id => $plain) {
            $this->assertNotSame($oldCiphertexts[$id], $this->rowStorage[$id], "Row {$id} ciphertext should have changed");
            $this->assertSame($plain, $newCipher->decrypt($this->rowStorage[$id]));
        }
    }

    public function testDryRunMakesNoChanges(): void
    {
        DsSecretCipher::generateKey($this->dsDir);
        $originalKeyContent = file_get_contents($this->dsDir . '/secrets/secrets.key');
        $originalFiles = $this->listKeyFiles();

        $this->seedStorage([1 => 'a', 2 => 'b', 3 => 'c']);
        $originalStorage = $this->rowStorage;

        $this->dsConnection->expects($this->never())->method('begin');
        $this->dsConnection->expects($this->never())->method('commit');
        $this->dsConnection->expects($this->never())->method('updateWhere');

        $tester = $this->makeTester();
        $exitCode = $tester->execute(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Dry-run mode', $tester->getDisplay());
        $this->assertStringContainsString('Would re-encrypt test_unit_secret.api_key — 3 rows', $tester->getDisplay());

        $this->assertSame($originalFiles, $this->listKeyFiles());
        $this->assertSame($originalKeyContent, file_get_contents($this->dsDir . '/secrets/secrets.key'));
        $this->assertSame($originalStorage, $this->rowStorage);
    }

    public function testFailureMidRotationRollsBackAndKeepsOldKey(): void
    {
        DsSecretCipher::generateKey($this->dsDir);
        $oldKeyContent = file_get_contents($this->dsDir . '/secrets/secrets.key');
        $this->seedStorage([1 => 'a', 2 => 'b']);
        $originalStorage = $this->rowStorage;

        // Override updateWhere to throw on second call
        $this->dsConnection = $this->createMock(DataSourceConnection::class);
        $this->dsConfig = $this->createMock(DataSourceConfig::class);
        $this->dsConfig->method('getModules')->willReturn(['test.unit']);
        $this->dsConfig->method('getDataSourceDir')->willReturn($this->dsDir);
        $this->wireStatefulMock();

        $callCount = 0;
        $this->dsConnection->method('updateWhere')->willReturnCallback(
            function () use (&$callCount): void {
                $callCount++;
                if ($callCount === 2) {
                    throw new \RuntimeException('Simulated DB failure');
                }
            },
        );

        $this->dsConnection->expects($this->once())->method('begin');
        $this->dsConnection->expects($this->never())->method('commit');
        $this->dsConnection->expects($this->once())->method('rollback');

        $tester = $this->makeTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Re-encryption failed (rolled back)', $tester->getDisplay());
        $this->assertStringContainsString('Simulated DB failure', $tester->getDisplay());

        // Key file untouched, no .bak, no .tmp
        $files = $this->listKeyFiles();
        $this->assertSame(['secrets.key'], $files);
        $this->assertSame($oldKeyContent, file_get_contents($this->dsDir . '/secrets/secrets.key'));
    }

    public function testRotateTwiceInARow(): void
    {
        DsSecretCipher::generateKey($this->dsDir);
        $this->seedStorage([1 => 'first', 2 => 'second']);

        $tester1 = $this->makeTester();
        $exit1 = $tester1->execute([]);
        $this->assertSame(Command::SUCCESS, $exit1);

        // After first rotation, storage has new ciphertexts; key file is the new one.
        // Second rotation must read the NEW key and rotate again.
        DsSecretCipher::resetCache();

        // Re-make connection mocks (PHPUnit forbids re-using expects() across calls)
        $this->dsConnection = $this->createMock(DataSourceConnection::class);
        $this->wireStatefulMock();

        $tester2 = $this->makeTester();
        $exit2 = $tester2->execute([]);
        $this->assertSame(Command::SUCCESS, $exit2);

        // Two .bak files now
        $bakFiles = array_values(array_filter(
            $this->listKeyFiles(),
            fn(string $f) => str_ends_with($f, '.bak'),
        ));
        $this->assertCount(2, $bakFiles);

        // Final key still decrypts the rows back to original plaintext
        DsSecretCipher::resetCache();
        $cipher = DsSecretCipher::forConfig($this->dsConfig);
        $this->assertSame('first', $cipher->decrypt($this->rowStorage[1]));
        $this->assertSame('second', $cipher->decrypt($this->rowStorage[2]));
    }

    // ── Vlastník nového klíče (tasks/secrets-rotate-key-owner.md) ──────────

    public function testNewKeyGetsOwnerOfDataSourceBeforeAnyDbChange(): void
    {
        DsSecretCipher::generateKey($this->dsDir);
        $this->seedStorage([1 => 'a', 2 => 'b']);
        $main = $this->dsDir . '/config/main.json';
        file_put_contents($main, '{}');

        // Běh pod sudo: tmp klíč vzniká jako root:root, main.json patří zdroji dat.
        $ownership = new RecordingFileOwnership();
        $ownership->owners[$main] = ['uid' => 12345, 'gid' => 54321];

        $tester = $this->makeTester($ownership);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $tmp = $this->dsDir . '/secrets/secrets.key.tmp';
        $this->assertSame([
            ['chown', $tmp, 12345],
            ['chgrp', $tmp, 54321],
        ], $ownership->calls);
        $this->assertSame($main, $ownership->lookups[0]);
        $this->assertNotContains('secrets.key.tmp', $this->listKeyFiles());

        DsSecretCipher::resetCache();
        $cipher = DsSecretCipher::forConfig($this->dsConfig);
        $this->assertSame('a', $cipher->decrypt($this->rowStorage[1]));
    }

    public function testRefusesRotationBeforeDbChangesWhenOwnerCannotBeSet(): void
    {
        DsSecretCipher::generateKey($this->dsDir);
        $oldKeyContent = file_get_contents($this->dsDir . '/secrets/secrets.key');
        $this->seedStorage([1 => 'a', 2 => 'b']);
        $originalStorage = $this->rowStorage;
        $main = $this->dsDir . '/config/main.json';
        file_put_contents($main, '{}');

        $ownership = new RecordingFileOwnership();
        $ownership->owners[$main] = ['uid' => 12345, 'gid' => 54321];
        $ownership->chownResult = false;

        $this->dsConnection->expects($this->never())->method('begin');
        $this->dsConnection->expects($this->never())->method('updateWhere');

        $tester = $this->makeTester($ownership);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Refusing to rotate', $display);
        $this->assertStringContainsString('Fix: chown ', $display);
        $this->assertStringContainsString('Nothing was changed', $display);

        // Klíč netknutý, žádný .bak ani .tmp, řádky beze změny.
        $this->assertSame(['secrets.key'], $this->listKeyFiles());
        $this->assertSame($oldKeyContent, file_get_contents($this->dsDir . '/secrets/secrets.key'));
        $this->assertSame($originalStorage, $this->rowStorage);
    }
}
