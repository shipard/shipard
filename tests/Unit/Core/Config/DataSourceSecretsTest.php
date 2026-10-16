<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\DataSourceSecrets;

/**
 * Soubory tajemství v `secrets/` (#126 D2, U2): názvy, líné čtení, koncový
 * řádek, prázdný / chybějící / nečitelný soubor. Hodnoty v testech jsou
 * zjevně falešné.
 */
final class DataSourceSecretsTest extends TestCase
{
    private string $dsDir;

    protected function setUp(): void
    {
        $this->dsDir = sys_get_temp_dir() . '/shpd-ds-secrets-' . bin2hex(random_bytes(4));
        mkdir($this->dsDir . '/secrets', 0700, true);
    }

    protected function tearDown(): void
    {
        @chmod($this->dsDir . '/secrets', 0700);
        foreach (glob($this->dsDir . '/secrets/*') ?: [] as $f) {
            @chmod($f, 0600);
            @unlink($f);
        }
        @rmdir($this->dsDir . '/secrets');
        @rmdir($this->dsDir);
    }

    private function writeSecret(string $name, string $raw): void
    {
        file_put_contents($this->dsDir . '/secrets/' . $name, $raw);
    }

    // ── názvy ───────────────────────────────────────────────────────────────

    public function testKnownNames(): void
    {
        $this->assertSame('db.password', DataSourceSecrets::DB_PASSWORD);
        $this->assertSame('mail-relay.password', DataSourceSecrets::MAIL_RELAY_PASSWORD);
        $this->assertSame('oidc-shipard-id.client-secret', DataSourceSecrets::oidcClientSecret('shipard-id'));
        $this->assertSame('oidc-github.client-secret', DataSourceSecrets::oidcClientSecret('github'));
    }

    #[DataProvider('invalidProviderIds')]
    public function testOidcClientSecretRejectsInvalidProviderId(string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DataSourceSecrets::oidcClientSecret($id);
    }

    /** @return array<string, array{string}> */
    public static function invalidProviderIds(): array
    {
        return [
            'prázdné'        => [''],
            'velká písmena'  => ['Entra'],
            'lomítko'        => ['../x'],
            'tečka'          => ['a.b'],
            'mezera'         => ['a b'],
        ];
    }

    public function testIsValidNameAndProviderIdFromName(): void
    {
        $this->assertTrue(DataSourceSecrets::isValidName('db.password'));
        $this->assertTrue(DataSourceSecrets::isValidName('mail-relay.password'));
        $this->assertTrue(DataSourceSecrets::isValidName('oidc-shipard-id.client-secret'));
        $this->assertSame('shipard-id', DataSourceSecrets::oidcProviderIdFromName('oidc-shipard-id.client-secret'));

        $this->assertFalse(DataSourceSecrets::isValidName('secrets.key'));
        $this->assertFalse(DataSourceSecrets::isValidName('oidc-op.key'));
        $this->assertFalse(DataSourceSecrets::isValidName('oidc-.client-secret'));
        $this->assertFalse(DataSourceSecrets::isValidName('oidc-Entra.client-secret'));
        $this->assertFalse(DataSourceSecrets::isValidName('../db.password'));
        $this->assertNull(DataSourceSecrets::oidcProviderIdFromName('db.password'));
    }

    // ── get ─────────────────────────────────────────────────────────────────

    public function testGetMissingFileIsNull(): void
    {
        $secrets = new DataSourceSecrets($this->dsDir);
        $this->assertNull($secrets->get('db.password'));
        $this->assertFalse($secrets->has('db.password'));
    }

    public function testGetWithoutSecretsDirectoryIsNull(): void
    {
        rmdir($this->dsDir . '/secrets');
        $secrets = new DataSourceSecrets($this->dsDir);
        $this->assertNull($secrets->get('db.password'));
        $this->assertFalse($secrets->has('db.password'));
    }

    #[DataProvider('rawValues')]
    public function testGetStripsExactlyOneTrailingNewline(string $raw, ?string $expected): void
    {
        $this->writeSecret('db.password', $raw);
        $secrets = new DataSourceSecrets($this->dsDir);
        $this->assertSame($expected, $secrets->get('db.password'));
    }

    /** @return array<string, array{string, ?string}> */
    public static function rawValues(): array
    {
        return [
            'holá hodnota'            => ['fake-pw-1', 'fake-pw-1'],
            'jeden \n'                => ["fake-pw-1\n", 'fake-pw-1'],
            'jeden \r\n'              => ["fake-pw-1\r\n", 'fake-pw-1'],
            'dva \n = jeden zůstane'  => ["fake-pw-1\n\n", "fake-pw-1\n"],
            'prázdný soubor'          => ['', null],
            'jen \n = chybí'          => ["\n", null],
            'mezery se netrimují'     => ['  spaced  ', '  spaced  '],
            'samostatné \r zůstává'   => ["fake\r", "fake\r"],
        ];
    }

    public function testHasIsTrueOnlyForNonEmptyFile(): void
    {
        $this->writeSecret('db.password', '');
        $secrets = new DataSourceSecrets($this->dsDir);
        $this->assertFalse($secrets->has('db.password'));

        $this->writeSecret('mail-relay.password', 'x');
        $this->assertTrue($secrets->has('mail-relay.password'));
    }

    public function testGetIsCachedPerName(): void
    {
        $this->writeSecret('db.password', 'first');
        $secrets = new DataSourceSecrets($this->dsDir);
        $this->assertSame('first', $secrets->get('db.password'));

        $this->writeSecret('db.password', 'second');
        $this->assertSame('first', $secrets->get('db.password'), 'hodnota se čte jednou za instanci');
        $this->assertSame('second', (new DataSourceSecrets($this->dsDir))->get('db.password'));
    }

    public function testUnreadableFileThrowsWithPathAndWithoutContent(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root čte i soubor s právy 0000');
        }
        $this->writeSecret('db.password', 'never-shown');
        chmod($this->dsDir . '/secrets/db.password', 0000);

        $secrets = new DataSourceSecrets($this->dsDir);
        try {
            $secrets->get('db.password');
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($this->dsDir . '/secrets/db.password', $e->getMessage());
            $this->assertStringNotContainsString('never-shown', $e->getMessage());
        }
        // has() obsah nečte — existence a velikost stačí.
        $this->assertTrue($secrets->has('db.password'));
    }
}
