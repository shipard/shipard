<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\DevAiKeyStore;
use Shipard\Core\Ai\Exception\DevAiKeyException;

/**
 * Serverový klíč pro vývoj (`<configDir>/secrets/dev-ai-<platforma>.key`,
 * tasks/testing-phase4.md D40): zápis 0600 v adresáři 0700, rotace, čtení
 * s kontrolou práv, chybějící / prázdný / volný soubor.
 */
final class DevAiKeyStoreTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        $this->configDir = sys_get_temp_dir() . '/shpd_devaikey_' . bin2hex(random_bytes(4));
        mkdir($this->configDir, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->configDir . '/secrets/*') ?: [] as $f) {
            @chmod($f, 0600);
            @unlink($f);
        }
        @rmdir($this->configDir . '/secrets');
        @rmdir($this->configDir);
    }

    public function testWriteCreatesTheKeyWithStrictPermissionsAndReadReturnsIt(): void
    {
        $warnings = DevAiKeyStore::write($this->configDir, 'anthropic', " sk-ant-dev \n", $this->configDir);

        $this->assertSame([], $warnings);
        $file = DevAiKeyStore::keyFilePath($this->configDir, 'anthropic');
        $this->assertSame($this->configDir . '/secrets/dev-ai-anthropic.key', $file);
        $this->assertFileExists($file);
        $this->assertSame(0600, fileperms($file) & 0777);
        $this->assertSame(0700, fileperms($this->configDir . '/secrets') & 0777);
        $this->assertTrue(DevAiKeyStore::exists($this->configDir, 'anthropic'));
        $this->assertFalse(DevAiKeyStore::exists($this->configDir, 'bedrock-eu'));
        $this->assertSame('sk-ant-dev', DevAiKeyStore::read($this->configDir, 'anthropic'));
        $this->assertFileDoesNotExist($file . '.tmp');
    }

    public function testWriteOverwritesTheKeyAndRejectsAnEmptyOne(): void
    {
        DevAiKeyStore::write($this->configDir, 'anthropic', 'old', $this->configDir);
        DevAiKeyStore::write($this->configDir, 'anthropic', 'new', $this->configDir);
        $this->assertSame('new', DevAiKeyStore::read($this->configDir, 'anthropic'));

        $this->expectException(\InvalidArgumentException::class);
        DevAiKeyStore::write($this->configDir, 'anthropic', "  \n", $this->configDir);
    }

    public function testReadReportsMissingInsecureAndEmptyKeys(): void
    {
        try {
            DevAiKeyStore::read($this->configDir, 'anthropic');
            $this->fail();
        } catch (DevAiKeyException $e) {
            $this->assertSame(DevAiKeyException::MISSING, $e->kind);
            $this->assertStringContainsString('dev-ai-key --set-key', $e->getMessage());
        }

        DevAiKeyStore::write($this->configDir, 'anthropic', 'sk', $this->configDir);
        $file = DevAiKeyStore::keyFilePath($this->configDir, 'anthropic');
        chmod($file, 0644);
        try {
            DevAiKeyStore::read($this->configDir, 'anthropic');
            $this->fail();
        } catch (DevAiKeyException $e) {
            $this->assertSame(DevAiKeyException::INSECURE, $e->kind);
            $this->assertStringContainsString('0644', $e->getMessage());
            $this->assertStringContainsString('chmod 0600', $e->getMessage());
        }

        chmod($file, 0600);
        file_put_contents($file, "\n");
        try {
            DevAiKeyStore::read($this->configDir, 'anthropic');
            $this->fail();
        } catch (DevAiKeyException $e) {
            $this->assertSame(DevAiKeyException::UNREADABLE, $e->kind);
            $this->assertStringContainsString('empty', $e->getMessage());
        }
    }
}
