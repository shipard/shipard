<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Certificate;

/**
 * Certifikáty pro testy: sdílený testovací `.p12` playgroundu EET (veřejný
 * ZIP finanční správy `CAEET_Playground_2026_v1.zip`, zastaralá šifra
 * RC2-40) a moderní `.p12` vyrobený za běhu.
 */
final class TestCertificates
{
    /** Testovací `.p12` playgroundu — CN `CZ00000019`, platnost do 14. 5. 2027. */
    public const string PLAYGROUND_P12 = __DIR__ . '/../../../../../Fixtures/Module/Economy/SalesRegistration/CA_EET-Playground-CZ00000019.p12';
    /** Heslo ze souboru `password_pokladni_cert_playground.txt` téhož veřejného ZIP. */
    public const string PLAYGROUND_PASSWORD = 'aaaa1111';
    public const string PLAYGROUND_EIC = 'CZ00000019';
    /** Okamžik uvnitř platnosti testovacího certifikátu — testy na něm nestárnou. */
    public const string PLAYGROUND_NOW = '2026-11-26 12:00:00';

    /**
     * Nový klíč a certifikát podepsaný sám sebou.
     *
     * @return array{key: \OpenSSLAsymmetricKey, keyPem: string, certificate: \OpenSSLCertificate, certificatePem: string}
     */
    public static function selfSigned(string $commonName, int $days = 30): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => $commonName, 'countryName' => 'CZ'], $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        openssl_pkey_export($key, $keyPem);
        openssl_x509_export($certificate, $certificatePem);

        return ['key' => $key, 'keyPem' => $keyPem, 'certificate' => $certificate, 'certificatePem' => $certificatePem];
    }

    /** Moderní `.p12` (šifry, které čte i OpenSSL 3 bez legacy provideru) — obsah souboru. */
    public static function modernP12(string $commonName, string $password, int $days = 30): string
    {
        $pair = self::selfSigned($commonName, $days);
        openssl_pkcs12_export($pair['certificate'], $p12, $pair['key'], $password);

        return $p12;
    }

    /** Zapíše obsah do dočasného souboru a vrátí cestu (uklízí volající). */
    public static function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'shpd-p12-');
        file_put_contents($path, $content);

        return $path;
    }
}
