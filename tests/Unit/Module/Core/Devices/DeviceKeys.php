<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Devices;

use Shipard\Module\Core\Devices\DeviceKey;

/**
 * Klíče zařízení pro testy: pár ECDSA vygenerovaný OpenSSL přímo v testu,
 * veřejný klíč v tvaru kontraktu (SPKI DER v base64) a podpis DER v base64
 * — totéž, co posílá aplikace.
 */
trait DeviceKeys
{
    /**
     * @return array{private: \OpenSSLAsymmetricKey, public: string} soukromý klíč a SPKI DER v base64
     */
    protected static function generateEcKey(string $curve = 'prime256v1'): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $curve]);
        if ($key === false) {
            throw new \RuntimeException('OpenSSL cannot generate an EC key: ' . openssl_error_string());
        }
        return ['private' => $key, 'public' => self::spkiBase64((string) openssl_pkey_get_details($key)['key'])];
    }

    /** PEM veřejného klíče → SPKI DER v base64. */
    protected static function spkiBase64(string $pem): string
    {
        return (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
    }

    /** Podpis řetězce kontraktu — DER v base64. */
    protected static function sign(\OpenSSLAsymmetricKey $private, string $purpose, string $deviceId, string $challenge): string
    {
        openssl_sign(DeviceKey::signingString($purpose, $deviceId, $challenge), $signature, $private, OPENSSL_ALGO_SHA256);
        return base64_encode((string) $signature);
    }
}
