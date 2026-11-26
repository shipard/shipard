<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\SalesRegistration;

use Ondrejnov\EET\Http\CurlTransport;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Module\Economy\SalesRegistration\Country\Cz\Eet2Engine;
use Shipard\Module\Economy\SalesRegistration\Engine\EngineEnvironment;
use Shipard\Module\Economy\SalesRegistration\Engine\SalesMessage;
use Shipard\Module\Economy\SalesRegistration\Engine\SenderIdentity;
use Shipard\Module\Economy\SalesRegistration\Engine\SendStatus;
use Shipard\Module\Economy\SalesRegistration\Safety\PlaygroundIdentity;
use Shipard\Module\Economy\SalesRegistration\Safety\SalesRegistrationServerConfig;
use Shipard\Module\Economy\SalesRegistration\Sender\SalesRegistrationSender;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\TempDataSource;

/**
 * Živá komunikace s playgroundem EET 2.0 (`pg.trzbyeet.gov.cz`) pod sdílenou
 * testovací identitou. Běží **jen** s proměnnou `SHIPARD_EET_PLAYGROUND=1`
 * (vzor `EET_PLAYGROUND` v knihovně) — jinak se přeskočí a síť se nepoužije:
 *
 *     SHIPARD_EET_PLAYGROUND=1 vendor/bin/phpunit --testsuite Integration --filter Eet2PlaygroundTest
 *
 * Potvrzení z playgroundu (kód končí `-ff`) není platné a nic se jím
 * neeviduje. Playground má servisní okno obvykle ve čtvrtek 20:00–6:00.
 * Zdroj dat test nepotřebuje — odesílač dostane dočasný adresář.
 */
class Eet2PlaygroundTest extends TestCase
{
    use SalesRegistrationConfig;
    use TempDataSource;

    private const URL = 'https://pg.trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4';

    protected function setUp(): void
    {
        if (getenv('SHIPARD_EET_PLAYGROUND') !== '1') {
            $this->markTestSkipped('Set SHIPARD_EET_PLAYGROUND=1 to run tests against the live EET playground.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDataSourceDir();
    }

    private function identity(): SenderIdentity
    {
        $identity = PlaygroundIdentity::forType('cz.eet2');
        $this->assertNotNull($identity);
        $dir = PlaygroundIdentity::DIRECTORY;

        return new SenderIdentity($dir . '/CZ00000019.key', $dir . '/CZ00000019.pem', $identity->taxpayerId(), true);
    }

    private function message(bool $verification, string $taxpayerId = 'CZ00000019'): SalesMessage
    {
        $now = new \DateTimeImmutable();

        return new SalesMessage(
            uuid: SalesMessage::newUuid(),
            unitId: '181',
            cashRegisterId: 'SHIPARD-TEST',
            serialNumber: 'TEST-' . $now->format('YmdHis'),
            soldAt: $now,
            amount: $verification ? '0.00' : '1.00',
            currency: 'czk',
            verification: $verification,
            taxpayerId: $taxpayerId,
        );
    }

    /** Delší limity než v provozu — test nemá padat na pomalé lince. */
    private function engine(): Eet2Engine
    {
        return new Eet2Engine(new CurlTransport(15000, 10000));
    }

    public function testVerificationModeIsProcessedByThePlayground(): void
    {
        $result = $this->engine()->send($this->message(true), $this->identity(), new EngineEnvironment('playground', self::URL));

        $this->assertSame(SendStatus::Verified, $result->status, sprintf('%s: %s', $result->errorCode ?? '-', $result->errorText ?? ''));
        $this->assertTrue($result->test);
        $this->assertNull($result->confirmationCode);
        $this->assertSame([], $result->warnings, 'EIČ zprávy = EIČ testovacího certifikátu → žádné varování 1');
        $this->assertNotNull($result->transactionId);
    }

    public function testSaleIsConfirmedWithATestConfirmationCode(): void
    {
        $result = $this->engine()->send($this->message(false), $this->identity(), new EngineEnvironment('playground', self::URL));

        $this->assertSame(SendStatus::Confirmed, $result->status, sprintf('%s: %s', $result->errorCode ?? '-', $result->errorText ?? ''));
        $this->assertFalse($result->untrusted, 'podpis potvrzení prošel verifierem, který engine používá naostro');
        $this->assertTrue($result->test);
        $this->assertMatchesRegularExpression(
            '~^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}-ff$~',
            (string) $result->confirmationCode,
            'potvrzení playgroundu končí -ff',
        );
    }

    /** Celá cesta odesílače: pojistka `playground`, registrace bez certifikátu, engine z registru. */
    public function testSenderReachesThePlaygroundUnderTheTestIdentity(): void
    {
        $this->createDataSourceDir();
        $config = $this->salesRegistrationConfig(['economy.salesRegistration.engines' => ['cz.eet2' => Eet2Engine::class]]);
        $sender = new SalesRegistrationSender($config, new DataSourceConfig($this->dataSourceDir), SalesRegistrationServerConfig::ofMode('playground'));

        $result = $sender->send(
            ['id' => 1, 'type' => 'cz.eet2', 'mode' => 'production', 'taxpayer_id' => 'CZ99999999', 'cert_pem' => null, 'cert_key' => null],
            $this->message(true, taxpayerId: ''),
        );

        // Engine z registru má provozní limity (2,5 s) — pomalá linka je `unknown`, ne chyba testu.
        if ($result->status === SendStatus::Unknown && !$result->untrusted) {
            $this->markTestSkipped('Playground did not answer within the production time limit: ' . $result->errorText);
        }
        $this->assertSame(SendStatus::Verified, $result->status, sprintf('%s: %s', $result->errorCode ?? '-', $result->errorText ?? ''));
        $this->assertSame('playground', $result->environment);
        $this->assertTrue($result->testIdentity);
        $this->assertStringContainsString('eic_popl="CZ00000019"', (string) $result->requestBody, 've zprávě jde EIČ testovací identity');
        $this->assertDirectoryExists($this->dataSourceDir . '/tmp');
        $this->assertSame([], array_values(array_diff(scandir($this->dataSourceDir . '/tmp') ?: [], ['.', '..'])));
    }
}
