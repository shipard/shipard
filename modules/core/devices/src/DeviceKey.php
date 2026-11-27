<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Devices;

/**
 * Veřejný klíč zařízení a ověření podpisu (#177 U4, kontrakt
 * docs/native-bridge.md §7.1): ECDSA P-256 se SHA-256 (`ES256`), klíč jako
 * SPKI DER v base64, podpis jako DER v base64.
 *
 * **Jediné místo, kde se skládá podepisovaný řetězec** —
 * `shipard-device-v1|<účel>|<deviceId>|<výzva>`. Účel a zařízení jsou
 * součástí podpisu, takže podpis pro jeden endpoint nejde přehrát na jiném
 * ani pod jiným zařízením.
 */
final class DeviceKey
{
    public const string ALGORITHM = 'ES256';

    private const string SIGNING_PREFIX = 'shipard-device-v1';
    private const string CURVE = 'prime256v1';

    /** SPKI klíče P-256 má 91 bajtů (124 znaků base64); strop s rezervou. */
    private const int MAX_KEY_LENGTH = 200;
    /** DER podpis P-256 má nejvýš 72 bajtů (96 znaků base64). */
    private const int MAX_SIGNATURE_LENGTH = 128;

    private function __construct(private readonly string $pem)
    {
    }

    /**
     * @param string $spkiBase64 veřejný klíč, SPKI DER v base64
     * @return self|null null = není to klíč ECDSA P-256
     */
    public static function fromSpkiBase64(string $spkiBase64): ?self
    {
        if ($spkiBase64 === '' || strlen($spkiBase64) > self::MAX_KEY_LENGTH) {
            return null;
        }
        $der = base64_decode($spkiBase64, true);
        if ($der === false || $der === '') {
            return null;
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";

        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            self::drainOpensslErrors();
            return null;
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details)
            || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC
            || ($details['ec']['curve_name'] ?? null) !== self::CURVE) {
            return null;
        }

        return new self($pem);
    }

    /** Podepisovaný řetězec (kontrakt §7.1). */
    public static function signingString(string $purpose, string $deviceId, string $challenge): string
    {
        return self::SIGNING_PREFIX . '|' . $purpose . '|' . $deviceId . '|' . $challenge;
    }

    /**
     * @param string $signatureBase64 podpis řetězce s daným účelem, DER v base64
     */
    public function verify(string $purpose, string $deviceId, string $challenge, string $signatureBase64): bool
    {
        if ($signatureBase64 === '' || strlen($signatureBase64) > self::MAX_SIGNATURE_LENGTH) {
            return false;
        }
        $signature = base64_decode($signatureBase64, true);
        if ($signature === false || $signature === '') {
            return false;
        }

        $result = openssl_verify(
            self::signingString($purpose, $deviceId, $challenge),
            $signature,
            $this->pem,
            OPENSSL_ALGO_SHA256,
        );
        if ($result !== 1) {
            // 0 = podpis nesedí, -1 / false = chyba (poškozený DER) — obojí je neplatný podpis.
            self::drainOpensslErrors();
            return false;
        }
        return true;
    }

    /** Chyby OpenSSL zůstávají ve frontě procesu — nesmí se přenést do dalšího volání. */
    private static function drainOpensslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // vyprázdnit
        }
    }
}
