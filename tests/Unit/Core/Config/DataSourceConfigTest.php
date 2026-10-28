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
        $secretsDir = $this->tempDir . '/secrets';
        if (is_dir($secretsDir)) {
            @chmod($secretsDir, 0700);
            foreach (glob($secretsDir . '/*') ?: [] as $f) {
                @chmod($f, 0600);
                @unlink($f);
            }
            @rmdir($secretsDir);
        }
        rmdir($this->tempDir);
    }

    private function createConfig(array $data): void
    {
        file_put_contents($this->tempDir . '/config/main.json', json_encode($data));
    }

    /** Soubor tajemství v `secrets/` — hodnoty v testech jsou zjevně falešné. */
    private function writeSecret(string $name, string $raw): void
    {
        if (!is_dir($this->tempDir . '/secrets')) {
            mkdir($this->tempDir . '/secrets', 0700);
        }
        file_put_contents($this->tempDir . '/secrets/' . $name, $raw);
    }

    /** @return array<string, mixed> main.json bez tajemství (po migraci #126 B1) */
    private function baseDataWithoutSecrets(): array
    {
        $data = $this->baseData();
        unset($data['database_password']);
        return $data;
    }

    public function testLoadValidConfig(): void
    {
        $this->createConfig([
            'id'            => 'abcd-efgh-ijkl-mnop',
            'name'          => 'Test DS',
            'database_name' => 'abcd_efgh_ijkl_mnop',
            'database_user' => 'shpd_abcdefgh',
            'created'       => '2026-03-12T10:00:00+01:00',
        ]);
        $this->writeSecret('db.password', 'db-pw-fake');

        $config = new DataSourceConfig($this->tempDir);

        $this->assertSame('abcd-efgh-ijkl-mnop', $config->getId());
        $this->assertSame('Test DS', $config->getName());
        $this->assertSame('abcd_efgh_ijkl_mnop', $config->getDatabaseName());
        $this->assertSame('shpd_abcdefgh', $config->getDatabaseUser());
        $this->assertSame('db-pw-fake', $config->getDatabasePassword());
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
            'id'            => 'abcd-efgh-ijkl-mnop',
            'name'          => 'Test DS',
            'database_name' => 'abcd_efgh_ijkl_mnop',
            'database_user' => 'shpd_abcdefgh',
            'created'       => '2026-03-12T10:00:00+01:00',
        ]);

        $config = new DataSourceConfig($this->tempDir);

        $this->assertSame('en', $config->getDefaultLanguage());
    }

    public function testDefaultLanguageReadsFromConfig(): void
    {
        $this->createConfig([
            'id'              => 'abcd-efgh-ijkl-mnop',
            'name'            => 'Test DS',
            'database_name'   => 'abcd_efgh_ijkl_mnop',
            'database_user'   => 'shpd_abcdefgh',
            'created'         => '2026-03-12T10:00:00+01:00',
            'defaultLanguage' => 'cs',
        ]);

        $config = new DataSourceConfig($this->tempDir);

        $this->assertSame('cs', $config->getDefaultLanguage());
    }

    public function testCountryReadsFromConfig(): void
    {
        $this->createConfig([
            'id'            => 'abcd-efgh-ijkl-mnop',
            'name'          => 'Test DS',
            'database_name' => 'abcd_efgh_ijkl_mnop',
            'database_user' => 'shpd_abcdefgh',
            'created'       => '2026-03-12T10:00:00+01:00',
            'country'       => 'sk',
        ]);

        $config = new DataSourceConfig($this->tempDir);

        $this->assertSame('sk', $config->getCountry());
        $this->assertTrue($config->hasCountry());
    }

    public function testCountryMissingFallsBackToCz(): void
    {
        $this->createConfig([
            'id'            => 'abcd-efgh-ijkl-mnop',
            'name'          => 'Test DS',
            'database_name' => 'abcd_efgh_ijkl_mnop',
            'database_user' => 'shpd_abcdefgh',
            'created'       => '2026-03-12T10:00:00+01:00',
        ]);

        $config = new DataSourceConfig($this->tempDir);

        $this->assertSame('cz', $config->getCountry());
        $this->assertFalse($config->hasCountry());
    }

    public function testCountryEmptyStringFallsBackToCz(): void
    {
        $this->createConfig([
            'id'            => 'abcd-efgh-ijkl-mnop',
            'name'          => 'Test DS',
            'database_name' => 'abcd_efgh_ijkl_mnop',
            'database_user' => 'shpd_abcdefgh',
            'created'       => '2026-03-12T10:00:00+01:00',
            'country'       => '',
        ]);

        $config = new DataSourceConfig($this->tempDir);

        $this->assertSame('cz', $config->getCountry());
        $this->assertFalse($config->hasCountry());
    }

    public function testShouldSkipProvisioningDefaultsToFalse(): void
    {
        $this->createConfig([
            'id'            => 'abcd-efgh-ijkl-mnop',
            'name'          => 'Test DS',
            'database_name' => 'abcd_efgh_ijkl_mnop',
            'database_user' => 'shpd_abcdefgh',
            'created'       => '2026-03-12T10:00:00+01:00',
        ]);

        $config = new DataSourceConfig($this->tempDir);

        $this->assertFalse($config->shouldSkipProvisioning());
    }

    public function testShouldSkipProvisioningReadsTrueFromConfig(): void
    {
        $this->createConfig([
            'id'               => 'abcd-efgh-ijkl-mnop',
            'name'             => 'Test DS',
            'database_name'    => 'abcd_efgh_ijkl_mnop',
            'database_user'    => 'shpd_abcdefgh',
            'created'          => '2026-03-12T10:00:00+01:00',
            'skipProvisioning' => true,
        ]);

        $config = new DataSourceConfig($this->tempDir);

        $this->assertTrue($config->shouldSkipProvisioning());
    }

    public function testShouldSkipProvisioningReadsFalseFromConfig(): void
    {
        $this->createConfig([
            'id'               => 'abcd-efgh-ijkl-mnop',
            'name'             => 'Test DS',
            'database_name'    => 'abcd_efgh_ijkl_mnop',
            'database_user'    => 'shpd_abcdefgh',
            'created'          => '2026-03-12T10:00:00+01:00',
            'skipProvisioning' => false,
        ]);

        $config = new DataSourceConfig($this->tempDir);

        $this->assertFalse($config->shouldSkipProvisioning());
    }

    public function testAllowsResetDefaultsToFalse(): void
    {
        $this->createConfig([
            'id'            => 'abcd-efgh-ijkl-mnop',
            'name'          => 'Test DS',
            'database_name' => 'abcd_efgh_ijkl_mnop',
            'database_user' => 'shpd_abcdefgh',
            'created'       => '2026-03-12T10:00:00+01:00',
        ]);

        $config = new DataSourceConfig($this->tempDir);

        $this->assertFalse($config->allowsReset());
    }

    public function testAllowsResetReadsTrueFromConfig(): void
    {
        $this->createConfig([
            'id'            => 'abcd-efgh-ijkl-mnop',
            'name'          => 'Test DS',
            'database_name' => 'abcd_efgh_ijkl_mnop',
            'database_user' => 'shpd_abcdefgh',
            'created'       => '2026-03-12T10:00:00+01:00',
            'enableReset'   => true,
        ]);

        $config = new DataSourceConfig($this->tempDir);

        $this->assertTrue($config->allowsReset());
    }

    /** @return array<string, mixed> main.json ve starém tvaru s `database_password` — jen pro test slotů (`inMainConfig`) */
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
            'REALCOPY' => ['REALCOPY'],
            'true'     => [true],
            'null'     => [null],
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
            'id'            => 'abcd-efgh-ijkl-mnop',
            'name'          => 'Test DS',
            'database_name' => 'abcd_efgh_ijkl_mnop',
            'database_user' => 'shpd_abcdefgh',
            'created'       => '2026-03-12T10:00:00+01:00',
        ]);

        $config = new DataSourceConfig($this->tempDir);

        $this->assertNull($config->getMailRelay());
        // druhé čtení jde přes parsed-cache
        $this->assertNull($config->getMailRelay());
    }

    public function testGetMailRelayOverride(): void
    {
        $this->createConfig([
            'id'            => 'abcd-efgh-ijkl-mnop',
            'name'          => 'Test DS',
            'database_name' => 'abcd_efgh_ijkl_mnop',
            'database_user' => 'shpd_abcdefgh',
            'created'       => '2026-03-12T10:00:00+01:00',
            'mail'          => [
                'relay' => [
                    'host'     => 'smtp.customer.example',
                    'port'     => 465,
                    'security' => 'tls',
                    'username' => 'ds-mailer',
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
            'id'            => 'abcd-efgh-ijkl-mnop',
            'name'          => 'Test DS',
            'database_name' => 'abcd_efgh_ijkl_mnop',
            'database_user' => 'shpd_abcdefgh',
            'created'       => '2026-03-12T10:00:00+01:00',
            'mail'          => ['relay' => ['security' => 'bogus']],
        ]);

        // load nesmí spadnout — validace až při prvním použití
        $config = new DataSourceConfig($this->tempDir);

        $this->expectException(\RuntimeException::class);
        $config->getMailRelay();
    }

    // ── tajemství v secrets/ (#126 B1 D3; B2 U1 — hodnota v main.json se ignoruje) ──

    public function testLoadWithoutDatabasePasswordSucceeds(): void
    {
        $this->createConfig($this->baseDataWithoutSecrets());
        $config = new DataSourceConfig($this->tempDir);

        $this->assertSame('abcd-efgh-ijkl-mnop', $config->getId());
        $this->assertSame('shpd_abcdefgh', $config->getDatabaseUser());
    }

    public function testDatabasePasswordMissingEverywhereThrowsWithTheFileName(): void
    {
        $this->createConfig($this->baseDataWithoutSecrets());
        $config = new DataSourceConfig($this->tempDir);

        try {
            $config->getDatabasePassword();
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Database password missing — expected secrets/db.password (run shpd-ds ds-upgrade)', $e->getMessage());
            $this->assertStringNotContainsString('main.json', $e->getMessage(), 'bez klíče v main.json se o něm nemluví');
        }
    }

    public function testDatabasePasswordComesFromSecretsFile(): void
    {
        $this->createConfig($this->baseDataWithoutSecrets());
        $this->writeSecret('db.password', "file-pw-fake\n");

        $this->assertSame('file-pw-fake', (new DataSourceConfig($this->tempDir))->getDatabasePassword());
    }

    public function testDatabasePasswordInMainJsonIsIgnoredWhenTheFileExists(): void
    {
        // B2 U1: hodnota v main.json se ignoruje — doctor ji hlásí, ds-upgrade přesune.
        $this->createConfig($this->baseDataWithoutSecrets() + ['database_password' => 'config-pw-fake']);
        $this->writeSecret('db.password', 'file-pw-fake');

        $this->assertSame('file-pw-fake', (new DataSourceConfig($this->tempDir))->getDatabasePassword());
    }

    public function testDatabasePasswordOnlyInMainJsonThrowsAndNamesTheLeftoverKey(): void
    {
        $this->createConfig($this->baseDataWithoutSecrets() + ['database_password' => 'config-pw-fake']);

        try {
            (new DataSourceConfig($this->tempDir))->getDatabasePassword();
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Database password missing — expected secrets/db.password (run shpd-ds ds-upgrade)', $e->getMessage());
            $this->assertStringContainsString('config/main.json still has database_password, run shpd-ds ds-upgrade to move it', $e->getMessage());
            $this->assertStringNotContainsString('fake', $e->getMessage(), 'hláška nikdy nenese hodnotu');
        }
    }

    public function testEmptyDatabasePasswordInMainJsonFallsBackToTheFile(): void
    {
        $this->createConfig($this->baseDataWithoutSecrets() + ['database_password' => '']);
        $this->writeSecret('db.password', 'file-pw-fake');

        $this->assertSame('file-pw-fake', (new DataSourceConfig($this->tempDir))->getDatabasePassword());
    }

    public function testSecretsAreReadLazily(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root čte i soubor s právy 0000');
        }
        $this->createConfig($this->baseDataWithoutSecrets());
        $this->writeSecret('db.password', 'unreadable-fake');
        chmod($this->tempDir . '/secrets/db.password', 0000);

        // Konstrukce ani běžné gettery do secrets/ nesáhnou.
        $config = new DataSourceConfig($this->tempDir);
        $this->assertSame('Test DS', $config->getName());
        $this->assertFalse($config->isFree());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot read secret file');
        $config->getDatabasePassword();
    }

    public function testMailRelayPasswordComesFromSecretsFile(): void
    {
        $this->createConfig($this->baseDataWithoutSecrets() + [
            'mail' => ['relay' => ['host' => 'smtp.example', 'username' => 'ds-mailer']],
        ]);
        $this->writeSecret('mail-relay.password', "relay-pw-fake\r\n");

        $relay = (new DataSourceConfig($this->tempDir))->getMailRelay();
        $this->assertNotNull($relay);
        $this->assertSame('ds-mailer', $relay->username);
        $this->assertSame('relay-pw-fake', $relay->password);
    }

    public function testMailRelayPasswordInMainJsonIsIgnoredWhenTheFileExists(): void
    {
        $this->createConfig($this->baseDataWithoutSecrets() + [
            'mail' => ['relay' => ['host' => 'smtp.example', 'username' => 'u', 'password' => 'config-relay-fake']],
        ]);
        $this->writeSecret('mail-relay.password', 'file-relay-fake');

        $this->assertSame('file-relay-fake', (new DataSourceConfig($this->tempDir))->getMailRelay()?->password);
    }

    public function testMailRelayPasswordOnlyInMainJsonLeavesTheRelayWithoutPassword(): void
    {
        $this->createConfig($this->baseDataWithoutSecrets() + [
            'mail' => ['relay' => ['host' => 'smtp.example', 'username' => 'u', 'password' => 'config-relay-fake']],
        ]);

        $relay = (new DataSourceConfig($this->tempDir))->getMailRelay();
        $this->assertNotNull($relay);
        $this->assertSame('u', $relay->username);
        $this->assertNull($relay->password, 'hodnota z main.json se nepoužije');
    }

    public function testMailRelayWithoutAnyPasswordStaysWithoutPassword(): void
    {
        $this->createConfig($this->baseDataWithoutSecrets() + [
            'mail' => ['relay' => ['host' => 'localhost', 'port' => 25, 'security' => 'none']],
        ]);

        $relay = (new DataSourceConfig($this->tempDir))->getMailRelay();
        $this->assertNotNull($relay);
        $this->assertNull($relay->password);
    }

    /** @return array<string, mixed> */
    private function providerData(array $overrides = []): array
    {
        return array_merge([
            'id'       => 'shipard-id',
            'label'    => 'Shipard ID',
            'issuer'   => 'https://portal.example.com/api/v1/_hosting/oidc',
            'clientId' => 'abcd-efgh-ijkl-mnop',
        ], $overrides);
    }

    public function testAuthPolicyClientSecretComesFromSecretsFile(): void
    {
        $this->createConfig($this->baseDataWithoutSecrets() + [
            'auth' => ['providers' => [$this->providerData()]],
        ]);
        $this->writeSecret('oidc-shipard-id.client-secret', "oidc-fake-secret\n");

        $policy = (new DataSourceConfig($this->tempDir))->getAuthPolicy();
        $this->assertSame('oidc-fake-secret', $policy->getProvider('shipard-id')?->clientSecret);
    }

    public function testAuthPolicyClientSecretInMainJsonIsIgnoredWhenTheFileExists(): void
    {
        $this->createConfig($this->baseDataWithoutSecrets() + [
            'auth' => ['providers' => [$this->providerData(['clientSecret' => 'config-oidc-fake'])]],
        ]);
        $this->writeSecret('oidc-shipard-id.client-secret', 'file-oidc-fake');

        $policy = (new DataSourceConfig($this->tempDir))->getAuthPolicy();
        $this->assertSame('file-oidc-fake', $policy->getProvider('shipard-id')?->clientSecret);
    }

    public function testAuthPolicyClientSecretOnlyInMainJsonIsRejectedWithoutTheValue(): void
    {
        $this->createConfig($this->baseDataWithoutSecrets() + [
            'auth' => ['providers' => [$this->providerData(['clientSecret' => 'config-oidc-fake'])]],
        ]);

        try {
            (new DataSourceConfig($this->tempDir))->getAuthPolicy();
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("provider 'shipard-id' is missing clientSecret (secrets/oidc-shipard-id.client-secret)", $e->getMessage());
            $this->assertStringNotContainsString('fake', $e->getMessage(), 'hláška nikdy nenese hodnotu');
        }
    }

    public function testAuthPolicyMissingClientSecretNamesTheFile(): void
    {
        $this->createConfig($this->baseDataWithoutSecrets() + [
            'auth' => ['providers' => [$this->providerData()]],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("provider 'shipard-id' is missing clientSecret (secrets/oidc-shipard-id.client-secret)");
        (new DataSourceConfig($this->tempDir))->getAuthPolicy();
    }

    public function testAuthPolicyInvalidProviderIdIsRejectedWithoutTouchingSecrets(): void
    {
        $this->createConfig($this->baseDataWithoutSecrets() + [
            'auth' => ['providers' => [$this->providerData(['id' => '../x'])]],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must match [a-z0-9-]+');
        (new DataSourceConfig($this->tempDir))->getAuthPolicy();
    }

    public function testGetDatasetReadsTheRecordLeniently(): void
    {
        $this->createConfig($this->baseDataWithoutSecrets() + ['dataset' => ['name' => ' cz-vat-payer ', 'hash' => 'abc', 'seededAt' => '2026-10-16T12:00:00Z']]);
        $this->assertSame(['name' => 'cz-vat-payer', 'hash' => 'abc', 'seededAt' => '2026-10-16T12:00:00Z'], (new DataSourceConfig($this->tempDir))->getDataset());

        $this->createConfig($this->baseDataWithoutSecrets());
        $this->assertNull((new DataSourceConfig($this->tempDir))->getDataset(), 'seed před F6b');

        $this->createConfig($this->baseDataWithoutSecrets() + ['dataset' => ['name' => 'x']]);
        $this->assertNull((new DataSourceConfig($this->tempDir))->getDataset(), 'bez hashe = nezaznamenaná');

        $this->createConfig($this->baseDataWithoutSecrets() + ['dataset' => 'cz-vat-payer']);
        $this->assertNull((new DataSourceConfig($this->tempDir))->getDataset(), 'rozbitý tvar = null, ne pád');

        $this->assertSame(['name' => 'a', 'hash' => 'b', 'seededAt' => null], DataSourceConfig::datasetFrom(['dataset' => ['name' => 'a', 'hash' => 'b', 'seededAt' => 7]]));
    }

    public function testSecretSlotsDescribeWhatMainJsonNeedsWithoutValues(): void
    {
        $this->createConfig($this->baseData() + [
            'mail' => ['relay' => ['host' => 'smtp.example', 'username' => 'u']],
            'auth' => ['providers' => [
                $this->providerData(['clientSecret' => 'config-oidc-fake']),
                $this->providerData(['id' => 'github', 'kind' => 'github']),
                ['label' => 'bez id'],
            ]],
        ]);
        $config = new DataSourceConfig($this->tempDir);

        $slots = $config->getSecretSlots();
        $this->assertSame(
            ['db.password', 'mail-relay.password', 'oidc-shipard-id.client-secret', 'oidc-github.client-secret'],
            array_map(static fn($s) => $s->name, $slots),
        );
        $this->assertSame(
            ['database', 'mail.relay', 'auth.providers[shipard-id]', 'auth.providers[github]'],
            array_map(static fn($s) => $s->usedBy, $slots),
        );
        $this->assertSame([true, true, true, true], array_map(static fn($s) => $s->required, $slots));
        $this->assertSame([true, false, true, false], array_map(static fn($s) => $s->inMainConfig, $slots));

        $this->assertSame(['db.password', 'oidc-shipard-id.client-secret'], $config->getSecretsInMainConfig());
        $this->assertTrue($config->hasSecretsInMainConfig());
    }

    public function testSecretSlotsRelayWithoutUsernameIsNotRequiredAndCleanConfigHasNoneInMainJson(): void
    {
        $this->createConfig($this->baseDataWithoutSecrets() + [
            'mail' => ['relay' => ['host' => 'localhost', 'port' => 25, 'security' => 'none']],
        ]);
        $config = new DataSourceConfig($this->tempDir);

        $slots = $config->getSecretSlots();
        $this->assertCount(2, $slots);
        $this->assertFalse($slots[1]->required);
        $this->assertFalse($slots[1]->inMainConfig);
        $this->assertSame([], $config->getSecretsInMainConfig());
        $this->assertFalse($config->hasSecretsInMainConfig());
    }
}
