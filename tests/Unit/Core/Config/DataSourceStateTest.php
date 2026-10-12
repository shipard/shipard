<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\DataSourceState;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Tests\Fixtures\Core\Server\RecordingFileOwnership;

class DataSourceStateTest extends TestCase
{
    private string $dsDir;
    private string $logPath;

    protected function setUp(): void
    {
        ErrorLogger::resetForTesting();
        $this->dsDir = sys_get_temp_dir() . '/shpd-ds-state-' . uniqid();
        mkdir($this->dsDir . '/config', 0755, true);
        $this->logPath = $this->dsDir . '/shipard.log';
        ErrorLogger::setLogPath($this->logPath);
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        foreach (glob($this->dsDir . '/config/*') ?: [] as $f) {
            @chmod($f, 0644);
            unlink($f);
        }
        @rmdir($this->dsDir . '/config');
        @unlink($this->logPath);
        @rmdir($this->dsDir);
    }

    private function writeState(string $json): void
    {
        file_put_contents(DataSourceState::filePath($this->dsDir), $json);
    }

    private function logContents(): string
    {
        return is_file($this->logPath) ? (string) file_get_contents($this->logPath) : '';
    }

    public function testMissingFileIsActiveWithoutOverlay(): void
    {
        $s = DataSourceState::load($this->dsDir);
        $this->assertSame('active', $s->getState());
        $this->assertSame('active', $s->getEffectiveState());
        $this->assertFalse($s->isMaintenanceActive());
        $this->assertFalse($s->blocksHttp());
        $this->assertFalse($s->isFromFile());
        $this->assertFalse($s->isCorrupted());
        $this->assertTrue($s->isCronEnabled());
        $this->assertSame('', $this->logContents());
    }

    public function testAllLifecycleStatesLoad(): void
    {
        $expectations = [
            'active' => false,
            'read_only' => false,
            'suspended' => true,
            'pending_deletion' => true,
        ];
        foreach ($expectations as $state => $blocks) {
            $this->writeState(json_encode(['version' => 1, 'state' => $state]));
            $s = DataSourceState::load($this->dsDir);
            $this->assertSame($state, $s->getState(), $state);
            $this->assertSame($state, $s->getEffectiveState(), $state);
            $this->assertSame($blocks, $s->blocksHttp(), $state);
            $this->assertTrue($s->isFromFile());
            $this->assertFalse($s->isCorrupted());
        }
    }

    public function testMaintenanceOverlayMakesEffectiveStateSuspended(): void
    {
        $this->writeState(json_encode([
            'version' => 1,
            'state' => 'read_only',
            'maintenance' => ['reason' => 'import', 'since' => '2026-09-01T10:00:00Z'],
            'changedBy' => 'cli',
            'changed' => '2026-09-01T10:00:00Z',
        ]));
        $s = DataSourceState::load($this->dsDir);
        $this->assertSame('read_only', $s->getState());
        $this->assertTrue($s->isMaintenanceActive());
        $this->assertSame('suspended', $s->getEffectiveState());
        $this->assertTrue($s->blocksHttp());
        $this->assertSame('import', $s->getMaintenanceReason());
        $this->assertSame('2026-09-01T10:00:00Z', $s->getMaintenanceSince());
        $this->assertSame('cli', $s->getChangedBy());
    }

    public function testDeleteAfterIsPreserved(): void
    {
        $this->writeState(json_encode([
            'version' => 1,
            'state' => 'pending_deletion',
            'deleteAfter' => '2026-10-01T00:00:00Z',
        ]));
        $s = DataSourceState::load($this->dsDir);
        $this->assertSame('2026-10-01T00:00:00Z', $s->getDeleteAfter());
        $this->assertTrue($s->blocksHttp());
    }

    /** @return iterable<string, array{string}> */
    public static function corruptedFiles(): iterable
    {
        yield 'invalid json' => ['{not json'];
        yield 'not an object' => ['"active"'];
        yield 'unknown state' => [json_encode(['version' => 1, 'state' => 'frozen'])];
        yield 'missing state' => [json_encode(['version' => 1])];
        yield 'unknown version' => [json_encode(['version' => 2, 'state' => 'active'])];
        yield 'missing version' => [json_encode(['state' => 'active'])];
        yield 'unknown reason' => [json_encode(['version' => 1, 'state' => 'active', 'maintenance' => ['reason' => 'vacation']])];
        yield 'maintenance not object' => [json_encode(['version' => 1, 'state' => 'active', 'maintenance' => 'import'])];
        yield 'cron not boolean (string)' => [json_encode(['version' => 1, 'state' => 'active', 'cron' => 'no'])];
        yield 'cron not boolean (int)' => [json_encode(['version' => 1, 'state' => 'active', 'cron' => 0])];
        yield 'empty file' => [''];
    }

    #[DataProvider('corruptedFiles')]
    public function testCorruptedFileFailsClosedAsSuspended(string $content): void
    {
        $this->writeState($content);
        $s = DataSourceState::load($this->dsDir);
        $this->assertSame('suspended', $s->getState());
        $this->assertSame('suspended', $s->getEffectiveState());
        $this->assertTrue($s->blocksHttp());
        $this->assertTrue($s->isCorrupted());
        $this->assertTrue($s->isFromFile());
        $log = $this->logContents();
        $this->assertStringContainsString('fail-closed', $log);
        $this->assertStringContainsString('"level":"error"', $log);
    }

    public function testUnreadableFileFailsClosed(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores file permissions');
        }
        $this->writeState(json_encode(['version' => 1, 'state' => 'active']));
        chmod(DataSourceState::filePath($this->dsDir), 0000);
        $s = DataSourceState::load($this->dsDir);
        $this->assertSame('suspended', $s->getEffectiveState());
        $this->assertTrue($s->isCorrupted());
        $this->assertStringContainsString('not readable', $this->logContents());
    }

    public function testSaveRoundTripAndAtomicity(): void
    {
        $now = new \DateTimeImmutable('2026-09-02 12:34:56', new \DateTimeZone('Europe/Prague'));
        $saved = DataSourceState::active()
            ->withState('read_only')
            ->withMaintenance('migration', $now)
            ->save($this->dsDir, 'cli', $now);

        $this->assertSame('2026-09-02T10:34:56Z', $saved->getChanged());
        $this->assertSame('cli', $saved->getChangedBy());
        $this->assertFileDoesNotExist(DataSourceState::filePath($this->dsDir) . '.tmp');

        $raw = json_decode((string) file_get_contents(DataSourceState::filePath($this->dsDir)), true);
        $this->assertSame([
            'version' => 1,
            'state' => 'read_only',
            'maintenance' => ['reason' => 'migration', 'since' => '2026-09-02T10:34:56Z'],
            'changedBy' => 'cli',
            'changed' => '2026-09-02T10:34:56Z',
        ], $raw);

        $loaded = DataSourceState::load($this->dsDir);
        $this->assertSame('read_only', $loaded->getState());
        $this->assertSame('migration', $loaded->getMaintenanceReason());
        $this->assertSame('2026-09-02T10:34:56Z', $loaded->getMaintenanceSince());
        $this->assertSame('suspended', $loaded->getEffectiveState());

        $reopened = $loaded->withoutMaintenance()->save($this->dsDir, 'cli');
        $this->assertFalse($reopened->isMaintenanceActive());
        $this->assertSame('read_only', DataSourceState::load($this->dsDir)->getEffectiveState());
    }

    public function testWithMaintenanceKeepsOriginalSinceWhenReasonChanges(): void
    {
        $first = new \DateTimeImmutable('2026-09-01T10:00:00Z');
        $s = DataSourceState::active()->withMaintenance('import', $first);
        $s = $s->withMaintenance('manual', new \DateTimeImmutable('2026-09-02T10:00:00Z'));
        $this->assertSame('manual', $s->getMaintenanceReason());
        $this->assertSame('2026-09-01T10:00:00Z', $s->getMaintenanceSince());
    }

    public function testDeleteAfterDroppedWhenLeavingPendingDeletion(): void
    {
        $s = DataSourceState::active()
            ->withState('pending_deletion')
            ->withDeleteAfter(new \DateTimeImmutable('2026-10-01T00:00:00Z'));
        $this->assertSame('2026-10-01T00:00:00Z', $s->getDeleteAfter());
        $this->assertNull($s->withState('active')->getDeleteAfter());
    }

    public function testInvalidStateAndReasonAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DataSourceState::active()->withState('frozen');
    }

    public function testInvalidReasonIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DataSourceState::active()->withMaintenance('vacation');
    }

    public function testSaveFailsLoudlyWhenConfigDirMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        DataSourceState::active()->save($this->dsDir . '/nonexistent', 'cli');
    }

    public function testSaveRefusesCorruptedState(): void
    {
        $this->writeState('{broken');
        $s = DataSourceState::load($this->dsDir);
        $this->expectException(\LogicException::class);
        $s->save($this->dsDir, 'cli');
    }

    // ── Přepínač periodických úloh (#115) ──────────────────────────────────

    public function testCronKeyMissingMeansEnabled(): void
    {
        $this->writeState(json_encode(['version' => 1, 'state' => 'read_only']));
        $s = DataSourceState::load($this->dsDir);
        $this->assertTrue($s->isCronEnabled());
        $this->assertArrayNotHasKey('cron', $s->toArray());
    }

    public function testCronFalseDisablesSwitchWithoutTouchingState(): void
    {
        $this->writeState(json_encode(['version' => 1, 'state' => 'active', 'cron' => false]));
        $s = DataSourceState::load($this->dsDir);
        $this->assertFalse($s->isCronEnabled());
        $this->assertSame('active', $s->getEffectiveState());
        $this->assertFalse($s->blocksHttp());
        $this->assertFalse($s->isCorrupted());
        $this->assertSame(false, $s->toArray()['cron']);
    }

    public function testCronTrueIsExplicitEnabledAndNotWrittenBack(): void
    {
        $this->writeState(json_encode(['version' => 1, 'state' => 'active', 'cron' => true]));
        $s = DataSourceState::load($this->dsDir);
        $this->assertTrue($s->isCronEnabled());
        $this->assertArrayNotHasKey('cron', $s->toArray());
    }

    public function testCronNonBooleanFailsClosedWithReason(): void
    {
        $this->writeState(json_encode(['version' => 1, 'state' => 'active', 'cron' => 'off']));
        $s = DataSourceState::load($this->dsDir);
        $this->assertTrue($s->isCorrupted());
        $this->assertSame('suspended', $s->getEffectiveState());
        $this->assertStringContainsString('cron must be a boolean', $this->logContents());
    }

    public function testMutatorsPreserveCronSwitch(): void
    {
        $off = DataSourceState::active()->withCron(false);
        $this->assertFalse($off->isCronEnabled());
        $this->assertFalse($off->withState('read_only')->isCronEnabled());
        $this->assertFalse($off->withMaintenance('import')->isCronEnabled());
        $this->assertFalse($off->withMaintenance('import')->withoutMaintenance()->isCronEnabled());
        $this->assertFalse($off->withState('pending_deletion')->withDeleteAfter(new \DateTimeImmutable('2026-10-01T00:00:00Z'))->isCronEnabled());
        $this->assertTrue($off->withCron(true)->isCronEnabled());
        // Mutátory nemění nic jiného.
        $this->assertSame('active', $off->getState());
        $this->assertFalse($off->isMaintenanceActive());
    }

    public function testSaveRoundTripKeepsCronOffAndWritesKeyOnlyWhenOff(): void
    {
        $now = new \DateTimeImmutable('2026-10-12T10:00:00Z');
        DataSourceState::active()->withCron(false)->withMaintenance('import', $now)->save($this->dsDir, 'cli', $now);

        $raw = json_decode((string) file_get_contents(DataSourceState::filePath($this->dsDir)), true);
        $this->assertSame([
            'version' => 1,
            'state' => 'active',
            'maintenance' => ['reason' => 'import', 'since' => '2026-10-12T10:00:00Z'],
            'cron' => false,
            'changedBy' => 'cli',
            'changed' => '2026-10-12T10:00:00Z',
        ], $raw);

        $loaded = DataSourceState::load($this->dsDir);
        $this->assertFalse($loaded->isCronEnabled());

        // maintenance --off přes save přepínač nezapne
        $reopened = $loaded->withoutMaintenance()->save($this->dsDir, 'cli', $now);
        $this->assertFalse($reopened->isCronEnabled());
        $this->assertFalse(DataSourceState::load($this->dsDir)->isCronEnabled());

        // zapnutí klíč odstraní
        $reopened->withCron(true)->save($this->dsDir, 'cli', $now);
        $raw = json_decode((string) file_get_contents(DataSourceState::filePath($this->dsDir)), true);
        $this->assertArrayNotHasKey('cron', $raw);
        $this->assertTrue(DataSourceState::load($this->dsDir)->isCronEnabled());
    }

    // ── Vlastník souboru při zápisu (tasks/ds-state-file-ownership.md) ─────

    private function writeMainJson(): string
    {
        $main = $this->dsDir . '/config/main.json';
        file_put_contents($main, '{}');
        chmod($main, 0600);
        return $main;
    }

    public function testSaveLeavesOwnerAloneWhenItMatchesMainJson(): void
    {
        $main = $this->writeMainJson();
        $ownership = new RecordingFileOwnership();

        DataSourceState::active()->withCron(false)->save($this->dsDir, 'cli', null, $ownership);

        $path = DataSourceState::filePath($this->dsDir);
        $this->assertSame([], $ownership->calls);
        $this->assertSame([$main, $path . '.tmp'], $ownership->lookups);
        $this->assertFileExists($path);
        $this->assertTrue(is_readable($path));
        $this->assertSame(0640, fileperms($path) & 0777);
        $this->assertFalse(DataSourceState::load($this->dsDir)->isCronEnabled());
    }

    public function testSaveAppliesOwnerOfMainJsonToTemporaryFileBeforeRename(): void
    {
        // Běh pod rootem: tmp vzniká jako root:root, main.json patří zdroji dat.
        $main = $this->writeMainJson();
        $ownership = new RecordingFileOwnership();
        $ownership->owners[$main] = ['uid' => 12345, 'gid' => 54321];

        DataSourceState::active()->save($this->dsDir, 'cli', null, $ownership);

        $tmp = DataSourceState::filePath($this->dsDir) . '.tmp';
        $this->assertSame([
            ['chown', $tmp, 12345],
            ['chgrp', $tmp, 54321],
        ], $ownership->calls);
        $this->assertFileDoesNotExist($tmp);
        $this->assertSame('active', DataSourceState::load($this->dsDir)->getState());
    }

    public function testSaveFailsLoudlyAndKeepsOriginalWhenOwnerCannotBeSet(): void
    {
        $main = $this->writeMainJson();
        $this->writeState(json_encode(['version' => 1, 'state' => 'read_only']));
        $original = (string) file_get_contents(DataSourceState::filePath($this->dsDir));

        $ownership = new RecordingFileOwnership();
        $ownership->owners[$main] = ['uid' => 12345, 'gid' => 54321];
        $ownership->chownResult = false;

        try {
            DataSourceState::load($this->dsDir)->withCron(false)->save($this->dsDir, 'cli', null, $ownership);
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refusing to install', $e->getMessage());
            $this->assertStringContainsString('Fix: chown ', $e->getMessage());
        }

        $path = DataSourceState::filePath($this->dsDir);
        $this->assertFileDoesNotExist($path . '.tmp');
        $this->assertSame($original, file_get_contents($path));
        $this->assertSame('read_only', DataSourceState::load($this->dsDir)->getState());
        $this->assertTrue(DataSourceState::load($this->dsDir)->isCronEnabled());
    }

    public function testSaveUsesConfigDirAsOwnerReferenceWithoutMainJson(): void
    {
        // setUp zakládá jen config/ — atrapa zdroje dat bez main.json.
        $configDir = $this->dsDir . '/config';
        $ownership = new RecordingFileOwnership();
        $ownership->owners[$configDir] = ['uid' => 12345, 'gid' => (int) filegroup($configDir)];

        DataSourceState::active()->save($this->dsDir, 'cli', null, $ownership);

        $tmp = DataSourceState::filePath($this->dsDir) . '.tmp';
        $this->assertSame($configDir, $ownership->lookups[0]);
        $this->assertSame([['chown', $tmp, 12345]], $ownership->calls);
        $this->assertFileExists(DataSourceState::filePath($this->dsDir));
    }
}
