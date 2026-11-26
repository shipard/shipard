<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\ContactPayment;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\ContactPaymentPolicy;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\PaymentTime;
use Shipard\Module\Economy\SalesRegistration\Evaluation\RegistrationFacts;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;

/**
 * Čas kontaktní platby (#171 U23) a pravidlo, kde se příznak nabízí (U14):
 * zadaný čas je čas v zóně místa tržby, převod do zóny serveru je na jednom
 * místě; nabídka jde z dat typu registrací V pořádku.
 */
class PaymentTimeTest extends TestCase
{
    use SalesRegistrationConfig;

    private function prague(): \DateTimeZone
    {
        return new \DateTimeZone('Europe/Prague');
    }

    public function testNormalizeAcceptsFormAndDatabaseShapes(): void
    {
        $this->assertSame('2027-01-05 14:30:00', PaymentTime::normalize('2027-01-05T14:30'));
        $this->assertSame('2027-01-05 14:30:20', PaymentTime::normalize('2027-01-05 14:30:20'));
        $this->assertSame('2027-01-05 14:30:00', PaymentTime::normalize(new \DateTimeImmutable('2027-01-05 14:30:00')));
        foreach ([null, '', '  ', 'zítra', '2027-02-30 10:00', '2027-01-05', 5] as $bad) {
            $this->assertNull(PaymentTime::normalize($bad), var_export($bad, true));
        }
    }

    public function testInstantReadsTheTimeInTheSaleZone(): void
    {
        $winter = PaymentTime::instant('2027-01-05 14:30:00', $this->prague());
        $this->assertSame('2027-01-05T13:30:00+00:00', $winter?->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM));

        $summer = PaymentTime::instant('2027-07-05T14:30', $this->prague());
        $this->assertSame('2027-07-05T12:30:00+00:00', $summer?->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM));

        $this->assertNull(PaymentTime::instant(null, $this->prague()));
    }

    public function testInServerZoneKeepsTheInstant(): void
    {
        $server = PaymentTime::inServerZone('2027-01-05 14:30:00', $this->prague());

        $this->assertNotNull($server);
        $this->assertSame(date_default_timezone_get(), $server->getTimezone()->getName());
        $this->assertSame(PaymentTime::instant('2027-01-05 14:30:00', $this->prague())?->getTimestamp(), $server->getTimestamp());
    }

    public function testFutureIsJudgedWithTolerance(): void
    {
        $now = new \DateTimeImmutable('2027-01-05 13:30:00', new \DateTimeZone('UTC')); // 14:30 v Praze

        $this->assertFalse(PaymentTime::isFuture('2027-01-05 14:30:00', $this->prague(), $now));
        $this->assertFalse(PaymentTime::isFuture('2027-01-05 14:34:00', $this->prague(), $now), 'pár minut napřed je tolerance');
        $this->assertTrue(PaymentTime::isFuture('2027-01-05 14:36:00', $this->prague(), $now));
        $this->assertFalse(PaymentTime::isFuture(null, $this->prague(), $now));

        $this->assertSame('2027-01-05 14:30:00', PaymentTime::notAfterNow('2027-01-05T14:34', $this->prague(), $now));
        $this->assertSame('2027-01-05 09:00:00', PaymentTime::notAfterNow('2027-01-05T09:00', $this->prague(), $now));
    }

    public function testPolicyFollowsTheTypeDataOfConfirmedRegistrations(): void
    {
        $type = SalesRegistrationTypes::fromConfig($this->salesRegistrationConfig())->get('cz.eet2');
        $this->assertNotNull($type);
        $policy = new ContactPaymentPolicy([new RegistrationFacts(1, $type, '2027-01-01', null, 2, [2 => '181'])]);

        $this->assertTrue($policy->hasRegistration());
        $this->assertSame(['invno', 'invpo'], $policy->docTypes());
        $this->assertTrue($policy->offersTab('invno'));
        $this->assertFalse($policy->offersTab('cash'), 'u pokladního dokladu se příznak nenabízí');
        $this->assertTrue($policy->allows('invno', 1));
        $this->assertFalse($policy->allows('invno', 2), 'faktura Kartou spadá pravidlem');
        $this->assertTrue($policy->allows('invpo', 2));
        $this->assertFalse($policy->allows('invpo', 5));
        $this->assertSame('Europe/Prague', $policy->saleTimeZone('invno', 1)->getName());

        $empty = new ContactPaymentPolicy([]);
        $this->assertFalse($empty->hasRegistration());
        $this->assertSame([], $empty->docTypes());
        $this->assertFalse($empty->allows('invno', 1));
        $this->assertSame(date_default_timezone_get(), $empty->saleTimeZone()->getName());
    }
}
