<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Checks;

use Shipard\Core\Alerts\AlertFinding;
use Shipard\Module\Economy\SalesRegistration\Texts;

/**
 * Upozornění `economy.salesregistration.cert_missing` (#171 D13): platná
 * registrace nemá nahraný certifikát — do produkce z ní nic neodejde.
 */
class CertificateMissingCheck extends CertificateCheck
{
    protected function finding(array $registration, \DateTimeImmutable $now, Texts $t): ?AlertFinding
    {
        if ($this->hasCertificate($registration)) {
            return null;
        }

        return $this->build(
            $registration,
            'warning',
            $t->t('alert.missingTitle', 'Sales registration {name} has no certificate'),
            $t->t('alert.missingMessage', 'The registration is confirmed and valid, but no cash register certificate is uploaded — sales cannot be registered. Upload the certificate in the registration detail.'),
            [],
            $t,
        );
    }
}
