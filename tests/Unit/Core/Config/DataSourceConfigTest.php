<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\DataClass;
use Shipard\Core\Config\DataSourceConfig;

class DataSourceConfigTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shipard_ds_test_' . uniqid();
        mkdir($this->tempDir . '/config', 0755, true);
    }

    protected function tearDown(): void
    {
        $configFile = $this->tempDir . '/config/main.json';
        if (file_exists($configFile)) {
            unlink($configFile);
        }
        rmdir($this->tempDir . '/config');
        rmdir($this->tempDir);
    }

    private function createConfig(array $data): void
    {
        file_put_contents($this->tempDir . '/config/main.json', json_encode($data));
    }

    public function testLoadValidConfig(): void
    {
        $this->createConfig([
            'id'                => 'abcd-efgh-ijkl-mnop',
            'name'              => 'Test DS',
            'database_name'     => 'abcd_efgh_ijkl_mnop',
            'database_user'     => 'shpd_abcdefgh',
            'database_password' => 'supersecret',
            'created'           => '2026-03-12T10:00:00+01:00',
        ]);

        $config = new DataSourceConfig($this->tempDir);

        $this->assertSame('abcd-efgh-ijkl-mnop', $config->getId());
        $this->assertSame('Test DS', $config->getName());
        $this->assertSame('abcd_efgh_ijkl_mnop', $config->getDatabaseName());
        $this->assertSame('shpd_abcdefgh', $config->getDatabaseUser());
        $this->assertSame('supersecret', $config->getDatabasePassword());
        $this->assertSame('2026-03-12T10:00:00+01:00', $config->getCreated());
    }

    public function testMissingConfigFileThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');

        new DataSourceConfig($this->tempDir . '/nonexistent');
    }

    public function testDefaultLanguageDefaultsToEnglish(): void
    {
        $this->createConfig([
            'id'                => 'abcd-efgh-ijkl-mnop',
            'name'              => 'Test DS',
            'database_name'     => 'abcd_efgh_ijkl_mnop',
            'database_user'     => 'shpd_abcdefgh',
            'database_password' => 'supersecret',
            'created'           => '2026-03-12T10:00:00+01:00',
        ]);

        $config = new DataSourceConfig($this->tempDir);

        $this->assertSame('en', $config->getDefaultLanguage());
    }

    public function testDefaultLanguageReadsFromConfig(): void
    {
        $this->createConfig([
            'id'                => 'abcd-efgh-ijkl-mnop',
            'name'              => 'Test DS',
            'database_name'     => 'abcd_efgh_ijkl_mnop',
            'database_user'     => 'shpd_abcdefgh',
            'database_password' => 'supersecret',
            'created'           => '2026-03-12T10:00:00+01:00',
            'defaultLanguage'   => 'cs',
        ]);

        $config = new DataSourceConfig($this->tempDir);

        $this->assertSame('cs', $config->getDefaultLanguage());
    }

    public function testCountryReadsFromConfig(): void
    {
        $this->createConfig([
            'id'                => 'abcd-efgh-ijkl-mnop',
            'name'              => 'Test DS',
            'database_name'     => 'abcd_efgh_ijkl_mnop',
            'database_user'     => 'shpd_abcdefgh',
            'database_password' => 'supersecret',
            'created'           => '2026-03-12T10:00:00+01:00',
            'country'           => 'sk',
        ]);

        $config = new DataSourceConfig($this->tempDir);

        $this->assertSame('sk', $config->getCountry());
        $this->assertTrue($config->hasCountry());
    }

    public function testCountryMissingFallsBackToCz(): void
    {
        $this->createConfig([
            'id'                => 'abcd-efgh-ijkl-mnop',
            'name'              => 'Test DS',
            'database_name'     => 'abcd_efgh_ijkl_mnop',
            'database_user'     => 'shpd_abcdefgh',
            'database_password' => 'supersecret',
            'created'           => '2026-03-12T10:00:00+01:00',
        ]);

        $config = new DataSourceConfig($this->tempDir);

        $this->assertSame('cz', $config->getCountry());
        $this->assertFalse($config->hasCountry());
    }

    public function testCountryEmptyStringFallsBackToCz(): void
    {
        $this->createConfig([
            'id'                => 'abcd-efgh-ijkl-mnop',
            'name'              => 'Test DS',
            'database_name'     => 'abcd_efgh_ijkl_mnop',
            'database_user'     => 'shpd_abcdefgh',
            'database_password' => 'supersecret',
            'created'           => '2026-03-12T10:00:00+01:00',
            'country'           => '',
        ]);

        $config = new DataSourceConfig($this->tempDir);

        $this->assertSame('cz', $config->getCountry());
        $this->assertFalse($config->hasCountry());
    }

    public function testShouldSkipProvisioningDefaultsToFalse(): void
    {
        $this->createConfig([
            'id'                => 'abcd-efgh-ijkl-mnop',
            'name'              => 'Test DS',
            'database_name'     => 'abcd_efgh_ijkl_mnop',
            'database_user'     => 'shpd_abcdefgh',
            'database_password' => 'supersecret',
            'created'           => '2026-03-12T10:00:00+01:00',
        ]);

        $config = new DataSourceConfig($this->tempDir);

        $this->assertFalse($config->shouldSkipProvisioning());
    }

    public function testShouldSkipProvisioningReadsTrueFromConfig(): void
    {
        $this->createConfig([
            'id'                => 'abcd-efgh-ijkl-mnop',
            'name'              => 'Test DS',
            'database_name'     => 'abcd_efgh_ijkl_mnop',
            'database_user'     => 'shpd_abcdefgh',
            'database_password' => 'supersecret',
            'created'           => '2026-03-12T10:00:00+01:00',
            'skipProvisioning'  => true,
        ]);

        $config = new DataSourceConfig($this->tempDir);

        $this->assertTrue($config->shouldSkipProvisioning());
    }

    public function testShouldSkipProvisioningReadsFalseFromConfig(): void
    {
        $this->createConfig([
            'id'                => 'abcd-efgh-ijkl-mnop',
            'name'              => 'Test DS',
            'database_name'     => 'abcd_efgh_ijkl_mnop',
            'database_user'     => 'shpd_abcdefgh',
            'database_password' => 'supersecret',
            'created'           => '2026-03-12T10:00:00+01:00',
            'skipProvisioning'  => false,
        ]);

        $config = new DataSourceConfig($this->tempDir);

        $this->assertFalse($config->shouldSkipProvisioning());
    }

    public function testAllowsResetDefaultsToFalse(): void
    {
        $this->createConfig([
            'id'                => 'abcd-efgh-ijkl-mnop',
            'name'              => 'Test DS',
            'database_name'     => 'abcd_efgh_ijkl_mnop',
            'database_user'     => 'shpd_abcdefgh',
            'database_password' => 'supersecret',
            'created'           => '2026-03-12T10:00:00+01:00',
        ]);

        $config = new DataSourceConfig($this->tempDir);

        $this->assertFalse($config->allowsReset());
    }

    public function testAllowsResetReadsTrueFromConfig(): void
    {
        $this->createConfig([
            'id'                => 'abcd-efgh-ijkl-mnop',
            'name'              => 'Test DS',
            'database_name'     => 'abcd_efgh_ijkl_mnop',
            'database_user'     => 'shpd_abcdefgh',
            'database_password' => 'supersecret',
            'created'           => '2026-03-12T10:00:00+01:00',
            'enableReset'       => true,
        ]);

        $config = new DataSourceConfig($this->tempDir);

        $this->assertTrue($config->allowsReset());
    }

    /** @return array<string, mixed> */
    private function baseData(): array
    {
        return [
            'id'                => 'abcd-efgh-ijkl-mnop',
            'name'              => 'Test DS',
            'database_name'     => 'abcd_efgh_ijkl_mnop',
            'database_user'     => 'shpd_abcdefgh',
            'database_password' => 'supersecret',
            'created'           => '2026-03-12T10:00:00+01:00',
        ];
    }

    public function testDataClassDefaultsToRealCopyWithoutBeingExplicit(): void
    {
        // #126 D6: chybějící třída = realCopy (fail-safe), bez účelu, původu a času.
        $this->createConfig($this->baseData());
        $config = new DataSourceConfig($this->tempDir);

        $this->assertSame(DataClass::RealCopy, $config->getDataClass());
        $this->assertFalse($config->isFree());
        $this->assertFalse($config->hasExplicitDataClass());
        $this->assertNull($config->getDataClassRaw());
        $this->assertNull($config->getPurpose());
        $this->assertNull($config->getOrigin());
        $this->assertNull($config->getDataClassChanged());
    }

    public function testDataClassFreeReadsPurposeOriginAndChanged(): void
    {
        $this->createConfig($this->baseData() + [
            'dataClass'        => 'free',
            'purpose'          => '  Ruční zkoušení UI  ',
            'origin'           => 'dataset-seed cz-vat-payer',
            'dataClassChanged' => '2026-10-16T12:00:00Z',
        ]);
        $config = new DataSourceConfig($this->tempDir);

        $this->assertSame(DataClass::Free, $config->getDataClass());
        $this->assertTrue($config->isFree());
        $this->assertTrue($config->hasExplicitDataClass());
        $this->assertSame('free', $config->getDataClassRaw());
        $this->assertSame('Ruční zkoušení UI', $config->getPurpose(), 'účel se trimuje');
        $this->assertSame('dataset-seed cz-vat-payer', $config->getOrigin());
        $this->assertSame('2026-10-16T12:00:00Z', $config->getDataClassChanged());
    }

    public function testExplicitRealCopyIsExplicit(): void
    {
        $this->createConfig($this->baseData() + ['dataClass' => 'realCopy']);
        $config = new DataSourceConfig($this->tempDir);

        $this->assertSame(DataClass::RealCopy, $config->getDataClass());
        $this->assertTrue($config->hasExplicitDataClass());
    }

    #[DataProvider('invalidDataClassValues')]
    public function testInvalidDataClassCountsAsRealCopyAndIsNotExplicit(mixed $raw): void
    {
        // U6: neplatná hodnota zdroj dat nezavírá — platí realCopy, třída není explicitní,
        // surová hodnota zůstává k ohlášení (`ds-class`, `ds-list`).
        $this->createConfig($this->baseData() + ['dataClass' => $raw]);
        $config = new DataSourceConfig($this->tempDir);

        $this->assertSame(DataClass::RealCopy, $config->getDataClass());
        $this->assertFalse($config->isFree());
        $this->assertFalse($config->hasExplicitDataClass());
        $this->assertSame($raw, $config->getDataClassRaw());
    }

    /** @return array<string, array{mixed}> */
    public static function invalidDataClassValues(): array
    {
        return [
            'Free s velkým písmenem' => ['Free'],
            'REALCOPY'               => ['REALCOPY'],
            'true'                   => [true],
            'null'                   => [null],
            'číslo'                  => [1],
            'pole'                   => [['free']],
        ];
    }

    public function testEmptyOrNonStringPurposeOriginAndChangedAreNull(): void
    {
        $this->createConfig($this->baseData() + [
            'dataClass'        => 'free',
            'purpose'          => '   ',
            'origin'           => '',
            'dataClassChanged' => 20261016,
        ]);
        $config = new DataSourceConfig($this->tempDir);

        $this->assertNull($config->getPurpose());
        $this->assertNull($config->getOrigin());
        $this->assertNull($config->getDataClassChanged());
    }

    public function testHasLegacyServerAiFlagReadsTheAiSectionOnly(): void
    {
        // #126 D9: zaniklý příznak se čte jen pro varování — jen přesně `true`.
        $this->createConfig($this->baseData());
        $this->assertFalse((new DataSourceConfig($this->tempDir))->hasLegacyServerAiFlag());

        $this->createConfig($this->baseData() + ['ai' => ['useServerConnection' => true]]);
        $config = new DataSourceConfig($this->tempDir);
        $this->assertTrue($config->hasLegacyServerAiFlag());
        $this->assertFalse($config->isFree(), 'příznak třídu neovlivňuje');

        $this->createConfig($this->baseData() + ['ai' => ['useServerConnection' => 'yes']]);
        $this->assertFalse((new DataSourceConfig($this->tempDir))->hasLegacyServerAiFlag());
    }

    public function testMissingRequiredFieldThrowsException(): void
    {
        $this->createConfig([
            'id'   => 'abcd-efgh-ijkl-mnop',
            'name' => 'Test DS',
            // missing other required fields
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing required/i');

        new DataSourceConfig($this->tempDir);
    }

    public function testGetMailRelayMissingReturnsNull(): void
    {
        $this->createConfig([
            'id'                => 'abcd-efgh-ijkl-mnop',
            'name'              => 'Test DS',
            'database_name'     => 'abcd_efgh_ijkl_mnop',
            'database_user'     => 'shpd_abcdefgh',
            'database_password' => 'supersecret',
            'created'           => '2026-03-12T10:00:00+01:00',
        ]);

        $config = new DataSourceConfig($this->tempDir);

        $this->assertNull($config->getMailRelay());
        // druhé čtení jde přes parsed-cache
        $this->assertNull($config->getMailRelay());
    }

    public function testGetMailRelayOverride(): void
    {
        $this->createConfig([
            'id'                => 'abcd-efgh-ijkl-mnop',
            'name'              => 'Test DS',
            'database_name'     => 'abcd_efgh_ijkl_mnop',
            'database_user'     => 'shpd_abcdefgh',
            'database_password' => 'supersecret',
            'created'           => '2026-03-12T10:00:00+01:00',
            'mail'              => [
                'relay' => [
                    'host'     => 'smtp.customer.example',
                    'port'     => 465,
                    'security' => 'tls',
                    'username' => 'ds-mailer',
                    'password' => 'pw',
                ],
            ],
        ]);

        $config = new DataSourceConfig($this->tempDir);
        $relay  = $config->getMailRelay();

        $this->assertNotNull($relay);
        $this->assertSame('smtp.customer.example', $relay->host);
        $this->assertSame(465, $relay->port);
        $this->assertSame('tls', $relay->security);
        $this->assertSame($relay, $config->getMailRelay());
    }

    public function testGetMailRelayInvalidThrowsLazily(): void
    {
        $this->createConfig([
            'id'                => 'abcd-efgh-ijkl-mnop',
            'name'              => 'Test DS',
            'database_name'     => 'abcd_efgh_ijkl_mnop',
            'database_user'     => 'shpd_abcdefgh',
            'database_password' => 'supersecret',
            'created'           => '2026-03-12T10:00:00+01:00',
            'mail'              => ['relay' => ['security' => 'bogus']],
        ]);

        // load nesmí spadnout — validace až při prvním použití
        $config = new DataSourceConfig($this->tempDir);

        $this->expectException(\RuntimeException::class);
        $config->getMailRelay();
    }
}
