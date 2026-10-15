<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
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

    public function testUsesServerAiConnectionReadsTheAiSection(): void
    {
        // tasks/testing-phase4.md D40: chybí = false, jen `true` zapíná.
        $base = [
            'id'                => 'abcd-efgh-ijkl-mnop',
            'name'              => 'Test DS',
            'database_name'     => 'abcd_efgh_ijkl_mnop',
            'database_user'     => 'shpd_abcdefgh',
            'database_password' => 'supersecret',
            'created'           => '2026-03-12T10:00:00+01:00',
        ];
        $this->createConfig($base);
        $this->assertFalse((new DataSourceConfig($this->tempDir))->usesServerAiConnection());

        $this->createConfig($base + ['ai' => ['useServerConnection' => true]]);
        $this->assertTrue((new DataSourceConfig($this->tempDir))->usesServerAiConnection());

        $this->createConfig($base + ['ai' => ['useServerConnection' => 'yes']]);
        $this->assertFalse((new DataSourceConfig($this->tempDir))->usesServerAiConnection());
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
