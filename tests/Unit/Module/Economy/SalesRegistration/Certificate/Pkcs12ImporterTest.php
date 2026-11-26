<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Certificate;

use PHPUnit\Framework\TestCase;
use Shipard\Core\DevUpdate\UpdateEnvironment;
use Shipard\Module\Economy\SalesRegistration\Certificate\CertificateImportException;
use Shipard\Module\Economy\SalesRegistration\Certificate\LegacyPkcs12Reader;
use Shipard\Module\Economy\SalesRegistration\Certificate\Pkcs12Importer;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationType;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;

/**
 * Převzetí certifikátu z `.p12` (#171 D11): testovací `.p12` playgroundu
 * (zastaralá šifra → záložní cesta), moderní `.p12`, špatné heslo, soubor,
 * který není certifikát, cizí klíč, CN mimo masku typu, vypršelý certifikát.
 */
class Pkcs12ImporterTest extends TestCase
{
    use SalesRegistrationConfig;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    private function type(): SalesRegistrationType
    {
        return SalesRegistrationTypes::fromConfig($this->salesRegistrationConfig())->get('cz.eet2');
    }

    private function file(string $content): string
    {
        return $this->tempFiles[] = TestCertificates::tempFile($content);
    }

    /** Záložní čtečka, která si pamatuje, kolikrát byla zavolána. */
    private function countingReader(): LegacyPkcs12Reader
    {
        return new class extends LegacyPkcs12Reader {
            public int $calls = 0;

            public function read(string $path, #[\SensitiveParameter] string $password): array
            {
                $this->calls++;
                return parent::read($path, $password);
            }
        };
    }

    /** Záložní čtečka s pevnou odpovědí. */
    private function fixedReader(string $outcome, string $pem = ''): LegacyPkcs12Reader
    {
        return new class($outcome, $pem) extends LegacyPkcs12Reader {
            public function __construct(private readonly string $outcome, private readonly string $pem)
            {
            }

            public function read(string $path, #[\SensitiveParameter] string $password): array
            {
                return ['outcome' => $this->outcome, 'pem' => $this->pem];
            }
        };
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(TestCertificates::PLAYGROUND_NOW);
    }

    private function requireOpenssl(): void
    {
        if (UpdateEnvironment::findTool('openssl') === null) {
            $this->markTestSkipped('Program openssl není na tomto stroji.');
        }
    }

    /** Čte PHP testovací `.p12` samo (zapnutý legacy provider)? */
    private function phpReadsPlaygroundFile(): bool
    {
        return @openssl_pkcs12_read((string) file_get_contents(TestCertificates::PLAYGROUND_P12), $parts, TestCertificates::PLAYGROUND_PASSWORD);
    }

    private function assertImportFails(string $kind, callable $import): CertificateImportException
    {
        try {
            $import();
        } catch (CertificateImportException $e) {
            $this->assertSame($kind, $e->kind, $e->getMessage());
            return $e;
        }
        $this->fail("Očekávaná chyba převzetí certifikátu: {$kind}");
    }

    public function testImportsThePlaygroundCertificateThroughTheLegacyFallback(): void
    {
        $this->requireOpenssl();
        $reader = $this->countingReader();

        $certificate = (new Pkcs12Importer($reader))->import(
            TestCertificates::PLAYGROUND_P12,
            TestCertificates::PLAYGROUND_PASSWORD,
            $this->type(),
            $this->now(),
        );

        $this->assertSame(TestCertificates::PLAYGROUND_EIC, $certificate->taxpayerId);
        $this->assertSame('2027-05-14', $certificate->validTo->format('Y-m-d'));
        $this->assertSame('2026-05-13', $certificate->validFrom->format('Y-m-d'));
        $this->assertSame('15584C0A86BB6E65CC5C7097792CB26481A9A6AC', $certificate->serial);
        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $certificate->keyPem);
        $this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', $certificate->certificatePem);
        $this->assertTrue(openssl_x509_check_private_key($certificate->certificatePem, $certificate->keyPem));

        if (!$this->phpReadsPlaygroundFile()) {
            $this->assertSame(1, $reader->calls, 'šifru RC2-40 PHP na OpenSSL 3 nepřečte — převod jde záložní cestou');
        }
    }

    public function testImportsAModernFileWithoutTheFallback(): void
    {
        $reader = $this->countingReader();
        $path = $this->file(TestCertificates::modernP12('CZ12345678', 'tajné heslo'));

        $certificate = (new Pkcs12Importer($reader))->import($path, 'tajné heslo', $this->type());

        $this->assertSame('CZ12345678', $certificate->taxpayerId);
        $this->assertSame(0, $reader->calls, 'moderní soubor čte PHP samo');
        $this->assertTrue(openssl_x509_check_private_key($certificate->certificatePem, $certificate->keyPem));
        $this->assertNotSame('', $certificate->serial);
        $this->assertGreaterThan(new \DateTimeImmutable(), $certificate->validTo);
    }

    public function testWrongPasswordOfAModernFile(): void
    {
        $reader = $this->countingReader();
        $path = $this->file(TestCertificates::modernP12('CZ12345678', 'tajné heslo'));

        $e = $this->assertImportFails(
            CertificateImportException::WRONG_PASSWORD,
            fn() => (new Pkcs12Importer($reader))->import($path, 'jiné heslo', $this->type()),
        );

        $this->assertSame(0, $reader->calls, 'špatné heslo pozná PHP — záložní čtení se nespouští');
        $this->assertStringNotContainsString('jiné heslo', $e->getMessage());
    }

    public function testWrongPasswordOfTheLegacyFile(): void
    {
        $this->assertImportFails(
            CertificateImportException::WRONG_PASSWORD,
            fn() => (new Pkcs12Importer())->import(TestCertificates::PLAYGROUND_P12, 'spatne', $this->type(), $this->now()),
        );
    }

    public function testWrongPasswordReportedByTheFallback(): void
    {
        $path = $this->file('not a pkcs12 file');

        $this->assertImportFails(
            CertificateImportException::WRONG_PASSWORD,
            fn() => (new Pkcs12Importer($this->fixedReader(LegacyPkcs12Reader::OUTCOME_WRONG_PASSWORD)))->import($path, 'x', $this->type()),
        );
    }

    public function testFileThatIsNotACertificate(): void
    {
        $this->requireOpenssl();

        foreach (['not a pkcs12 file at all', "\x30\x82\x00\x01garbage", '<html></html>'] as $content) {
            $this->assertImportFails(
                CertificateImportException::NOT_A_CERTIFICATE,
                fn() => (new Pkcs12Importer())->import($this->file($content), 'heslo', $this->type()),
            );
        }
    }

    public function testEmptyMissingOrOversizedFile(): void
    {
        $importer = new Pkcs12Importer($this->fixedReader(LegacyPkcs12Reader::OUTCOME_OK, 'unused'));

        $this->assertImportFails(CertificateImportException::NOT_A_CERTIFICATE, fn() => $importer->import($this->file(''), 'x', $this->type()));
        $this->assertImportFails(CertificateImportException::NOT_A_CERTIFICATE, fn() => $importer->import('/nonexistent/file.p12', 'x', $this->type()));
        $this->assertImportFails(
            CertificateImportException::NOT_A_CERTIFICATE,
            fn() => $importer->import($this->file(str_repeat('A', Pkcs12Importer::MAX_BYTES + 1)), 'x', $this->type()),
        );
    }

    public function testLegacyCipherWithoutTheOpensslProgram(): void
    {
        if ($this->phpReadsPlaygroundFile()) {
            $this->markTestSkipped('PHP na tomto stroji čte zastaralou šifru samo.');
        }

        $this->assertImportFails(
            CertificateImportException::LEGACY_UNAVAILABLE,
            fn() => (new Pkcs12Importer($this->fixedReader(LegacyPkcs12Reader::OUTCOME_UNAVAILABLE)))->import(
                TestCertificates::PLAYGROUND_P12,
                TestCertificates::PLAYGROUND_PASSWORD,
                $this->type(),
                $this->now(),
            ),
        );
    }

    public function testForeignKeyDoesNotBelongToTheCertificate(): void
    {
        // `.p12` s cizím klíčem PHP ani openssl nevyrobí — pár dodá záložní čtečka.
        $certificate = TestCertificates::selfSigned('CZ12345678');
        $foreign = TestCertificates::selfSigned('CZ12345678');
        $reader = $this->fixedReader(LegacyPkcs12Reader::OUTCOME_OK, $foreign['keyPem'] . $certificate['certificatePem']);

        $this->assertImportFails(
            CertificateImportException::KEY_MISMATCH,
            fn() => (new Pkcs12Importer($reader))->import($this->file('not a pkcs12 file'), 'x', $this->type()),
        );
    }

    public function testFallbackPicksTheCertificateThatBelongsToTheKey(): void
    {
        // Výstup `openssl pkcs12` smí nést i další certifikáty (řetěz) a atributy.
        $own = TestCertificates::selfSigned('CZ12345678');
        $other = TestCertificates::selfSigned('CZ87654321');
        $pem = "Bag Attributes\n    localKeyID: AF BD\n" . $other['certificatePem']
            . "Bag Attributes\nsubject=CN = CZ12345678\n" . $own['certificatePem']
            . "Key Attributes: <No Attributes>\n" . $own['keyPem'];
        $reader = $this->fixedReader(LegacyPkcs12Reader::OUTCOME_OK, $pem);

        $imported = (new Pkcs12Importer($reader))->import($this->file('not a pkcs12 file'), 'x', $this->type());

        $this->assertSame('CZ12345678', $imported->taxpayerId);
    }

    public function testFallbackOutputWithoutKeyOrCertificate(): void
    {
        $pair = TestCertificates::selfSigned('CZ12345678');

        foreach ([$pair['certificatePem'], $pair['keyPem']] as $pem) {
            $this->assertImportFails(
                CertificateImportException::NOT_A_CERTIFICATE,
                fn() => (new Pkcs12Importer($this->fixedReader(LegacyPkcs12Reader::OUTCOME_OK, $pem)))
                    ->import($this->file('not a pkcs12 file'), 'x', $this->type()),
            );
        }
    }

    public function testCommonNameOutsideTheMaskOfTheType(): void
    {
        foreach (['Jan Novák', '00000019', 'cz00000019', 'CZ1'] as $commonName) {
            $path = $this->file(TestCertificates::modernP12($commonName, 'heslo'));

            $e = $this->assertImportFails(
                CertificateImportException::NOT_FOR_TYPE,
                fn() => (new Pkcs12Importer())->import($path, 'heslo', $this->type()),
            );
            $this->assertSame($commonName, $e->params['commonName']);
        }
    }

    public function testExpiredAndNotYetValidCertificate(): void
    {
        $path = $this->file(TestCertificates::modernP12('CZ12345678', 'heslo', days: 30));
        $importer = new Pkcs12Importer();

        $expired = $this->assertImportFails(
            CertificateImportException::EXPIRED,
            fn() => $importer->import($path, 'heslo', $this->type(), new \DateTimeImmutable('+31 days')),
        );
        $this->assertSame('CZ12345678', $expired->params['taxpayerId']);
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, (string) $expired->params['validTo']));

        $this->assertImportFails(
            CertificateImportException::NOT_YET_VALID,
            fn() => $importer->import($path, 'heslo', $this->type(), new \DateTimeImmutable('-1 day')),
        );

        $this->assertSame('CZ12345678', $importer->import($path, 'heslo', $this->type(), new \DateTimeImmutable('+29 days'))->taxpayerId);
    }

    public function testExpiredPlaygroundCertificateIsRefused(): void
    {
        $this->requireOpenssl();

        $this->assertImportFails(
            CertificateImportException::EXPIRED,
            fn() => (new Pkcs12Importer())->import(
                TestCertificates::PLAYGROUND_P12,
                TestCertificates::PLAYGROUND_PASSWORD,
                $this->type(),
                new \DateTimeImmutable('2027-05-15 00:00:00'),
            ),
        );
    }

    public function testImportedCertificateDoesNotLeakTheKeyWhenDumped(): void
    {
        $path = $this->file(TestCertificates::modernP12('CZ12345678', 'heslo'));
        $certificate = (new Pkcs12Importer())->import($path, 'heslo', $this->type());

        $dump = print_r($certificate, true);
        $this->assertStringNotContainsString('PRIVATE KEY', $dump);
        $this->assertStringContainsString('CZ12345678', $dump);
    }
}
