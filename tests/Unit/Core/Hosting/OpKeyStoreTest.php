<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Hosting;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Hosting\Exception\OpKeyInsecureException;
use Shipard\Core\Hosting\Exception\OpKeyMissingException;
use Shipard\Core\Hosting\OpKeyStore;

class OpKeyStoreTest extends TestCase
{
    /** Keygen RSA 3072 je pomalý — jeden klíč pro celou třídu. */
    private static string $dsDir;
    private static string $generatedKid;

    public static function setUpBeforeClass(): void
    {
        self::$dsDir = sys_get_temp_dir() . '/shpd-opkeystore-test-' . uniqid();
        mkdir(self::$dsDir . '/config', 0755, true);
        file_put_contents(self::$dsDir . '/config/main.json', json_encode([
            'id'            => 'abcd-efgh-ijkl-mnop',
            'name'          => 'Test DS',
            'database_name' => 'test_db',
            'database_user' => 'test_user',
            'created'       => '2026-01-01 00:00:00',
        ]));
        self::$generatedKid = OpKeyStore::generateKey(self::$dsDir)['kid'];
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(OpKeyStore::keyFilePath(self::$dsDir));
        @rmdir(self::$dsDir . '/secrets');
        @unlink(self::$dsDir . '/config/main.json');
        @rmdir(self::$dsDir . '/config');
        @rmdir(self::$dsDir);
    }

    protected function tearDown(): void
    {
        OpKeyStore::resetCache();
        @chmod(OpKeyStore::keyFilePath(self::$dsDir), 0600);
    }

    private function config(): DataSourceConfig
    {
        return new DataSourceConfig(self::$dsDir);
    }

    public function testGenerateKeyCreatesFileWithSecurePermissions(): void
    {
        $keyFile = OpKeyStore::keyFilePath(self::$dsDir);
        $this->assertFileExists($keyFile);
        $this->assertSame(0600, fileperms($keyFile) & 0777);
        $this->assertSame(0700, fileperms(self::$dsDir . '/secrets') & 0777);
        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', (string) file_get_contents($keyFile));
    }

    public function testGenerateKeyRefusesExistingKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already exists/');
        OpKeyStore::generateKey(self::$dsDir);
    }

    public function testKidIsStableAcrossLoads(): void
    {
        $first = OpKeyStore::forConfig($this->config())->kid();
        OpKeyStore::resetCache();
        $second = OpKeyStore::forConfig($this->config())->kid();

        $this->assertSame($first, $second);
        $this->assertSame(self::$generatedKid, $first);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $first);
    }

    public function testKidMatchesIndependentDerivation(): void
    {
        // kid = prefix sha256 otisku veřejného klíče (DER SPKI)
        $key = openssl_pkey_get_private((string) file_get_contents(OpKeyStore::keyFilePath(self::$dsDir)));
        $publicPem = openssl_pkey_get_details($key)['key'];
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $publicPem), true);
        $expected = substr(hash('sha256', (string) $der), 0, 16);

        $this->assertSame($expected, OpKeyStore::forConfig($this->config())->kid());
    }

    public function testPublicJwkShapeAndParseability(): void
    {
        $store = OpKeyStore::forConfig($this->config());
        $jwk = $store->publicJwk();

        $this->assertSame('RSA', $jwk['kty']);
        $this->assertSame('sig', $jwk['use']);
        $this->assertSame('RS256', $jwk['alg']);
        $this->assertSame($store->kid(), $jwk['kid']);
        // n/e jsou base64url bez paddingu
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $jwk['n']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $jwk['e']);

        $keys = JWK::parseKeySet(['keys' => [$jwk]], 'RS256');
        $this->assertArrayHasKey($store->kid(), $keys);
    }

    public function testSignRoundTripWithKidHeader(): void
    {
        $store = OpKeyStore::forConfig($this->config());
        $claims = [
            'iss'   => 'https://hosting.example.com/api/v1/_hosting/oidc',
            'sub'   => '42',
            'aud'   => 'abcd-efgh-ijkl-mnop',
            'exp'   => time() + 300,
            'iat'   => time(),
            'nonce' => 'test-nonce',
        ];

        $jwt = $store->sign($claims);

        $header = json_decode(
            (string) base64_decode(strtr(explode('.', $jwt)[0], '-_', '+/')),
            true,
        );
        $this->assertSame('RS256', $header['alg']);
        $this->assertSame($store->kid(), $header['kid']);

        $decoded = (array) JWT::decode($jwt, JWK::parseKeySet(['keys' => [$store->publicJwk()]], 'RS256'));
        $this->assertSame($claims['iss'], $decoded['iss']);
        $this->assertSame($claims['sub'], $decoded['sub']);
        $this->assertSame($claims['nonce'], $decoded['nonce']);
    }

    public function testMissingKeyThrowsWithInitHint(): void
    {
        $emptyDir = sys_get_temp_dir() . '/shpd-opkeystore-empty-' . uniqid();
        mkdir($emptyDir . '/config', 0755, true);
        file_put_contents($emptyDir . '/config/main.json', json_encode([
            'id'            => 'wxyz-wxyz-wxyz-wxyz',
            'name'          => 'Empty DS',
            'database_name' => 'db',
            'database_user' => 'u',
            'created'       => '2026-01-01 00:00:00',
        ]));

        try {
            $this->expectException(OpKeyMissingException::class);
            $this->expectExceptionMessageMatches('/hosting-oidc-init/');
            OpKeyStore::forConfig(new DataSourceConfig($emptyDir));
        } finally {
            @unlink($emptyDir . '/config/main.json');
            @rmdir($emptyDir . '/config');
            @rmdir($emptyDir);
        }
    }

    public function testInsecurePermissionsThrow(): void
    {
        chmod(OpKeyStore::keyFilePath(self::$dsDir), 0644);

        $this->expectException(OpKeyInsecureException::class);
        $this->expectExceptionMessageMatches('/chmod 0600/');
        OpKeyStore::forConfig($this->config());
    }
}
