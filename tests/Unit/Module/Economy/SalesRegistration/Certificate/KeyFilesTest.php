<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Certificate;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\SalesRegistration\Certificate\KeyFiles;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\TempDataSource;

/**
 * Dočasné soubory klíče (#171 U1): privátní adresář zdroje dat `tmp/`
 * (0700), soubory 0600, smazání ve `finally` — i po výjimce.
 */
class KeyFilesTest extends TestCase
{
    use TempDataSource;

    private int $umask;

    protected function setUp(): void
    {
        $this->umask = umask();
        $this->createDataSourceDir();
    }

    protected function tearDown(): void
    {
        umask($this->umask);
        $this->removeDataSourceDir();
    }

    private function files(): KeyFiles
    {
        return KeyFiles::forConfig($this->dataSourceConfig());
    }

    /** @return list<string> */
    private function leftovers(): array
    {
        return array_values(array_diff(scandir($this->dataSourceDir . '/tmp') ?: [], ['.', '..']));
    }

    public function testFilesLiveInThePrivateDirectoryOfTheDataSourceOnlyDuringTheCallback(): void
    {
        umask(0000); // ani povolný umask nesmí práva rozvolnit
        $seen = [];

        $result = $this->files()->with('KEY-PEM', 'CERT-PEM', function (string $keyPath, string $certPath) use (&$seen): string {
            $seen = [
                'keyDir'   => dirname($keyPath),
                'certDir'  => dirname($certPath),
                'key'      => file_get_contents($keyPath),
                'cert'     => file_get_contents($certPath),
                'keyMode'  => fileperms($keyPath) & 0777,
                'certMode' => fileperms($certPath) & 0777,
                'dirMode'  => fileperms(dirname($keyPath)) & 0777,
                'paths'    => [$keyPath, $certPath],
            ];
            return 'result of the callback';
        });

        $this->assertSame('result of the callback', $result);
        $this->assertSame($this->dataSourceDir . '/tmp', $seen['keyDir']);
        $this->assertSame($seen['keyDir'], $seen['certDir']);
        $this->assertStringStartsNotWith(sys_get_temp_dir() . '/' . KeyFiles::PREFIX, $seen['paths'][0], 'nikdy přímo systémový /tmp');
        $this->assertSame('KEY-PEM', $seen['key']);
        $this->assertSame('CERT-PEM', $seen['cert']);
        $this->assertSame(0600, $seen['keyMode']);
        $this->assertSame(0600, $seen['certMode']);
        $this->assertSame(0700, $seen['dirMode']);

        foreach ($seen['paths'] as $path) {
            $this->assertFileDoesNotExist($path);
        }
        $this->assertSame([], $this->leftovers());
        $this->assertSame(0000, umask(), 'umask procesu zůstává, jak byl');
    }

    public function testFilesAreRemovedEvenWhenTheCallbackThrows(): void
    {
        $paths = [];
        try {
            $this->files()->with('KEY', 'CERT', function (string $keyPath, string $certPath) use (&$paths): never {
                $paths = [$keyPath, $certPath];
                throw new \DomainException('sending failed');
            });
            $this->fail('výjimka se má propsat ven');
        } catch (\DomainException $e) {
            $this->assertSame('sending failed', $e->getMessage());
        }

        $this->assertCount(2, $paths);
        foreach ($paths as $path) {
            $this->assertFileDoesNotExist($path);
        }
        $this->assertSame([], $this->leftovers());
    }

    public function testEveryCallUsesNewFileNames(): void
    {
        $names = [];
        for ($i = 0; $i < 3; $i++) {
            $this->files()->with('K', 'C', function (string $keyPath) use (&$names): void {
                $names[] = basename($keyPath);
            });
        }

        $this->assertCount(3, array_unique($names));
        foreach ($names as $name) {
            $this->assertMatchesRegularExpression('/^salesreg-[0-9a-f]{24}\.key$/', $name);
        }
    }

    public function testStaleFilesOfAKilledProcessAreSweptButOthersAreKept(): void
    {
        $dir = $this->dataSourceDir . '/tmp';
        mkdir($dir, 0700);
        $stale = $dir . '/salesreg-dead.key';
        $fresh = $dir . '/salesreg-fresh.key';
        $foreign = $dir . '/something-else.txt';
        foreach ([$stale, $fresh, $foreign] as $file) {
            file_put_contents($file, 'x');
        }
        touch($stale, time() - KeyFiles::STALE_AFTER_SECONDS - 60);
        touch($foreign, time() - 86400);

        $this->files()->with('K', 'C', static fn(): bool => true);

        $this->assertFileDoesNotExist($stale, 'starý vlastní soubor po tvrdém pádu procesu');
        $this->assertFileExists($fresh, 'souběžné odeslání se nesmí rozbít');
        $this->assertFileExists($foreign, 'cizí soubory úklid nemaže');
    }

    public function testRefusesADirectoryOpenToGroupOrOthers(): void
    {
        mkdir($this->dataSourceDir . '/tmp', 0750);
        chmod($this->dataSourceDir . '/tmp', 0750);
        $called = false;

        try {
            $this->files()->with('K', 'C', function () use (&$called): void {
                $called = true;
            });
            $this->fail('adresář čitelný skupinou se nesmí použít');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('fix-permissions', $e->getMessage());
        }
        $this->assertFalse($called, 'klíč se do nechráněného adresáře nezapíše');
        $this->assertSame([], $this->leftovers());
    }

    public function testFailsWhenTheDataSourceDirectoryIsNotWritable(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root zapíše i do adresáře bez práv');
        }
        chmod($this->dataSourceDir, 0500);

        try {
            $this->expectException(\RuntimeException::class);
            $this->files()->with('K', 'C', static fn(): bool => true);
        } finally {
            chmod($this->dataSourceDir, 0750);
        }
    }
}
