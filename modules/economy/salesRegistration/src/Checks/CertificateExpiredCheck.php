<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Checks;

use Shipard\Core\Alerts\AlertFinding;
use Shipard\Module\Economy\SalesRegistration\Texts;

/**
 * Upozornění `economy.salesregistration.cert_expired` (#171 D13): certifikát
 * platné registrace už vypršel — zprávy podepsané jím služba odmítne.
 */
class CertificateExpiredCheck extends CertificateCheck
{
    protected function finding(array $registration, \DateTimeImmutable $now, Texts $t): ?AlertFinding
    {
        $validTo = $this->hasCertificate($registration) ? self::time($registration['cert_valid_to']) : null;
        if ($validTo === null || $validTo > $now) {
            return null;
        }

        return $this->build(
            $registration,
            'error',
            $t->t('alert.expiredTitle', 'Certificate of sales registration {name} has expired'),
            $t->t('alert.expiredMessage', 'The cash register certificate ({label} {taxpayerId}) expired on {date} — sales cannot be registered. Issue a new one and upload it in the registration detail.'),
            ['date' => $this->displayDate($validTo)],
            $t,
        );
    }
}
