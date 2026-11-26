<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Certificate;

/**
 * Certifikát nejde převzít (#171 D11). `kind` říká proč — akce z něj skládá
 * hlášku pro uživatele. Zpráva výjimky je technická (anglicky, do logu)
 * a nikdy nenese heslo ani obsah klíče.
 */
final class CertificateImportException extends \RuntimeException
{
    /** Špatné heslo k souboru. */
    public const string WRONG_PASSWORD = 'wrongPassword';
    /** Soubor není PKCS#12 s certifikátem a klíčem. */
    public const string NOT_A_CERTIFICATE = 'notACertificate';
    /** Soubor používá zastaralou šifru a server ji neumí převést (chybí `openssl`). */
    public const string LEGACY_UNAVAILABLE = 'legacyUnavailable';
    /** Soukromý klíč nepatří k certifikátu. */
    public const string KEY_MISMATCH = 'keyMismatch';
    /** CN certifikátu neodpovídá masce identifikátoru typu evidence. */
    public const string NOT_FOR_TYPE = 'notForType';
    /** Certifikát už neplatí. */
    public const string EXPIRED = 'expired';
    /** Certifikát ještě neplatí. */
    public const string NOT_YET_VALID = 'notYetValid';

    /** @param array<string, scalar> $params údaje pro hlášku (CN, datum) — nikdy tajemství */
    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly array $params = [],
    ) {
        parent::__construct($message);
    }
}
