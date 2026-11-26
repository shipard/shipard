<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Actions;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\RecordAction\RecordActionContext;
use Shipard\Core\RecordAction\RecordActionField;
use Shipard\Core\RecordAction\RecordActionFile;
use Shipard\Core\RecordAction\RecordActionInput;
use Shipard\Core\RecordAction\RecordActionResult;
use Shipard\Core\RecordAction\RecordActionValidationException;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Module\Economy\SalesRegistration\Actions\UploadCertificateAction;
use Shipard\Module\Economy\SalesRegistration\Certificate\LegacyPkcs12Reader;
use Shipard\Module\Economy\SalesRegistration\Certificate\Pkcs12Importer;
use Shipard\Module\Economy\SalesRegistration\RegistrationDocument;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\Certificate\TestCertificates;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\TempDataSource;

/**
 * Akce „Nahrát certifikát“ (#171 D11): certifikát a šifrovaný klíč do
 * registrace, identifikátor a platnost z certifikátu, heslo nikde,
 * srozumitelné chyby.
 */
class UploadCertificateActionTest extends TestCase
{
    use SalesRegistrationConfig;
    use TempDataSource;

    private const PASSWORD = 'Velmi-Tajne-Heslo-7';

    /** @var list<string> */
    private array $tempFiles = [];
    private string $logFile;

    protected function setUp(): void
    {
        $this->createDataSourceDir();
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'shpd-salesreg-log-');
        ErrorLogger::resetForTesting();
        ErrorLogger::setLogPath($this->logFile);
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        @unlink($this->logFile);
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->removeDataSourceDir();
    }

    /** Akce, která místo databáze zaznamená, co by uložila. */
    private function action(?string $saveError = null, ?Pkcs12Importer $importer = null): UploadCertificateAction
    {
        return new class($saveError, $importer) extends UploadCertificateAction {
            /** @var list<array<string, mixed>> */
            public array $saved = [];

            public function __construct(private readonly ?string $saveError, private readonly ?Pkcs12Importer $testImporter)
            {
            }

            protected function save(RecordActionContext $context, array $data): ?string
            {
                $this->saved[] = $data;
                return $this->saveError;
            }

            protected function importer(): Pkcs12Importer
            {
                return $this->testImporter ?? parent::importer();
            }
        };
    }

    /** @param array<string, mixed> $configOverride */
    private function context(string $language = 'cs', ?int $userId = 5, array $configOverride = []): RecordActionContext
    {
        return new RecordActionContext(
            RegistrationDocument::TABLE,
            $this->createMock(DataSourceConnection::class),
            $this->salesRegistrationConfig($language === 'cs' ? $configOverride : $configOverride + [
                'economy.salesRegistration.texts' => null,
                'world.cz.salesRegistrationTypes' => self::repoJsonc('modules/world/cz/config/salesRegistrationTypes.jsonc', 'en'),
            ]),
            $this->dataSourceConfig(),
            $language,
            null,
            $userId,
            true,
        );
    }

    private function input(string $fileContent, string $password = self::PASSWORD): RecordActionInput
    {
        $path = $this->tempFiles[] = TestCertificates::tempFile($fileContent);
        return new RecordActionInput(
            [UploadCertificateAction::FIELD_PASSWORD => $password],
            [UploadCertificateAction::FIELD_FILE => new RecordActionFile($path, 'pokladna.p12', strlen($fileContent), 'application/octet-stream')],
        );
    }

    /** @return array<string, mixed> */
    private function record(array $override = []): array
    {
        return $override + ['id' => 7, 'type' => 'cz.eet2', 'name' => 'Prodejna', 'taxpayer_id' => null, 'cert_pem' => null, 'docState' => 40];
    }

    /** @return array<string, string> pole → hláška */
    private function failure(callable $execute): array
    {
        try {
            $execute();
        } catch (RecordActionValidationException $e) {
            return $e->fieldErrors();
        }
        $this->fail('Akce měla skončit chybou.');
    }

    public function testStoresCertificateWithEncryptedKeyAndIdentityFromTheCertificate(): void
    {
        $action = $this->action();
        $before = new \DateTimeImmutable('-2 seconds');

        $result = $action->execute(
            RegistrationDocument::TABLE,
            $this->record(),
            $this->input(TestCertificates::modernP12('CZ12345678', self::PASSWORD, days: 366)),
            $this->context(),
        );

        $this->assertSame(RecordActionResult::SUCCESS, $result->level);
        $this->assertMatchesRegularExpression('/^Certifikát nahrán — EIČ CZ12345678, platnost do \d{2}\.\d{2}\.\d{4}\.$/u', $result->message);

        $this->assertCount(1, $action->saved);
        $saved = $action->saved[0];
        $this->assertSame(7, $saved['id']);
        $this->assertSame('CZ12345678', $saved['taxpayer_id']);
        $this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', $saved['cert_pem']);
        $this->assertSame(5, $saved['cert_uploaded_by']);
        $this->assertGreaterThanOrEqual($before, new \DateTimeImmutable($saved['cert_uploaded_at']));
        $this->assertNotSame('', (string) $saved['cert_serial']);
        $this->assertGreaterThan(new \DateTimeImmutable('+360 days'), new \DateTimeImmutable($saved['cert_valid_to']));
        $this->assertLessThanOrEqual(new \DateTimeImmutable('+1 minute'), new \DateTimeImmutable($saved['cert_valid_from']));

        // Klíč jen šifrovaně — a dešifrovaný patří k uloženému certifikátu.
        $this->assertStringStartsWith('v1:', $saved['cert_key']);
        $this->assertStringNotContainsString('PRIVATE KEY', $saved['cert_key']);
        $key = DsSecretCipher::forConfig($this->dataSourceConfig())->decrypt($saved['cert_key']);
        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $key);
        $this->assertTrue(openssl_x509_check_private_key($saved['cert_pem'], $key));

        // Heslo se neukládá ani neloguje, klíč se neloguje.
        $this->assertArrayNotHasKey('password', $saved);
        $everything = json_encode($saved, JSON_THROW_ON_ERROR) . $result->message . file_get_contents($this->logFile);
        $this->assertStringNotContainsString(self::PASSWORD, $everything);
        $this->assertStringNotContainsString('PRIVATE KEY', (string) file_get_contents($this->logFile));
        $this->assertStringContainsString('certificate uploaded', (string) file_get_contents($this->logFile));
        $this->assertStringContainsString('CZ12345678', (string) file_get_contents($this->logFile));
    }

    public function testStoresThePlaygroundCertificateWithTheLegacyCipher(): void
    {
        if ((new \DateTimeImmutable()) > new \DateTimeImmutable('2027-05-14')) {
            $this->markTestSkipped('Testovací certifikát playgroundu vypršel — obnov ho podle README v resources/playground.');
        }
        $action = $this->action();
        $content = (string) file_get_contents(TestCertificates::PLAYGROUND_P12);
        if (!@openssl_pkcs12_read($content, $parts, TestCertificates::PLAYGROUND_PASSWORD)
            && (new LegacyPkcs12Reader())->read(TestCertificates::PLAYGROUND_P12, 'x')['outcome'] === LegacyPkcs12Reader::OUTCOME_UNAVAILABLE
        ) {
            $this->markTestSkipped('Program openssl není na tomto stroji.');
        }

        $result = $action->execute(
            RegistrationDocument::TABLE,
            $this->record(),
            $this->input($content, TestCertificates::PLAYGROUND_PASSWORD),
            $this->context(),
        );

        $this->assertSame('Certifikát nahrán — EIČ CZ00000019, platnost do 14.05.2027.', $result->message);
        $this->assertSame('CZ00000019', $action->saved[0]['taxpayer_id']);
        $this->assertStringStartsWith('v1:', $action->saved[0]['cert_key']);
    }

    public function testRenewalReplacesTheCertificateOfTheSameTaxpayer(): void
    {
        $action = $this->action();
        $record = $this->record(['taxpayer_id' => 'CZ12345678', 'cert_pem' => 'old certificate']);

        $action->execute(RegistrationDocument::TABLE, $record, $this->input(TestCertificates::modernP12('CZ12345678', self::PASSWORD)), $this->context());

        $this->assertCount(1, $action->saved);
        $this->assertNotSame('old certificate', $action->saved[0]['cert_pem']);
    }

    public function testCertificateOfAnotherTaxpayerIsRefused(): void
    {
        $action = $this->action();
        $record = $this->record(['taxpayer_id' => 'CZ87654321', 'cert_pem' => 'old certificate']);

        $errors = $this->failure(fn() => $action->execute(
            RegistrationDocument::TABLE,
            $record,
            $this->input(TestCertificates::modernP12('CZ12345678', self::PASSWORD)),
            $this->context(),
        ));

        $this->assertSame(
            [UploadCertificateAction::FIELD_FILE => 'Certifikát patří jinému poplatníkovi (CZ12345678) než tato registrace (CZ87654321).'],
            $errors,
        );
        $this->assertSame([], $action->saved, 'nic se neuloží');
    }

    public function testWrongPasswordIsAnErrorOfThePasswordField(): void
    {
        $action = $this->action();

        $errors = $this->failure(fn() => $action->execute(
            RegistrationDocument::TABLE,
            $this->record(),
            $this->input(TestCertificates::modernP12('CZ12345678', self::PASSWORD), 'jine-heslo'),
            $this->context(),
        ));

        $this->assertSame([UploadCertificateAction::FIELD_PASSWORD => 'Heslo k souboru certifikátu nesouhlasí.'], $errors);
        $this->assertSame([], $action->saved);
        $this->assertStringNotContainsString('jine-heslo', (string) file_get_contents($this->logFile));
    }

    public function testFileThatIsNotACertificate(): void
    {
        $importer = new Pkcs12Importer(new class extends LegacyPkcs12Reader {
            public function read(string $path, #[\SensitiveParameter] string $password): array
            {
                return ['outcome' => self::OUTCOME_UNREADABLE, 'pem' => ''];
            }
        });

        $errors = $this->failure(fn() => $this->action(importer: $importer)->execute(
            RegistrationDocument::TABLE,
            $this->record(),
            $this->input('just a text file renamed to .p12'),
            $this->context(),
        ));

        $this->assertSame([UploadCertificateAction::FIELD_FILE => 'Soubor není certifikát se soukromým klíčem (.p12).'], $errors);
    }

    public function testCertificateThatIsNotACashRegisterCertificateOfTheType(): void
    {
        $errors = $this->failure(fn() => $this->action()->execute(
            RegistrationDocument::TABLE,
            $this->record(),
            $this->input(TestCertificates::modernP12('server.example', self::PASSWORD)),
            $this->context(),
        ));

        $this->assertSame(
            [UploadCertificateAction::FIELD_FILE => 'Nejde o certifikát pro EET 2.0 (Česko): jeho jméno „server.example“ není platné EIČ.'],
            $errors,
        );
    }

    public function testLegacyCipherWithoutOpensslIsAFormError(): void
    {
        $importer = new Pkcs12Importer(new class extends LegacyPkcs12Reader {
            public function read(string $path, #[\SensitiveParameter] string $password): array
            {
                return ['outcome' => self::OUTCOME_UNAVAILABLE, 'pem' => ''];
            }
        });

        $errors = $this->failure(fn() => $this->action(importer: $importer)->execute(
            RegistrationDocument::TABLE,
            $this->record(),
            $this->input('not readable by php'),
            $this->context(),
        ));

        $this->assertSame([RecordActionValidationException::FORM], array_keys($errors));
        $this->assertStringContainsString('openssl', $errors[RecordActionValidationException::FORM]);
    }

    public function testValidationErrorOfTheDocumentIsShownToTheUser(): void
    {
        $action = $this->action(saveError: 'Pro poplatníka CZ12345678 už existuje jiná registrace tohoto typu evidence — nejdřív ji archivujte.');

        $errors = $this->failure(fn() => $action->execute(
            RegistrationDocument::TABLE,
            $this->record(),
            $this->input(TestCertificates::modernP12('CZ12345678', self::PASSWORD)),
            $this->context(),
        ));

        $this->assertSame([RecordActionValidationException::FORM], array_keys($errors));
        $this->assertStringContainsString('už existuje jiná registrace', $errors[RecordActionValidationException::FORM]);
    }

    public function testMissingFileAndUnknownType(): void
    {
        $action = $this->action();
        $noFile = new RecordActionInput([UploadCertificateAction::FIELD_PASSWORD => 'x']);

        $this->assertSame(
            [UploadCertificateAction::FIELD_FILE],
            array_keys($this->failure(fn() => $action->execute(RegistrationDocument::TABLE, $this->record(), $noFile, $this->context()))),
        );
        $this->assertSame(
            [RecordActionValidationException::FORM],
            array_keys($this->failure(fn() => $action->execute(
                RegistrationDocument::TABLE,
                $this->record(['type' => 'xx.gone']),
                $this->input('x'),
                $this->context(),
            ))),
        );
    }

    public function testIsAvailableOnLiveRegistrationsOfAKnownType(): void
    {
        $action = $this->action();
        $context = $this->context();

        foreach ([10, 40, 80] as $state) {
            $this->assertTrue($action->available(RegistrationDocument::TABLE, $this->record(['docState' => $state]), $context), "stav {$state}");
        }
        foreach ([70, 90] as $state) {
            $this->assertFalse($action->available(RegistrationDocument::TABLE, $this->record(['docState' => $state]), $context), "stav {$state}");
        }
        $this->assertFalse($action->available(RegistrationDocument::TABLE, $this->record(['type' => 'xx.gone']), $context));
    }

    public function testDialogAsksForFileAndPasswordAndSaysWhatHappens(): void
    {
        $descriptor = $this->action()->describe(RegistrationDocument::TABLE, $this->record(), $this->context());
        $array = $descriptor->toArray();

        $this->assertSame('Nahrát certifikát', $descriptor->label);
        $this->assertSame('primary', $descriptor->variant, 'registrace bez certifikátu — hlavní akce');
        $fields = array_column($descriptor->fields(), null, 'id');
        $this->assertSame([RecordActionField::NOTICE, RecordActionField::FILE, RecordActionField::PASSWORD], array_map(static fn($f) => $f->type, array_values($fields)));

        $json = json_encode($array, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('.p12,.pfx', $json);
        $this->assertStringContainsString((string) Pkcs12Importer::MAX_BYTES, $json);
        $this->assertStringContainsString('heslo se neukládá', $json);

        $renewal = $this->action()->describe(RegistrationDocument::TABLE, $this->record(['cert_pem' => 'x']), $this->context());
        $this->assertSame('secondary', $renewal->variant);
        $this->assertStringContainsString('nahradí dosavadní', json_encode($renewal->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function testEnglishFallbackWithoutTheTextCatalog(): void
    {
        $result = $this->action()->execute(
            RegistrationDocument::TABLE,
            $this->record(),
            $this->input(TestCertificates::modernP12('CZ12345678', self::PASSWORD)),
            $this->context('en'),
        );

        $this->assertMatchesRegularExpression('/^Certificate uploaded — EIC CZ12345678, valid until \d{4}-\d{2}-\d{2}\.$/', $result->message);
    }
}
