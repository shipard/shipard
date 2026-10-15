<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Server\MainConfigFile;
use Shipard\Core\Server\MainConfigPatcher;

/** Read-modify-write `config/main.json`: atomicky, 0600, chyby čtení a JSON, žádný tmp soubor. */
final class MainConfigFileTest extends TestCase
{
    private string $dsDir;

    protected function setUp(): void
    {
        $this->dsDir = sys_get_temp_dir() . '/shpd_mainconfig_' . bin2hex(random_bytes(4));
        mkdir($this->dsDir . '/config', 0750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dsDir . '/config/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dsDir . '/config');
        @rmdir($this->dsDir);
    }

    public function testPatchRewritesTheFileAtomically(): void
    {
        $file = MainConfigFile::path($this->dsDir);
        file_put_contents($file, json_encode(['id' => 'x', 'name' => 'Čeština']));

        MainConfigFile::patch($this->dsDir, static fn (array $c): array => MainConfigPatcher::withServerAi($c, true));

        $config = json_decode((string) file_get_contents($file), true);
        $this->assertSame(['id' => 'x', 'name' => 'Čeština', 'ai' => ['useServerConnection' => true]], $config);
        $this->assertSame(0600, fileperms($file) & 0777);
        $this->assertStringContainsString("\"name\": \"Čeština\"", (string) file_get_contents($file), 'UTF-8 bez escapování');
        $this->assertFileDoesNotExist($file . '.tmp');
    }

    public function testMissingOrInvalidFileIsAnError(): void
    {
        try {
            MainConfigFile::patch($this->dsDir, static fn (array $c): array => $c);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot read', $e->getMessage());
        }

        file_put_contents(MainConfigFile::path($this->dsDir), '{not json');
        try {
            MainConfigFile::patch($this->dsDir, static fn (array $c): array => $c);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('invalid JSON', $e->getMessage());
        }
        $this->assertSame('{not json', file_get_contents(MainConfigFile::path($this->dsDir)), 'původní soubor zůstává');
    }
}
