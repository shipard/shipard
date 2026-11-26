<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Certificate;

/**
 * Certifikát převzatý z `.p12` (#171 D11): klíč a certifikát v PEM
 * a údaje, které se z certifikátu propisují do registrace. Klíč je
 * nešifrovaný PEM — smí žít jen v paměti do zašifrování klíčem zdroje dat.
 */
final readonly class ImportedCertificate
{
    public function __construct(
        #[\SensitiveParameter]
        public string $keyPem,
        public string $certificatePem,
        /** Identifikátor poplatníka = CN certifikátu (EET 2.0: EIČ). */
        public string $taxpayerId,
        public \DateTimeImmutable $validFrom,
        public \DateTimeImmutable $validTo,
        public string $serial,
    ) {
    }

    /** @return array<string, string> bez klíče — ani do `var_dump` */
    public function __debugInfo(): array
    {
        return [
            'taxpayerId' => $this->taxpayerId,
            'validTo'    => $this->validTo->format(DATE_ATOM),
            'serial'     => $this->serial,
        ];
    }
}
