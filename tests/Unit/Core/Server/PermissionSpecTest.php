<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Server\PermissionSpec;

class PermissionSpecTest extends TestCase
{
    private string $tempRoot;
    private int $umask;

    protected function setUp(): void
    {
        $this->umask = umask();
        $this->tempRoot = sys_get_temp_dir() . '/shpd-permspec-test-' . uniqid();
        mkdir($this->tempRoot, 0755, true);
        chmod($this->tempRoot, 0755);
    }

    protected function tearDown(): void
    {
        umask($this->umask);
        $this->recursiveDelete($this->tempRoot);
    }

    private function recursiveDelete(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->recursiveDelete($path . '/' . $entry);
        }
        @rmdir($path);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function umasks(): array
    {
        return [
            'no umask'      => [0000],
            'default umask' => [0022],
            'strict umask'  => [0077],
        ];
    }

    #[DataProvider('umasks')]
    public function testEnsureDsDirCreatesEveryLevelWithSpecMode(int $umask): void
    {
        umask($umask);

        $this->assertTrue(PermissionSpec::ensureDsDir($this->tempRoot . '/att/2026/10/03'));

        clearstatcache();
        foreach (['/att', '/att/2026', '/att/2026/10', '/att/2026/10/03'] as $level) {
            $this->assertSame(0750, fileperms($this->tempRoot . $level) & 0777, "mode of '{$level}'");
        }
    }

    public function testEnsureDsDirLeavesExistingDirectoriesUntouched(): void
    {
        // DS created by an older version — repairing it is up to fix-permissions.
        mkdir($this->tempRoot . '/config');
        chmod($this->tempRoot . '/config', 0755);

        $this->assertTrue(PermissionSpec::ensureDsDir($this->tempRoot . '/config'));
        $this->assertTrue(PermissionSpec::ensureDsDir($this->tempRoot . '/config/configuration'));

        clearstatcache();
        $this->assertSame(0755, fileperms($this->tempRoot) & 0777);
        $this->assertSame(0755, fileperms($this->tempRoot . '/config') & 0777);
        $this->assertSame(0750, fileperms($this->tempRoot . '/config/configuration') & 0777);
    }

    public function testEnsureDsDirFailsWhenFileIsInTheWay(): void
    {
        file_put_contents($this->tempRoot . '/att', '');

        $this->assertFalse(PermissionSpec::ensureDsDir($this->tempRoot . '/att'));
        $this->assertFalse(PermissionSpec::ensureDsDir($this->tempRoot . '/att/2026'));
    }
}
