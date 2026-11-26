<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Engine;

use Ondrejnov\EET\Exceptions\TransportException;
use Ondrejnov\EET\Security\ResponseVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\SalesRegistration\Country\Cz\Eet2Engine;
use Shipard\Module\Economy\SalesRegistration\Engine\EngineEnvironment;
use Shipard\Module\Economy\SalesRegistration\Engine\SalesMessage;
use Shipard\Module\Economy\SalesRegistration\Engine\SalesRegistrationEngine;
use Shipard\Module\Economy\SalesRegistration\Engine\SenderIdentity;
use Shipard\Module\Economy\SalesRegistration\Engine\SendResult;
use Shipard\Module\Economy\SalesRegistration\Engine\SendStatus;
use Shipard\Module\Economy\SalesRegistration\Safety\PlaygroundIdentity;

/**
 * Engine EET 2.0 (#171 D12): převod výsledků a výjimek knihovny na obecný
 * výsledek — nad oficiálními vzorovými zprávami a skutečnými odpověďmi
 * playgroundu přes falešný transport. Síť se nepoužívá.
 */
class Eet2EngineTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../../../Fixtures/Module/Economy/SalesRegistration/eet';

    /** UUID zprávy, ke které patří skutečné potvrzení playgroundu. */
    private const CONFIRMED_UUID = '4a4b8444-dd2d-4cf1-9a45-1743f06cfb89';
    private const CONFIRMED_POK = 'c8e6e4cf-92bf-46ee-80fe-986a62bcad2a-ff';
    private const ORGANIZATION = 'Generální finanční ředitelství';
    private const URL = 'https://pg.trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4';

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . '/' . $name);
    }

    /** Verifier bez řetězu certifikátů — podpis a `O`; na dnešním datu nezávisí. */
    private function engine(FakeTransport $transport, ?ResponseVerifier $verifier = null): Eet2Engine
    {
        return new Eet2Engine($transport, $verifier ?? new ResponseVerifier(null, self::ORGANIZATION));
    }

    private function identity(): SenderIdentity
    {
        $dir = PlaygroundIdentity::DIRECTORY;
        return new SenderIdentity($dir . '/CZ00000019.key', $dir . '/CZ00000019.pem', 'CZ00000019', true);
    }

    private function environment(): EngineEnvironment
    {
        return new EngineEnvironment('playground', self::URL);
    }

    private function message(bool $verification = false, string $uuid = self::CONFIRMED_UUID, array $override = []): SalesMessage
    {
        return new SalesMessage(...($override + [
            'uuid'           => $uuid,
            'unitId'         => '181',
            'cashRegisterId' => 'SHIPARD-TEST',
            'serialNumber'   => 'TEST-20261126143015',
            'soldAt'         => new \DateTimeImmutable('2026-11-26 13:30:15', new \DateTimeZone('UTC')),
            'amount'         => '1250.50',
            'currency'       => 'czk',
            'firstSending'   => true,
            'verification'   => $verification,
            'taxpayerId'     => 'CZ00000019',
        ]));
    }

    public function testImplementsTheEngineContractAndNeedsNoArguments(): void
    {
        $this->assertInstanceOf(SalesRegistrationEngine::class, new Eet2Engine());
        $this->assertSame(2500, Eet2Engine::TIMEOUT_MS);
        $this->assertSame(2000, Eet2Engine::CONNECT_TIMEOUT_MS);
    }

    public function testRealPlaygroundConfirmationIsConfirmed(): void
    {
        $transport = new FakeTransport(self::fixture('playground-confirmation.xml'), 'global-tx-42');

        $result = $this->engine($transport)->send($this->message(), $this->identity(), $this->environment());

        $this->assertSame(SendStatus::Confirmed, $result->status);
        $this->assertTrue($result->status->isRegistered());
        $this->assertSame(self::CONFIRMED_POK, $result->confirmationCode);
        $this->assertTrue($result->sent);
        $this->assertTrue($result->test, 'potvrzení playgroundu nese příznak testu');
        $this->assertFalse($result->untrusted);
        $this->assertNull($result->errorCode);
        $this->assertSame([], $result->warnings);
        $this->assertSame('global-tx-42', $result->transactionId);
        $this->assertSame($transport->requests[0]['body'], $result->requestBody);
        $this->assertSame(self::fixture('playground-confirmation.xml'), $result->responseBody);
        $this->assertIsInt($result->durationMs);
    }

    public function testRequestCarriesTheMessageSignedByTheIdentity(): void
    {
        $transport = new FakeTransport(self::fixture('playground-confirmation.xml'));

        $this->engine($transport)->send($this->message(), $this->identity(), $this->environment());

        $this->assertCount(1, $transport->requests);
        $this->assertSame(self::URL, $transport->requests[0]['url']);
        $this->assertSame('http://fs.gov.cz/eet/OdeslaniTrzby', $transport->requests[0]['action']);
        $this->assertSame(self::CONFIRMED_UUID, $transport->attribute('Hlavicka', 'uuid_zpravy'), 'UUID dává volající, ne knihovna');
        $this->assertSame('true', $transport->attribute('Hlavicka', 'prvni_zaslani'));
        $this->assertNull($transport->attribute('Hlavicka', 'overeni'), 'ostrá zpráva příznak ověření nenese');
        $this->assertSame('CZ00000019', $transport->attribute('Data', 'eic_popl'));
        $this->assertSame('181', $transport->attribute('Data', 'id_jednotky'));
        $this->assertSame('SHIPARD-TEST', $transport->attribute('Data', 'id_pokl'));
        $this->assertSame('TEST-20261126143015', $transport->attribute('Data', 'porad_cis'));
        $this->assertSame('1250.50', $transport->attribute('Data', 'celk_trzba'));
        $this->assertSame('2026-11-26T14:30:15+01:00', $transport->attribute('Data', 'dat_trzby'), 'čas tržby v zóně Europe/Prague');
        foreach (['urceno_cerp_zuct', 'cerp_zuct', 'eic_poverujiciho', 'povereni_vice_popl'] as $outOfScope) {
            $this->assertNull($transport->attribute('Data', $outOfScope), "{$outOfScope} je mimo fázi 1 (#171 D14)");
        }
    }

    /** Podpis naší zprávy má tutéž stavbu jako oficiální vzorové zprávy finanční správy. */
    public function testRequestIsSignedLikeTheOfficialSampleMessages(): void
    {
        $verifier = new ResponseVerifier();
        $load = static function (string $xml): \DOMDocument {
            $document = new \DOMDocument();
            $document->loadXML($xml);
            return $document;
        };
        foreach (['CZ00000019', 'CZ683555118', 'CZ8551015704'] as $eic) {
            $verifier->verifySignature($load(self::fixture($eic . '.eet.v4.req.xml')));
        }

        $transport = new FakeTransport(self::fixture('playground-confirmation.xml'));
        $this->engine($transport)->send($this->message(), $this->identity(), $this->environment());
        $certificate = $verifier->verifySignature($load($transport->requests[0]['body']));

        $this->assertSame('CZ00000019', openssl_x509_parse($certificate)['subject']['CN'], 'zprávu podepsala předaná identita');
    }

    public function testResendCarriesTheFlagOfRepeatedSending(): void
    {
        $transport = new FakeTransport(self::fixture('playground-confirmation.xml'));

        $this->engine($transport)->send($this->message(override: ['firstSending' => false]), $this->identity(), $this->environment());

        $this->assertSame('false', $transport->attribute('Hlavicka', 'prvni_zaslani'));
    }

    public function testConfirmationOfAnotherMessageCannotBeTrusted(): void
    {
        $transport = new FakeTransport(self::fixture('playground-confirmation.xml'));

        $result = $this->engine($transport)->send($this->message(uuid: SalesMessage::newUuid()), $this->identity(), $this->environment());

        $this->assertSame(SendStatus::Unknown, $result->status);
        $this->assertTrue($result->untrusted);
        $this->assertNull($result->confirmationCode, 'POK z nedůvěryhodné odpovědi se nepřebírá');
        $this->assertTrue($result->sent);
        $this->assertFalse($result->test);
        $this->assertStringContainsString('different message', (string) $result->errorText);
        $this->assertNotNull($result->responseBody, 'tělo odpovědi zůstává pro žurnál');
    }

    public function testTamperedConfirmationCannotBeTrusted(): void
    {
        $forged = str_replace(self::CONFIRMED_POK, 'aaaaaaaa-92bf-46ee-80fe-986a62bcad2a-ff', self::fixture('playground-confirmation.xml'));

        $result = $this->engine(new FakeTransport($forged))->send($this->message(), $this->identity(), $this->environment());

        $this->assertSame(SendStatus::Unknown, $result->status);
        $this->assertTrue($result->untrusted);
        $this->assertNull($result->confirmationCode);
    }

    public function testConfirmationSignedByAnotherOrganizationCannotBeTrusted(): void
    {
        $engine = $this->engine(new FakeTransport(self::fixture('playground-confirmation.xml')), new ResponseVerifier(null, 'Někdo jiný'));

        $result = $engine->send($this->message(), $this->identity(), $this->environment());

        $this->assertSame(SendStatus::Unknown, $result->status);
        $this->assertTrue($result->untrusted);
    }

    /**
     * Skutečné potvrzení projde i verifierem, který engine používá naostro
     * (řetěz I.CA z knihovny + organizace). Podpisový certifikát playgroundu
     * platí do 8. 6. 2027 — test začne padat 30 dní předem: obnov fixtury
     * (tests/Fixtures/Module/Economy/SalesRegistration/eet/README.md).
     */
    public function testRealConfirmationPassesTheProductionVerifier(): void
    {
        preg_match('/BinarySecurityToken[^>]*>([^<]+)</', self::fixture('playground-confirmation.xml'), $m);
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(trim($m[1]), 64, "\n") . "-----END CERTIFICATE-----\n";
        $validTo = new \DateTimeImmutable('@' . openssl_x509_parse($pem)['validTo_time_t']);
        $this->assertGreaterThan(
            new \DateTimeImmutable('+30 days'),
            $validTo,
            'Podpisový certifikát ve fixtuře potvrzení platí jen do ' . $validTo->format('Y-m-d') . ' — obnov fixtury EET.',
        );

        $engine = new Eet2Engine(new FakeTransport(self::fixture('playground-confirmation.xml')));
        $result = $engine->send($this->message(), $this->identity(), $this->environment());

        $this->assertSame(SendStatus::Confirmed, $result->status, (string) $result->errorText);
        $this->assertFalse($result->untrusted);
    }

    public function testRealPlaygroundVerificationResponseIsVerified(): void
    {
        $transport = new FakeTransport(self::fixture('playground-verification.xml'));

        $result = $this->engine($transport)->send($this->message(verification: true), $this->identity(), $this->environment());

        $this->assertSame(SendStatus::Verified, $result->status);
        $this->assertFalse($result->status->isRegistered(), 'ověřovací mód povinnost neplní');
        $this->assertNull($result->confirmationCode);
        $this->assertTrue($result->test);
        $this->assertSame([], $result->warnings);
        $this->assertSame('true', $transport->attribute('Hlavicka', 'overeni'));
    }

    public function testVerificationWithWarningsOfTheService(): void
    {
        $reply = FakeTransport::errorReply(
            0,
            'Datovou zpravu evidovane trzby v overovacim modu se podarilo zpracovat',
            '<eet:Varovani kod_varov="1">EIC poplatnika v datove zprave se neshoduje s EIC v certifikatu</eet:Varovani>',
        );

        $result = $this->engine(new FakeTransport($reply))->send($this->message(verification: true), $this->identity(), $this->environment());

        $this->assertSame(SendStatus::Verified, $result->status);
        $this->assertSame([1 => 'EIC poplatnika v datove zprave se neshoduje s EIC v certifikatu'], $result->warnings);
    }

    /** @return array<string, array{int, bool, SendStatus}> kód chyby, ověřovací mód → stav */
    public static function serviceErrors(): array
    {
        return [
            'temporary -1 / send'    => [-1, false, SendStatus::RetryLater],
            'temporary 8 / send'     => [8, false, SendStatus::RetryLater],
            'bad signature / send'   => [4, false, SendStatus::Rejected],
            'bad EIC / send'         => [6, false, SendStatus::Rejected],
            'schema / send'          => [3, false, SendStatus::Rejected],
            'temporary -1 / verify'  => [-1, true, SendStatus::RetryLater],
            'temporary 8 / verify'   => [8, true, SendStatus::RetryLater],
            'bad signature / verify' => [4, true, SendStatus::Rejected],
            'too large / verify'     => [7, true, SendStatus::Rejected],
        ];
    }

    #[DataProvider('serviceErrors')]
    public function testServiceErrorsAreRetryLaterOrRejected(int $code, bool $verification, SendStatus $expected): void
    {
        $transport = new FakeTransport(FakeTransport::errorReply($code, 'Popis chyby'));

        $result = $this->engine($transport)->send($this->message($verification, SalesMessage::newUuid()), $this->identity(), $this->environment());

        $this->assertSame($expected, $result->status);
        $this->assertSame($code, $result->errorCode);
        $this->assertSame('Popis chyby', $result->errorText);
        $this->assertTrue($result->sent);
        $this->assertNull($result->confirmationCode);
        $this->assertFalse($result->untrusted);
        $this->assertSame('tx-test-1', $result->transactionId);
    }

    public function testServiceErrorWithoutTextUsesTheTextOfTheInterface(): void
    {
        $result = $this->engine(new FakeTransport(FakeTransport::errorReply(8, '')))
            ->send($this->message(true), $this->identity(), $this->environment());

        $this->assertSame(SendStatus::RetryLater, $result->status);
        $this->assertNotSame('', (string) $result->errorText);
    }

    /** @return array<string, array{string|TransportException}> */
    public static function unknownOutcomes(): array
    {
        return [
            'timeout'           => [new TransportException('Operation timed out after 2500 milliseconds', 28)],
            'connection failed' => [new TransportException('Could not resolve host', 6)],
            'empty body'        => [''],
            'html error page'   => ['<html><body>502 Bad Gateway</body></html>'],
            'soap fault'        => ['<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><soapenv:Fault><faultcode>x</faultcode><faultstring>Internal error</faultstring></soapenv:Fault></soapenv:Body></soapenv:Envelope>'],
        ];
    }

    #[DataProvider('unknownOutcomes')]
    public function testNoValidResponseLeavesTheStateUnknown(string|TransportException $reply): void
    {
        foreach ([false, true] as $verification) {
            $transport = new FakeTransport($reply);

            $result = $this->engine($transport)->send($this->message($verification), $this->identity(), $this->environment());

            $this->assertSame(SendStatus::Unknown, $result->status);
            $this->assertTrue($result->sent, 'zpráva odešla — stav tržby není známý');
            $this->assertFalse($result->untrusted);
            $this->assertNull($result->confirmationCode);
            $this->assertNotSame('', (string) $result->errorText);
            $this->assertNotNull($result->requestBody);
        }
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidMessages(): array
    {
        return [
            'unit with leading zero'  => [['unitId' => '0181']],
            'cash register too long'  => [['cashRegisterId' => str_repeat('A', 21)]],
            'serial with diacritics'  => [['serialNumber' => 'účtenka 1']],
            'bad taxpayer id'         => [['taxpayerId' => '00000019']],
            'empty taxpayer id'       => [['taxpayerId' => '']],
            'three decimals'          => [['amount' => '10.123']],
            'amount is not a number'  => [['amount' => 'deset']],
            'bad uuid'                => [['uuid' => 'not-a-uuid']],
        ];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidMessages')]
    public function testInvalidMessageIsRejectedWithoutSending(array $override): void
    {
        $transport = new FakeTransport(self::fixture('playground-confirmation.xml'));

        $result = $this->engine($transport)->send($this->message(override: $override), $this->identity(), $this->environment());

        $this->assertSame(SendStatus::Rejected, $result->status);
        $this->assertFalse($result->sent);
        $this->assertSame(SendResult::PROBLEM_MESSAGE, $result->problem);
        $this->assertNotSame('', (string) $result->errorText);
        $this->assertSame([], $transport->requests, 'nic neodešlo');
    }

    public function testItemsOutsideThePhaseAreRefusedInsteadOfSilentlyDropped(): void
    {
        $transport = new FakeTransport(self::fixture('playground-confirmation.xml'));

        $result = $this->engine($transport)->send(
            $this->message(override: ['extras' => ['cerp_zuct' => '302.00']]),
            $this->identity(),
            $this->environment(),
        );

        $this->assertSame(SendStatus::Rejected, $result->status);
        $this->assertFalse($result->sent);
        $this->assertStringContainsString('cerp_zuct', (string) $result->errorText);
        $this->assertSame([], $transport->requests);
    }

    public function testUnreadableKeyFilesAreRejectedWithoutSending(): void
    {
        $transport = new FakeTransport(self::fixture('playground-confirmation.xml'));
        $identity = new SenderIdentity('/nonexistent/key.pem', '/nonexistent/cert.pem', 'CZ00000019');

        $result = $this->engine($transport)->send($this->message(), $identity, $this->environment());

        $this->assertSame(SendStatus::Rejected, $result->status);
        $this->assertFalse($result->sent);
        $this->assertSame(SendResult::PROBLEM_KEY, $result->problem);
        $this->assertSame([], $transport->requests);
        $this->assertStringNotContainsString('/nonexistent', (string) $result->errorText, 'cesta ke klíči do výsledku nepatří');
    }

    public function testResultNeverLeaksBodiesIntoTheLogContext(): void
    {
        $result = $this->engine(new FakeTransport(self::fixture('playground-confirmation.xml')))
            ->send($this->message(), $this->identity(), $this->environment());

        $context = json_encode($result->logContext(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Envelope', $context);
        $this->assertStringNotContainsString('BinarySecurityToken', $context);
        $this->assertStringContainsString(self::CONFIRMED_POK, $context);
        $this->assertStringNotContainsString('Envelope', print_r($result, true));
    }

    public function testNewUuidIsVersion4(): void
    {
        $uuids = array_map(static fn(): string => SalesMessage::newUuid(), range(1, 20));

        $this->assertCount(20, array_unique($uuids));
        foreach ($uuids as $uuid) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
        }
    }
}
