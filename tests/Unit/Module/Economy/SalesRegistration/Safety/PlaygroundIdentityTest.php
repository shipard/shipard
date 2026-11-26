<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Safety;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\SalesRegistration\Safety\PlaygroundIdentity;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationType;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\Certificate\TestCertificates;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;

/**
 * Sdílená testovací identita playgroundu v repozitáři (#171 U2): úplná,
 * platná a s README o zdroji a obnově. Test platnosti je záměrně závislý na
 * dnešním datu — začne padat 30 dní před koncem platnosti certifikátu.
 */
class PlaygroundIdentityTest extends TestCase
{
    use SalesRegistrationConfig;

    private const RENEW_DAYS_AHEAD = 30;

    private function identity(): PlaygroundIdentity
    {
        $identity = PlaygroundIdentity::forType('cz.eet2');
        $this->assertNotNull($identity, 'testovací identita cz.eet2 v resources/playground chybí');
        return $identity;
    }

    public function testEveryMappedTypeHasItsFiles(): void
    {
        foreach (PlaygroundIdentity::FILES as $typeId => $name) {
            $this->assertNotNull(PlaygroundIdentity::forType($typeId), "{$typeId}: chybí {$name}.key nebo {$name}.pem");
        }
        $this->assertNull(PlaygroundIdentity::forType('xx.unknown'));
        $this->assertNull(PlaygroundIdentity::forType('cz.eet2', '/nonexistent'), 'chybějící soubory = identita není');
    }

    public function testKeyBelongsToTheCertificateAndIdentifierFitsTheType(): void
    {
        $identity = $this->identity();
        $type = SalesRegistrationTypes::fromConfig($this->salesRegistrationConfig())->get('cz.eet2');

        $this->assertInstanceOf(SalesRegistrationType::class, $type);
        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $identity->keyPem());
        $this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', $identity->certificatePem());
        $this->assertTrue(openssl_x509_check_private_key($identity->certificatePem(), $identity->keyPem()));
        $this->assertSame('CZ00000019', $identity->taxpayerId());
        $this->assertTrue($type->isValidTaxpayerId($identity->taxpayerId()));
        $this->assertSame(TestCertificates::PLAYGROUND_EIC, $identity->taxpayerId(), 'tatáž identita jako testovací .p12 importu');
    }

    public function testCertificateComesFromThePlaygroundAuthorityNotFromProduction(): void
    {
        $issuer = openssl_x509_parse($this->identity()->certificatePem())['issuer']['CN'] ?? '';

        $this->assertStringContainsStringIgnoringCase('playground', (string) $issuer, 'do repozitáře smí jen testovací certifikát playgroundu');
    }

    /**
     * Hlídač obnovy (U2): 30 dní před koncem platnosti začne padat — stáhni
     * nový balík a postupuj podle resources/playground/README.md.
     */
    public function testCertificateIsValidForAtLeast30Days(): void
    {
        $validTo = $this->identity()->validTo();
        $deadline = new \DateTimeImmutable('+' . self::RENEW_DAYS_AHEAD . ' days');

        $this->assertGreaterThan(
            $deadline,
            $validTo,
            sprintf(
                'Testovací certifikát playgroundu platí jen do %s — obnov ho podle modules/economy/salesRegistration/resources/playground/README.md.',
                $validTo->format('Y-m-d'),
            ),
        );
    }

    public function testReadmeSaysWhereTheIdentityComesFromAndHowToRenewIt(): void
    {
        $readme = (string) file_get_contents(PlaygroundIdentity::DIRECTORY . '/README.md');

        $this->assertStringContainsString('eet.gov.cz', $readme);
        $this->assertStringContainsString('openssl pkcs12 -legacy', $readme);
        $this->assertStringContainsString('## Obnova', $readme);
        $this->assertStringContainsString('TLP:AMBER', $readme, 'co do repozitáře nepatří');
        $this->assertStringContainsString($this->identity()->validTo()->format('j. n. Y'), $readme, 'README uvádí skutečný konec platnosti');
    }

    public function testNoOtherPrivateKeyLivesInTheModule(): void
    {
        $module = dirname(PlaygroundIdentity::DIRECTORY, 2);
        $keys = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($module, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (str_contains((string) file_get_contents($file->getPathname()), 'PRIVATE KEY-----' . "\n")) {
                $keys[] = substr($file->getPathname(), strlen($module) + 1);
            }
        }
        sort($keys);

        $this->assertSame(
            array_map(static fn(string $name): string => 'resources/playground/' . $name . '.key', array_values(PlaygroundIdentity::FILES)),
            $keys,
            'soukromý klíč v doplňku smí být jen veřejná testovací identita playgroundu',
        );
    }
}
