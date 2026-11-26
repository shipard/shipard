<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Certificate;

use Shipard\Module\Economy\SalesRegistration\SalesRegistrationType;

/**
 * Převzetí pokladního certifikátu z `.p12` (#171 D11): heslo + soubor →
 * klíč a certifikát v PEM s ověřenou identitou.
 *
 * Čte se `openssl_pkcs12_read`; když selže jinak než na hesle (typicky
 * zastaralá šifra RC2-40 na OpenSSL 3), zkusí se {@see LegacyPkcs12Reader}.
 * Pak kontroly: klíč patří k certifikátu, CN odpovídá masce identifikátoru
 * typu evidence, certifikát právě platí. Heslo se nikam neukládá ani
 * neloguje a výjimky ho nenesou.
 */
class Pkcs12Importer
{
    /** Strop velikosti souboru — pokladní `.p12` má jednotky kB. */
    public const int MAX_BYTES = 65536;

    public function __construct(private readonly ?LegacyPkcs12Reader $legacyReader = null)
    {
    }

    /**
     * @throws CertificateImportException
     */
    public function import(
        string $path,
        #[\SensitiveParameter]
        string $password,
        SalesRegistrationType $type,
        ?\DateTimeImmutable $now = null,
    ): ImportedCertificate {
        $content = is_file($path) ? @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1) : false;
        if ($content === false || $content === '' || strlen($content) > self::MAX_BYTES) {
            throw new CertificateImportException(
                CertificateImportException::NOT_A_CERTIFICATE,
                'The uploaded file is empty, unreadable or too large for a PKCS#12 certificate.',
            );
        }

        [$keyPem, $certificatePems] = $this->readParts($path, $content, $password);

        return $this->verify($keyPem, $certificatePems, $type, $now ?? new \DateTimeImmutable());
    }

    /**
     * @return array{0: string, 1: list<string>} klíč v PEM a kandidáti na certifikát
     *
     * @throws CertificateImportException
     */
    private function readParts(
        string $path,
        string $content,
        #[\SensitiveParameter]
        string $password,
    ): array {
        self::drainOpenSslErrors();
        if (@openssl_pkcs12_read($content, $parts, $password)
            && is_string($parts['pkey'] ?? null) && is_string($parts['cert'] ?? null)
        ) {
            return [$parts['pkey'], [$parts['cert']]];
        }
        if (stripos(self::drainOpenSslErrors(), 'mac verify failure') !== false) {
            throw new CertificateImportException(
                CertificateImportException::WRONG_PASSWORD,
                'The PKCS#12 file cannot be opened with the given password.',
            );
        }

        // Jiné selhání: zastaralá šifra, nebo soubor není PKCS#12 — rozliší
        // to až záložní čtení.
        $legacy = ($this->legacyReader ?? new LegacyPkcs12Reader())->read($path, $password);
        switch ($legacy['outcome']) {
            case LegacyPkcs12Reader::OUTCOME_OK:
                return self::splitPem($legacy['pem']);
            case LegacyPkcs12Reader::OUTCOME_WRONG_PASSWORD:
                throw new CertificateImportException(
                    CertificateImportException::WRONG_PASSWORD,
                    'The PKCS#12 file cannot be opened with the given password.',
                );
            case LegacyPkcs12Reader::OUTCOME_UNAVAILABLE:
                throw new CertificateImportException(
                    CertificateImportException::LEGACY_UNAVAILABLE,
                    'The PKCS#12 file cannot be read by PHP and the openssl binary for the legacy fallback is not available.',
                );
            default:
                throw new CertificateImportException(
                    CertificateImportException::NOT_A_CERTIFICATE,
                    'The uploaded file is not a readable PKCS#12 certificate.',
                );
        }
    }

    /**
     * Výstup `openssl pkcs12 -nodes` → klíč a certifikáty.
     *
     * @return array{0: string, 1: list<string>}
     *
     * @throws CertificateImportException
     */
    private static function splitPem(
        #[\SensitiveParameter]
        string $pem,
    ): array {
        preg_match('/-----BEGIN ((?:RSA |EC )?PRIVATE KEY)-----.+?-----END \1-----/s', $pem, $key);
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $certificates);
        if (!isset($key[0]) || $certificates[0] === []) {
            throw new CertificateImportException(
                CertificateImportException::NOT_A_CERTIFICATE,
                'The PKCS#12 file does not contain both a private key and a certificate.',
            );
        }
        return [$key[0] . "\n", array_map(static fn(string $c): string => $c . "\n", $certificates[0])];
    }

    /**
     * @param list<string> $certificatePems
     *
     * @throws CertificateImportException
     */
    private function verify(
        #[\SensitiveParameter]
        string $keyPem,
        array $certificatePems,
        SalesRegistrationType $type,
        \DateTimeImmutable $now,
    ): ImportedCertificate {
        $key = @openssl_pkey_get_private($keyPem);
        if ($key === false) {
            throw new CertificateImportException(
                CertificateImportException::NOT_A_CERTIFICATE,
                'The private key in the PKCS#12 file cannot be read.',
            );
        }

        $certificatePem = null;
        foreach ($certificatePems as $candidate) {
            if (@openssl_x509_check_private_key($candidate, $key)) {
                $certificatePem = $candidate;
                break;
            }
        }
        if ($certificatePem === null) {
            throw new CertificateImportException(
                CertificateImportException::KEY_MISMATCH,
                'The private key does not belong to the certificate.',
            );
        }

        $parsed = @openssl_x509_parse($certificatePem);
        if (!is_array($parsed) || !@openssl_x509_export($certificatePem, $normalizedCertificate)
            || !@openssl_pkey_export($key, $normalizedKey)
        ) {
            throw new CertificateImportException(
                CertificateImportException::NOT_A_CERTIFICATE,
                'The certificate in the PKCS#12 file cannot be read.',
            );
        }

        $commonName = $parsed['subject']['CN'] ?? '';
        $commonName = is_array($commonName) ? (string) reset($commonName) : (string) $commonName;
        if (!$type->isValidTaxpayerId($commonName)) {
            throw new CertificateImportException(
                CertificateImportException::NOT_FOR_TYPE,
                'The common name of the certificate does not match the taxpayer identifier of the registration type.',
                ['commonName' => mb_substr($commonName, 0, 64)],
            );
        }

        $validFrom = new \DateTimeImmutable('@' . (int) ($parsed['validFrom_time_t'] ?? 0));
        $validTo = new \DateTimeImmutable('@' . (int) ($parsed['validTo_time_t'] ?? 0));
        if ($validTo < $now) {
            throw new CertificateImportException(
                CertificateImportException::EXPIRED,
                'The certificate has expired.',
                ['taxpayerId' => $commonName, 'validTo' => $validTo->format(DATE_ATOM)],
            );
        }
        if ($validFrom > $now) {
            throw new CertificateImportException(
                CertificateImportException::NOT_YET_VALID,
                'The certificate is not valid yet.',
                ['taxpayerId' => $commonName, 'validFrom' => $validFrom->format(DATE_ATOM)],
            );
        }

        return new ImportedCertificate(
            keyPem: $normalizedKey,
            certificatePem: $normalizedCertificate,
            taxpayerId: $commonName,
            validFrom: $validFrom,
            validTo: $validTo,
            serial: strtoupper((string) ($parsed['serialNumberHex'] ?? '')),
        );
    }

    /** Vyprázdní frontu chyb OpenSSL a vrátí je spojené. */
    private static function drainOpenSslErrors(): string
    {
        $errors = [];
        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }
        return implode('; ', $errors);
    }
}
