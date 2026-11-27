<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Devices;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Devices\DeviceKey;

/**
 * Klíč a podpis zařízení (#177 U4, kontrakt §7.1): podpis platí jen pro
 * svůj účel, zařízení a výzvu; klíč jiné křivky ani jiného typu server
 * nepřijme; podpis z WebCrypto (simulace mostu) PHP ověří.
 */
class DeviceKeyTest extends TestCase
{
    use DeviceKeys;

    private const string DEVICE = '0b9f6c1e-52a4-4f0e-9a43-6f2d1c7e8a10';
    private const string CHALLENGE = 'p3mX0uJ8m1nq2Yb7c5VtR9kLwZ4aQeH6sD0fGiNoPxE';

    public function testSigningStringFollowsTheContract(): void
    {
        $this->assertSame(
            'shipard-device-v1|self|' . self::DEVICE . '|' . self::CHALLENGE,
            DeviceKey::signingString('self', self::DEVICE, self::CHALLENGE),
        );
    }

    public function testSignatureOfTheDeviceKeyIsAccepted(): void
    {
        $pair = self::generateEcKey();
        $key = DeviceKey::fromSpkiBase64($pair['public']);

        $this->assertNotNull($key);
        $this->assertTrue($key->verify('self', self::DEVICE, self::CHALLENGE, self::sign($pair['private'], 'self', self::DEVICE, self::CHALLENGE)));
    }

    public function testSignatureIsBoundToPurposeDeviceAndChallenge(): void
    {
        $pair = self::generateEcKey();
        $key = DeviceKey::fromSpkiBase64($pair['public']);
        $signature = self::sign($pair['private'], 'self', self::DEVICE, self::CHALLENGE);

        $this->assertFalse($key->verify('peripherals', self::DEVICE, self::CHALLENGE, $signature), 'jiný účel');
        $this->assertFalse($key->verify('self', 'ffffffff-52a4-4f0e-9a43-6f2d1c7e8a10', self::CHALLENGE, $signature), 'jiné zařízení');
        $this->assertFalse($key->verify('self', self::DEVICE, strrev(self::CHALLENGE), $signature), 'jiná výzva');
    }

    public function testSignatureOfAnotherKeyIsRejected(): void
    {
        $device = self::generateEcKey();
        $other = self::generateEcKey();
        $key = DeviceKey::fromSpkiBase64($device['public']);

        $this->assertFalse($key->verify('self', self::DEVICE, self::CHALLENGE, self::sign($other['private'], 'self', self::DEVICE, self::CHALLENGE)));
    }

    public function testDamagedSignatureIsRejectedWithoutAnError(): void
    {
        $pair = self::generateEcKey();
        $key = DeviceKey::fromSpkiBase64($pair['public']);

        foreach (['', 'not base64 !!', base64_encode('garbage'), base64_encode(random_bytes(64)), str_repeat('A', 400)] as $signature) {
            $this->assertFalse($key->verify('self', self::DEVICE, self::CHALLENGE, $signature), $signature);
        }
        // Chyba OpenSSL nesmí zůstat ve frontě pro další volání.
        $this->assertFalse(openssl_error_string());
    }

    public function testOnlyP256KeysAreAccepted(): void
    {
        $this->assertNull(DeviceKey::fromSpkiBase64(self::generateEcKey('secp384r1')['public']), 'P-384');
        $this->assertNull(DeviceKey::fromSpkiBase64(self::generateEcKey('secp256k1')['public']), 'secp256k1');

        $rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->assertNull(DeviceKey::fromSpkiBase64(self::spkiBase64((string) openssl_pkey_get_details($rsa)['key'])), 'RSA');
    }

    public function testGarbageIsNotAKey(): void
    {
        foreach (['', 'not base64 !!', base64_encode('garbage'), str_repeat('A', 5000)] as $value) {
            $this->assertNull(DeviceKey::fromSpkiBase64($value), $value === '' ? '(prázdné)' : substr($value, 0, 20));
        }
        $this->assertFalse(openssl_error_string());
    }

    public function testWebCryptoSignaturesFromTheBridgeMockAreAccepted(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../Fixtures/Devices/webcrypto-es256.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame(DeviceKey::ALGORITHM, $fixture['algorithm']);
        $key = DeviceKey::fromSpkiBase64($fixture['publicKey']);
        $this->assertNotNull($key, 'veřejný klíč z WebCrypto (SPKI DER v base64)');

        $lengths = [];
        $this->assertNotEmpty($fixture['signatures']);
        foreach ($fixture['signatures'] as $sample) {
            // Klient i server skládají tentýž řetězec …
            $this->assertSame(
                $sample['signingString'],
                DeviceKey::signingString($sample['purpose'], $fixture['deviceId'], $sample['challenge']),
            );
            // … a server rozumí podpisu klienta.
            $this->assertTrue(
                $key->verify($sample['purpose'], $fixture['deviceId'], $sample['challenge'], $sample['signature']),
                $sample['challenge'],
            );
            $this->assertFalse($key->verify('lock', $fixture['deviceId'], $sample['challenge'], $sample['signature']));
            $lengths[strlen((string) base64_decode($sample['signature'], true))] = true;
        }
        // Fixture má pokrýt různé délky DER (úvodní nula u r nebo s).
        $this->assertGreaterThan(1, count($lengths), 'fixture nese jen jednu délku podpisu — vygeneruj ji znovu');
    }
}
