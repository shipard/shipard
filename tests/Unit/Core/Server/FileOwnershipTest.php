<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Server\FileOwnership;
use Shipard\Tests\Fixtures\Core\Server\RecordingFileOwnership;

class FileOwnershipTest extends TestCase
{
    private string $dir;
    private string $reference;
    private string $target;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shpd-file-ownership-' . uniqid();
        mkdir($this->dir, 0750, true);
        $this->reference = $this->dir . '/main.json';
        $this->target = $this->dir . '/state.json.tmp';
        file_put_contents($this->reference, '{}');
        file_put_contents($this->target, '{}');
    }

    protected function tearDown(): void
    {
        @unlink($this->reference);
        @unlink($this->target);
        @unlink($this->dir . '/ds/config/main.json');
        @rmdir($this->dir . '/ds/config');
        @rmdir($this->dir . '/ds');
        @rmdir($this->dir);
    }

    public function testDataSourceReferencePrefersMainJsonThenConfigDirThenDsDir(): void
    {
        $ds = $this->dir . '/ds';
        mkdir($ds);
        $this->assertSame($ds, FileOwnership::dataSourceReference($ds));
        $this->assertSame($ds, FileOwnership::dataSourceReference($ds . '/'), 'koncové lomítko se nepropisuje');

        mkdir($ds . '/config');
        $this->assertSame($ds . '/config', FileOwnership::dataSourceReference($ds));

        file_put_contents($ds . '/config/main.json', '{}');
        $this->assertSame($ds . '/config/main.json', FileOwnership::dataSourceReference($ds));
    }

    public function testSameOwnerMakesNoCall(): void
    {
        // Oba soubory založil testovací uživatel — běžný běh pod shipard-userem.
        $ownership = new RecordingFileOwnership();
        $ownership->matchTo($this->target, $this->reference);

        $this->assertSame([], $ownership->calls);
        $this->assertSame([$this->reference, $this->target], $ownership->lookups);
    }

    public function testRealImplementationIsNoOpForSameOwner(): void
    {
        (new FileOwnership())->matchTo($this->target, $this->reference);

        $this->assertSame(fileowner($this->reference), fileowner($this->target));
        $this->assertSame(filegroup($this->reference), filegroup($this->target));
    }

    public function testDifferentOwnerAndGroupAreAppliedFromReference(): void
    {
        $ownership = new RecordingFileOwnership();
        $ownership->owners[$this->reference] = ['uid' => 12345, 'gid' => 54321];

        $ownership->matchTo($this->target, $this->reference);

        $this->assertSame([
            ['chown', $this->target, 12345],
            ['chgrp', $this->target, 54321],
        ], $ownership->calls);
    }

    public function testOnlyGroupDifferenceCallsChgrpAlone(): void
    {
        $ownership = new RecordingFileOwnership();
        $ownership->owners[$this->reference] = ['uid' => (int) fileowner($this->target), 'gid' => 54321];

        $ownership->matchTo($this->target, $this->reference);

        $this->assertSame([['chgrp', $this->target, 54321]], $ownership->calls);
    }

    public function testChownFailureThrowsWithFixHint(): void
    {
        $ownership = new RecordingFileOwnership();
        $ownership->owners[$this->reference] = ['uid' => 12345, 'gid' => 54321];
        $ownership->chownResult = false;

        try {
            $ownership->matchTo($this->target, $this->reference);
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failed to set owner of ' . $this->target, $e->getMessage());
            $this->assertStringContainsString('running as', $e->getMessage());
            $this->assertStringContainsString('Fix: chown ', $e->getMessage());
        }
        // Po selhání chown se chgrp už nezkouší.
        $this->assertSame([['chown', $this->target, 12345]], $ownership->calls);
    }

    public function testChgrpFailureThrows(): void
    {
        $ownership = new RecordingFileOwnership();
        $ownership->owners[$this->reference] = ['uid' => (int) fileowner($this->target), 'gid' => 54321];
        $ownership->chgrpResult = false;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to set group of ' . $this->target);
        $ownership->matchTo($this->target, $this->reference);
    }

    public function testMissingReferenceThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot determine owner of ' . $this->dir . '/missing.json');
        (new FileOwnership())->matchTo($this->target, $this->dir . '/missing.json');
    }

    public function testMissingTargetThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot determine owner of ' . $this->dir . '/missing.tmp');
        (new FileOwnership())->matchTo($this->dir . '/missing.tmp', $this->reference);
    }

    public function testOwnerSpecNamesKnownIdsAndFallsBackToNumbers(): void
    {
        $uid = posix_getuid();
        $gid = posix_getgid();
        $expected = posix_getpwuid($uid)['name'] . ':' . posix_getgrgid($gid)['name'];
        $this->assertSame($expected, FileOwnership::ownerSpec($uid, $gid));

        $this->assertSame('2147483000:2147483001', FileOwnership::ownerSpec(2147483000, 2147483001));
    }
}
