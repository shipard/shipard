<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Sender;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Module\Economy\SalesRegistration\Certificate\KeyFiles;
use Shipard\Module\Economy\SalesRegistration\Engine\EngineEnvironment;
use Shipard\Module\Economy\SalesRegistration\Engine\SalesMessage;
use Shipard\Module\Economy\SalesRegistration\Engine\SalesRegistrationEngine;
use Shipard\Module\Economy\SalesRegistration\Engine\SenderIdentity;
use Shipard\Module\Economy\SalesRegistration\Engine\SendResult;
use Shipard\Module\Economy\SalesRegistration\Engine\SendStatus;
use Shipard\Module\Economy\SalesRegistration\Safety\PlaygroundIdentity;
use Shipard\Module\Economy\SalesRegistration\Safety\SalesRegistrationServerConfig;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationType;
use Shipard\Module\Economy\SalesRegistration\Sender\SalesRegistrationSender;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\Certificate\TestCertificates;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\TempDataSource;

/**
 * Odesílač (#171 D9–D12, U1): trasa podle pojistky a režimu, identita
 * registrace nebo testovací, dočasný soubor klíče jen po dobu volání enginu,
 * `drop` bez sítě, log bez klíče a těl zpráv.
 */
class SalesRegistrationSenderTest extends TestCase
{
    use SalesRegistrationConfig;
    use TempDataSource;

    private const REGISTRATION_EIC = 'CZ12345678';
    private const PRODUCTION_URL = 'https://trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4';
    private const PLAYGROUND_URL = 'https://pg.trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4';

    private string $logFile;
    /** @var array{keyPem: string, certificatePem: string} */
    private array $pair;

    protected function setUp(): void
    {
        $this->createDataSourceDir();
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'shpd-salesreg-log-');
        ErrorLogger::resetForTesting();
        ErrorLogger::setLogPath($this->logFile);
        $this->pair = TestCertificates::selfSigned(self::REGISTRATION_EIC);
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        @unlink($this->logFile);
        $this->removeDataSourceDir();
    }

    /** Engine, který nic neposílá — zaznamená, co dostal, a co bylo v souborech identity. */
    private function recordingEngine(?\Throwable $throw = null): SalesRegistrationEngine
    {
        return new class($throw) implements SalesRegistrationEngine {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function __construct(private readonly ?\Throwable $throw)
            {
            }

            public function send(SalesMessage $message, SenderIdentity $identity, EngineEnvironment $environment): SendResult
            {
                $this->calls[] = [
                    'message'     => $message,
                    'identity'    => $identity,
                    'environment' => $environment,
                    'key'         => file_get_contents($identity->keyPath),
                    'certificate' => file_get_contents($identity->certificatePath),
                    'keyMode'     => fileperms($identity->keyPath) & 0777,
                ];
                if ($this->throw !== null) {
                    throw $this->throw;
                }
                return new SendResult(
                    $message->verification ? SendStatus::Verified : SendStatus::Confirmed,
                    true,
                    confirmationCode: $message->verification ? null : 'pok-123',
                    transactionId: 'tx-9',
                    requestBody: '<Envelope>REQUEST BODY</Envelope>',
                    responseBody: '<Envelope>RESPONSE BODY</Envelope>',
                    durationMs: 120,
                    test: $identity->test,
                );
            }
        };
    }

    /** @param array<string, mixed> $configOverride */
    private function sender(string $safetyMode, SalesRegistrationEngine $engine, array $configOverride = [], bool $engineAvailable = true): SalesRegistrationSender
    {
        $sender = new class($this->salesRegistrationConfig($configOverride), $this->dataSourceConfig(), SalesRegistrationServerConfig::ofMode($safetyMode)) extends SalesRegistrationSender {
            public ?SalesRegistrationEngine $testEngine = null;

            protected function engine(SalesRegistrationType $type): ?SalesRegistrationEngine
            {
                return $this->testEngine;
            }
        };
        $sender->testEngine = $engineAvailable ? $engine : null;
        return $sender;
    }

    /** @return array<string, mixed> řádek registrace s certifikátem a šifrovaným klíčem */
    private function registration(string $mode, array $override = []): array
    {
        return $override + [
            'id'          => 7,
            'type'        => 'cz.eet2',
            'name'        => 'Prodejna',
            'mode'        => $mode,
            'taxpayer_id' => self::REGISTRATION_EIC,
            'cert_pem'    => $this->pair['certificatePem'],
            'cert_key'    => DsSecretCipher::forConfig($this->dataSourceConfig())->encrypt($this->pair['keyPem']),
        ];
    }

    private function message(bool $verification = false): SalesMessage
    {
        return new SalesMessage(
            uuid: SalesMessage::newUuid(),
            unitId: '181',
            cashRegisterId: 'POKL-1',
            serialNumber: '2027/0001',
            soldAt: new \DateTimeImmutable('2027-01-04 10:00:00'),
            amount: '1250.00',
            currency: 'czk',
            verification: $verification,
        );
    }

    /** @return list<string> */
    private function temporaryFiles(): array
    {
        $dir = $this->dataSourceDir . '/' . KeyFiles::DIRECTORY;
        return is_dir($dir) ? array_values(array_diff(scandir($dir) ?: [], ['.', '..'])) : [];
    }

    /**
     * @return array<string, array{string, string, bool, string, bool, bool}>
     *         pojistka, režim, zpráva žádá ověření → prostředí, testovací identita, ověření
     */
    public static function routes(): array
    {
        return [
            'off / production'                => ['off', 'production', false, 'production', false, false],
            'off / verification'              => ['off', 'verification', false, 'production', false, true],
            'off / production / verify asked' => ['off', 'production', true, 'production', false, true],
            'off / playground'                => ['off', 'playground', false, 'playground', true, false],
            'playground / production'         => ['playground', 'production', false, 'playground', true, false],
            'playground / verification'       => ['playground', 'verification', false, 'playground', true, true],
            'playground / playground'         => ['playground', 'playground', true, 'playground', true, true],
        ];
    }

    #[DataProvider('routes')]
    public function testMessageGoesWhereSafetyAndModeSay(
        string $safety,
        string $mode,
        bool $verificationRequested,
        string $environment,
        bool $testIdentity,
        bool $verification,
    ): void {
        $engine = $this->recordingEngine();

        $result = $this->sender($safety, $engine)->send($this->registration($mode), $this->message($verificationRequested));

        $this->assertCount(1, $engine->calls);
        $call = $engine->calls[0];
        $this->assertSame($environment, $call['environment']->name);
        $this->assertSame($environment === 'production' ? self::PRODUCTION_URL : self::PLAYGROUND_URL, $call['environment']->url);
        $this->assertSame($verification, $call['message']->verification);
        $this->assertSame($testIdentity, $call['identity']->test);
        $this->assertSame(0600, $call['keyMode']);

        if ($testIdentity) {
            $playground = PlaygroundIdentity::forType('cz.eet2');
            $this->assertSame('CZ00000019', $call['message']->taxpayerId, 've zprávě jde EIČ testovacího certifikátu, ne registrace');
            $this->assertSame('CZ00000019', $call['identity']->taxpayerId);
            $this->assertSame($playground->keyPem(), $call['key']);
            $this->assertNotSame($this->pair['keyPem'], $call['key'], 'certifikát zdroje dat na playground nejde nikdy');
            $this->assertSame($playground->certificatePem(), $call['certificate']);
        } else {
            $this->assertSame(self::REGISTRATION_EIC, $call['message']->taxpayerId);
            $this->assertSame($this->pair['keyPem'], $call['key'], 'klíč registrace dešifrovaný až pro odeslání');
            $this->assertSame($this->pair['certificatePem'], $call['certificate']);
        }

        $this->assertSame($verification ? SendStatus::Verified : SendStatus::Confirmed, $result->status);
        $this->assertSame($environment, $result->environment);
        $this->assertSame($testIdentity, $result->testIdentity);
        $this->assertSame($verification, $result->verification);
        $this->assertSame([], $this->temporaryFiles(), 'dočasný soubor klíče po odeslání neexistuje');
    }

    public function testMessageKeepsItsDataAndUuid(): void
    {
        $engine = $this->recordingEngine();
        $message = $this->message();

        $this->sender('off', $engine)->send($this->registration('production'), $message);

        $sent = $engine->calls[0]['message'];
        $this->assertSame($message->uuid, $sent->uuid);
        $this->assertSame(['181', 'POKL-1', '2027/0001', '1250.00', 'czk', true], [
            $sent->unitId, $sent->cashRegisterId, $sent->serialNumber, $sent->amount, $sent->currency, $sent->firstSending,
        ]);
        $this->assertEquals($message->soldAt, $sent->soldAt);
    }

    /** @return array<string, array{string}> */
    public static function registrationModes(): array
    {
        return ['production' => ['production'], 'verification' => ['verification'], 'playground' => ['playground']];
    }

    #[DataProvider('registrationModes')]
    public function testDropSendsNothing(string $mode): void
    {
        $engine = $this->recordingEngine();

        $result = $this->sender('drop', $engine)->send($this->registration($mode), $this->message());

        $this->assertSame(SendStatus::Dropped, $result->status);
        $this->assertFalse($result->sent);
        $this->assertSame(SendResult::PROBLEM_SAFETY, $result->problem);
        $this->assertSame([], $engine->calls, 'engine se nevolá, síť se nepoužije');
        $this->assertDirectoryDoesNotExist($this->dataSourceDir . '/' . KeyFiles::DIRECTORY, 'klíč se ani nedešifruje do souboru');
        $this->assertStringContainsString('"status":"dropped"', (string) file_get_contents($this->logFile));
    }

    public function testRegistrationWithoutCertificateIsNotSentToProduction(): void
    {
        $engine = $this->recordingEngine();
        $registration = $this->registration('production', ['cert_pem' => null, 'cert_key' => null, 'taxpayer_id' => null]);

        $result = $this->sender('off', $engine)->send($registration, $this->message());

        $this->assertSame(SendStatus::Rejected, $result->status);
        $this->assertFalse($result->sent);
        $this->assertSame(SendResult::PROBLEM_NO_CERTIFICATE, $result->problem);
        $this->assertSame('production', $result->environment);
        $this->assertSame([], $engine->calls);
    }

    public function testRegistrationWithoutCertificateStillReachesThePlayground(): void
    {
        $engine = $this->recordingEngine();
        $registration = $this->registration('production', ['cert_pem' => null, 'cert_key' => null, 'taxpayer_id' => null]);

        $result = $this->sender('playground', $engine)->send($registration, $this->message(true));

        $this->assertSame(SendStatus::Verified, $result->status);
        $this->assertTrue($result->testIdentity);
    }

    public function testKeyThatCannotBeDecryptedIsNotSent(): void
    {
        $engine = $this->recordingEngine();

        $result = $this->sender('off', $engine)->send($this->registration('production', ['cert_key' => 'v1:broken']), $this->message());

        $this->assertSame(SendStatus::Rejected, $result->status);
        $this->assertSame(SendResult::PROBLEM_KEY, $result->problem);
        $this->assertSame([], $engine->calls);
        $this->assertSame([], $this->temporaryFiles());
    }

    public function testUnknownTypeOrMissingEngineIsNotSent(): void
    {
        $engine = $this->recordingEngine();

        $unknown = $this->sender('off', $engine)->send($this->registration('production', ['type' => 'xx.gone']), $this->message());
        $this->assertSame(SendResult::PROBLEM_TYPE, $unknown->problem);

        $noEngine = $this->sender('off', $engine, engineAvailable: false)->send($this->registration('production'), $this->message());
        $this->assertSame(SendResult::PROBLEM_TYPE, $noEngine->problem);
        $this->assertSame(SendStatus::Rejected, $noEngine->status);
        $this->assertSame([], $engine->calls);
    }

    public function testTypeWithoutTheTargetEnvironmentIsNotSent(): void
    {
        $types = self::repoJsonc('modules/world/cz/config/salesRegistrationTypes.jsonc');
        unset($types['cz.eet2']['environments']['playground']);
        $engine = $this->recordingEngine();

        $result = $this->sender('playground', $engine, ['world.cz.salesRegistrationTypes' => $types])
            ->send($this->registration('production'), $this->message());

        $this->assertSame(SendResult::PROBLEM_ENVIRONMENT, $result->problem);
        $this->assertSame([], $engine->calls);
    }

    public function testTemporaryKeyFileIsRemovedEvenWhenTheEngineThrows(): void
    {
        $engine = $this->recordingEngine(new \LogicException('engine broke its contract'));

        $result = $this->sender('off', $engine)->send($this->registration('production'), $this->message());

        $this->assertCount(1, $engine->calls, 'engine klíč dostal');
        $this->assertSame($this->pair['keyPem'], $engine->calls[0]['key']);
        $this->assertSame([], $this->temporaryFiles(), 'po výjimce soubor klíče neexistuje');
        $this->assertSame(SendStatus::Rejected, $result->status);
        $this->assertSame(SendResult::PROBLEM_INTERNAL, $result->problem);
        $this->assertFalse($result->sent);
    }

    public function testLogHasTheOutcomeButNeitherKeyNorBodies(): void
    {
        $message = $this->message();

        $this->sender('off', $this->recordingEngine())->send($this->registration('production'), $message);

        $log = (string) file_get_contents($this->logFile);
        foreach (['"registration":7', '"type":"cz.eet2"', '"environment":"production"', '"status":"confirmed"', '"confirmation":"pok-123"', '"transactionId":"tx-9"', '"durationMs":120', $message->uuid] as $expected) {
            $this->assertStringContainsString($expected, $log);
        }
        foreach (['PRIVATE KEY', 'REQUEST BODY', 'RESPONSE BODY', 'BEGIN CERTIFICATE', 'v1:'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $log);
        }
    }
}
