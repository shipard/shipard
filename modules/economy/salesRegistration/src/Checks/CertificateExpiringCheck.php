<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Checks;

use Shipard\Core\Alerts\AlertFinding;
use Shipard\Module\Economy\SalesRegistration\Texts;

/**
 * Upozornění `economy.salesregistration.cert_expiring` (#171 D13):
 * certifikát platné registrace vyprší do 30 dnů. Vypršelý certifikát hlásí
 * {@see CertificateExpiredCheck} — nálezy se nepřekrývají.
 */
class CertificateExpiringCheck extends CertificateCheck
{
    protected function finding(array $registration, \DateTimeImmutable $now, Texts $t): ?AlertFinding
    {
        $validTo = $this->hasCertificate($registration) ? self::time($registration['cert_valid_to']) : null;
        if ($validTo === null || $validTo <= $now || $validTo > $now->modify('+' . self::EXPIRING_DAYS . ' days')) {
            return null;
        }
        $days = (int) floor(($validTo->getTimestamp() - $now->getTimestamp()) / 86400);

        return $this->build(
            $registration,
            'warning',
            $t->t('alert.expiringTitle', 'Certificate of sales registration {name} expires on {date}'),
            $t->t('alert.expiringMessage', 'The cash register certificate ({label} {taxpayerId}) is valid until {date} — days left: {days}. Issue a new one and upload it in the registration detail; after it expires, sales cannot be registered.'),
            ['date' => $this->displayDate($validTo), 'days' => $days],
            $t,
        );
    }
}
