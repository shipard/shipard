<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Security;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Security\Exception\InvalidCiphertextException;
use Shipard\Core\Security\Exception\SecretsKeyInsecureException;
use Shipard\Core\Security\Exception\SecretsKeyMissingException;

class DsSecretCipherTest extends TestCase
{
    /**
     * Vektor vygenerovaný původní implementací přes libsodium
     * (klíč 32× 0x01, nonce 12× 0x02) — hlídá kompatibilitu uložených dat.
     */
    private const VECTOR_PLAINTEXT = 'shipard-test-vector-ěščř';
    private const VECTOR_CIPHERTEXT = 'v1:AgICAgICAgICAgIC:5p4KFImYykTbVLyMGqemWA==:dL6gOSslpdCnqc+8cdGn0dbao8IvKkjQGQolYg==';

    private string $tmpDir;

    protected function setUp(): void
    {
        DsSecretCipher::resetCache();
        $this->tmpDir = sys_get_temp_dir() . '/shpd-cipher-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir . '/config', 0700, true);
        mkdir($this->tmpDir . '/secrets', 0700, true);

        file_put_contents(
            $this->tmpDir . '/config/main.json',
            json_encode([
                'id' => 'test-test-test-test',
                'name' => 'Test',
                'database_name' => 'test_test_test_test',
                'database_user' => 'shpd_testtest',
                'database_password' => 'pw',
                'created' => date('c'),
            ]),
        );

        $this->writeKey(random_bytes(DsSecretCipher::KEY_BYTES));
    }

    protected function tearDown(): void
    {
        DsSecretCipher::resetCache();
        $this->rrmdir($this->tmpDir);
    }

    private function writeKey(string $bytes): void
    {
        $keyFile = $this->tmpDir . '/secrets/secrets.key';
        file_put_contents($keyFile, $bytes);
        chmod($keyFile, 0600);
        chmod($this->tmpDir . '/secrets', 0700);
    }

    private function makeConfig(): DataSourceConfig
    {
        return new DataSourceConfig($this->tmpDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @chmod($path, 0600);
                @unlink($path);
            }
        }
        @chmod($dir, 0700);
        @rmdir($dir);
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $cipher = DsSecretCipher::forConfig($this->makeConfig());
        $plain = 'sk-secret-api-key-12345';

        $ct = $cipher->encrypt($plain);
        $this->assertStringStartsWith('v1:', $ct);
        $this->assertSame($plain, $cipher->decrypt($ct));
    }

    public function testEachEncryptGeneratesFreshNonce(): void
    {
        $cipher = DsSecretCipher::forConfig($this->makeConfig());
        $plain = 'same input';

        $a = $cipher->encrypt($plain);
        $b = $cipher->encrypt($plain);

        $this->assertNotSame($a, $b, 'two encryptions of the same plaintext must differ');
        $this->assertSame($plain, $cipher->decrypt($a));
        $this->assertSame($plain, $cipher->decrypt($b));
    }

    public function testTamperDetectionInCiphertextBody(): void
    {
        $cipher = DsSecretCipher::forConfig($this->makeConfig());
        $ct = $cipher->encrypt('hello world');

        $parts = explode(':', $ct, 4);
        $body = base64_decode($parts[3], true);
        $body[0] = chr(ord($body[0]) ^ 0x01);
        $parts[3] = base64_encode($body);
        $tampered = implode(':', $parts);

        $this->expectException(InvalidCiphertextException::class);
        $cipher->decrypt($tampered);
    }

    public function testTamperDetectionInAuthTag(): void
    {
        $cipher = DsSecretCipher::forConfig($this->makeConfig());
        $ct = $cipher->encrypt('hello world');

        $parts = explode(':', $ct, 4);
        $tag = base64_decode($parts[2], true);
        $tag[0] = chr(ord($tag[0]) ^ 0x01);
        $parts[2] = base64_encode($tag);
        $tampered = implode(':', $parts);

        $this->expectException(InvalidCiphertextException::class);
        $cipher->decrypt($tampered);
    }

    public function testWrongKeyFails(): void
    {
        $cipher = DsSecretCipher::forConfig($this->makeConfig());
        $ct = $cipher->encrypt('hello');

        DsSecretCipher::resetCache();
        $this->writeKey(random_bytes(DsSecretCipher::KEY_BYTES));
        $other = DsSecretCipher::forConfig($this->makeConfig());

        $this->expectException(InvalidCiphertextException::class);
        $other->decrypt($ct);
    }

    public function testMissingSecretsKey(): void
    {
        unlink($this->tmpDir . '/secrets/secrets.key');

        $this->expectException(SecretsKeyMissingException::class);
        DsSecretCipher::forConfig($this->makeConfig());
    }

    public function testInsecureFilePermissions(): void
    {
        chmod($this->tmpDir . '/secrets/secrets.key', 0644);

        $this->expectException(SecretsKeyInsecureException::class);
        DsSecretCipher::forConfig($this->makeConfig());
    }

    public function testInsecureGroupReadablePermissions(): void
    {
        chmod($this->tmpDir . '/secrets/secrets.key', 0640);

        $this->expectException(SecretsKeyInsecureException::class);
        DsSecretCipher::forConfig($this->makeConfig());
    }

    public function testParentDirWrongPermsIsWarningNotError(): void
    {
        chmod($this->tmpDir . '/secrets', 0755);

        $cipher = DsSecretCipher::forConfig($this->makeConfig());
        $this->assertSame('roundtrip', $cipher->decrypt($cipher->encrypt('roundtrip')));

        $warnings = DsSecretCipher::healthCheck($this->makeConfig());
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('secrets/ directory has permissions 0755', $warnings[0]);
    }

    public function testMalformedCiphertextMissingPrefix(): void
    {
        $cipher = DsSecretCipher::forConfig($this->makeConfig());

        $this->expectException(InvalidCiphertextException::class);
        $cipher->decrypt('not-a-valid-ciphertext');
    }

    public function testMalformedCiphertextWrongVersion(): void
    {
        $cipher = DsSecretCipher::forConfig($this->makeConfig());

        $this->expectException(InvalidCiphertextException::class);
        $cipher->decrypt('v2:aaaa:bbbb:cccc');
    }

    public function testMalformedCiphertextBadBase64(): void
    {
        $cipher = DsSecretCipher::forConfig($this->makeConfig());

        $this->expectException(InvalidCiphertextException::class);
        $cipher->decrypt('v1:!!!:!!!:!!!');
    }

    public function testMalformedCiphertextTruncatedTag(): void
    {
        $cipher = DsSecretCipher::forConfig($this->makeConfig());
        $ct = $cipher->encrypt('hello world');

        // OpenSSL would accept a shortened tag on its own; the length check must not.
        $parts = explode(':', $ct, 4);
        $parts[2] = base64_encode(substr(base64_decode($parts[2], true), 0, 4));

        $this->expectException(InvalidCiphertextException::class);
        $this->expectExceptionMessage('invalid tag length');
        $cipher->decrypt(implode(':', $parts));
    }

    public function testMalformedCiphertextWrongNonceLength(): void
    {
        $cipher = DsSecretCipher::forConfig($this->makeConfig());
        $ct = $cipher->encrypt('hello world');

        $parts = explode(':', $ct, 4);
        $parts[1] = base64_encode(substr(base64_decode($parts[1], true), 0, 8));

        $this->expectException(InvalidCiphertextException::class);
        $this->expectExceptionMessage('invalid nonce length');
        $cipher->decrypt(implode(':', $parts));
    }

    public function testEmptyPlaintext(): void
    {
        $cipher = DsSecretCipher::forConfig($this->makeConfig());
        $ct = $cipher->encrypt('');
        $this->assertSame('', $cipher->decrypt($ct));
    }

    public function testLargePlaintext(): void
    {
        $cipher = DsSecretCipher::forConfig($this->makeConfig());
        $plain = str_repeat('A', 1024 * 1024);
        $this->assertSame($plain, $cipher->decrypt($cipher->encrypt($plain)));
    }

    public function testUnicodePlaintext(): void
    {
        $cipher = DsSecretCipher::forConfig($this->makeConfig());
        $plain = "Příliš žluťoučký kůň 🐎 草津温泉 \u{1F511}";
        $this->assertSame($plain, $cipher->decrypt($cipher->encrypt($plain)));
    }

    public function testHealthCheckHappyPath(): void
    {
        $this->assertSame([], DsSecretCipher::healthCheck($this->makeConfig()));
    }

    public function testHealthCheckMissingKey(): void
    {
        unlink($this->tmpDir . '/secrets/secrets.key');
        $warnings = DsSecretCipher::healthCheck($this->makeConfig());
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('secrets.key missing', $warnings[0]);
    }

    public function testHealthCheckMissingDir(): void
    {
        unlink($this->tmpDir . '/secrets/secrets.key');
        rmdir($this->tmpDir . '/secrets');
        $warnings = DsSecretCipher::healthCheck($this->makeConfig());
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('secrets/ directory missing', $warnings[0]);
    }

    public function testHealthCheckBadFilePerms(): void
    {
        chmod($this->tmpDir . '/secrets/secrets.key', 0644);
        $warnings = DsSecretCipher::healthCheck($this->makeConfig());
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('secrets.key has permissions 0644', $warnings[0]);
    }

    public function testHealthCheckWrongKeySize(): void
    {
        file_put_contents($this->tmpDir . '/secrets/secrets.key', 'too short');
        chmod($this->tmpDir . '/secrets/secrets.key', 0600);
        $warnings = DsSecretCipher::healthCheck($this->makeConfig());
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('secrets.key has size', $warnings[0]);
    }

    public function testForConfigCachesInstancePerDsPath(): void
    {
        $a = DsSecretCipher::forConfig($this->makeConfig());
        $b = DsSecretCipher::forConfig($this->makeConfig());
        $this->assertSame($a, $b);
    }

    public function testFromKeyRequiresExactLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DsSecretCipher::fromKey('too short');
    }

    public function testFromKeyEncryptDecryptRoundTrip(): void
    {
        $key = random_bytes(DsSecretCipher::KEY_BYTES);
        $cipher = DsSecretCipher::fromKey($key);
        $this->assertSame('hello', $cipher->decrypt($cipher->encrypt('hello')));
    }

    public function testKeysFromDifferentInstancesAreIncompatible(): void
    {
        $a = DsSecretCipher::fromKey(random_bytes(DsSecretCipher::KEY_BYTES));
        $b = DsSecretCipher::fromKey(random_bytes(DsSecretCipher::KEY_BYTES));

        $ct = $a->encrypt('payload');
        $this->expectException(InvalidCiphertextException::class);
        $b->decrypt($ct);
    }

    public function testDecryptsCiphertextWrittenByLibsodiumImplementation(): void
    {
        $cipher = DsSecretCipher::fromKey(str_repeat("\x01", DsSecretCipher::KEY_BYTES));

        $this->assertSame(self::VECTOR_PLAINTEXT, $cipher->decrypt(self::VECTOR_CIPHERTEXT));
    }

    public function testEncryptReproducesLibsodiumVector(): void
    {
        $cipher = DsSecretCipher::fromKey(str_repeat("\x01", DsSecretCipher::KEY_BYTES));
        $encryptWithNonce = new \ReflectionMethod(DsSecretCipher::class, 'encryptWithNonce');

        $this->assertSame(
            self::VECTOR_CIPHERTEXT,
            $encryptWithNonce->invoke($cipher, self::VECTOR_PLAINTEXT, str_repeat("\x02", 12)),
        );
    }
}
