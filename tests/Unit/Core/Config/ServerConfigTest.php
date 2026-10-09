<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ServerConfig;

class ServerConfigTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shipard_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    private function createConfig(array $data): string
    {
        $path = $this->tempDir . '/server.json';
        file_put_contents($path, json_encode($data));
        return $path;
    }

    public function testLoadValidConfig(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
        ]);

        $config = new ServerConfig($path);
        $config->load();

        $this->assertSame('127.0.0.1', $config->getHost());
        $this->assertSame(3306, $config->getPort());
        $this->assertSame('root', $config->getAdminUser());
        $this->assertSame('secret', $config->getAdminPassword());
        $this->assertSame('production', $config->getMode());
        $this->assertSame('/etc/shipard/domains.json', $config->getDomainsFile());
    }

    public function testGetAiAnalysisMaxConcurrentDefaultsAndValidates(): void
    {
        $base = ['host' => 'h', 'port' => 1, 'admin_user' => 'u', 'admin_password' => 'p', 'mode' => 'development'];

        $config = new ServerConfig($this->createConfig($base));
        $config->load();
        $this->assertSame(2, $config->getAiAnalysisMaxConcurrent());

        $config = new ServerConfig($this->createConfig($base + ['ai' => ['analysis' => ['maxConcurrent' => 0]]]));
        $config->load();
        $this->assertSame(0, $config->getAiAnalysisMaxConcurrent());

        $config = new ServerConfig($this->createConfig($base + ['ai' => ['analysis' => ['maxConcurrent' => 5]]]));
        $config->load();
        $this->assertSame(5, $config->getAiAnalysisMaxConcurrent());

        $config = new ServerConfig($this->createConfig($base + ['ai' => ['analysis' => ['maxConcurrent' => -1]]]));
        $config->load();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ai.analysis.maxConcurrent');
        $config->getAiAnalysisMaxConcurrent();
    }

    public function testGetDomainsFileCustomPath(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
            'domainsFile'    => '/custom/path/domains.json',
        ]);

        $config = new ServerConfig($path);
        $config->load();

        $this->assertSame('/custom/path/domains.json', $config->getDomainsFile());
    }

    public function testLoadMissingFileThrowsException(): void
    {
        $config = new ServerConfig($this->tempDir . '/nonexistent.json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');

        $config->load();
    }

    public function testLoadInvalidJsonThrowsException(): void
    {
        $path = $this->tempDir . '/server.json';
        file_put_contents($path, '{invalid json}');

        $config = new ServerConfig($path);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid json/i');

        $config->load();
    }

    public function testLoadMissingRequiredFieldThrowsException(): void
    {
        $path = $this->createConfig([
            'host' => '127.0.0.1',
            // missing: port, admin_user, admin_password, mode
        ]);

        $config = new ServerConfig($path);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing required/i');

        $config->load();
    }

    public function testGetLogFileDefault(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
        ]);

        $config = new ServerConfig($path);
        $config->load();

        $this->assertSame('/opt/shipard/log/shipard.log', $config->getLogFile());
        $this->assertSame('debug', $config->getLogLevel());
    }

    public function testGetLogFileAndLevelCustom(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
            'logFile'        => '/var/log/shipard-custom.log',
            'logLevel'       => 'warn',
        ]);

        $config = new ServerConfig($path);
        $config->load();

        $this->assertSame('/var/log/shipard-custom.log', $config->getLogFile());
        $this->assertSame('warn', $config->getLogLevel());
    }

    public function testGetExtraModulesPathDefaultsToEmpty(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
        ]);

        $config = new ServerConfig($path);
        $config->load();

        $this->assertSame([], $config->getExtraModulesPath());
    }

    public function testGetExtraModulesPathReturnsList(): void
    {
        $path = $this->createConfig([
            'host'             => '127.0.0.1',
            'port'             => 3306,
            'admin_user'       => 'root',
            'admin_password'   => 'secret',
            'mode'             => 'production',
            'extraModulesPath' => ['/opt/customer-a/modules', '/opt/customer-b/modules'],
        ]);

        $config = new ServerConfig($path);
        $config->load();

        $this->assertSame(
            ['/opt/customer-a/modules', '/opt/customer-b/modules'],
            $config->getExtraModulesPath(),
        );
    }

    public function testExtraModulesPathRejectsNonArray(): void
    {
        $path = $this->createConfig([
            'host'             => '127.0.0.1',
            'port'             => 3306,
            'admin_user'       => 'root',
            'admin_password'   => 'secret',
            'mode'             => 'production',
            'extraModulesPath' => '/just/a/string',
        ]);

        $config = new ServerConfig($path);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/extraModulesPath.*array/i');

        $config->load();
    }

    public function testExtraModulesPathRejectsNonStringEntry(): void
    {
        $path = $this->createConfig([
            'host'             => '127.0.0.1',
            'port'             => 3306,
            'admin_user'       => 'root',
            'admin_password'   => 'secret',
            'mode'             => 'production',
            'extraModulesPath' => ['/ok', 123],
        ]);

        $config = new ServerConfig($path);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/extraModulesPath\[1\]/');

        $config->load();
    }

    public function testExtraModulesPathRejectsEmptyStringEntry(): void
    {
        $path = $this->createConfig([
            'host'             => '127.0.0.1',
            'port'             => 3306,
            'admin_user'       => 'root',
            'admin_password'   => 'secret',
            'mode'             => 'production',
            'extraModulesPath' => ['/ok', ''],
        ]);

        $config = new ServerConfig($path);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/extraModulesPath\[1\]/');

        $config->load();
    }

    public function testExtraModulesPathRejectsAssociativeArray(): void
    {
        $path = $this->createConfig([
            'host'             => '127.0.0.1',
            'port'             => 3306,
            'admin_user'       => 'root',
            'admin_password'   => 'secret',
            'mode'             => 'production',
            'extraModulesPath' => ['key' => '/path'],
        ]);

        $config = new ServerConfig($path);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/extraModulesPath.*array/i');

        $config->load();
    }

    public function testGetRegistryPersonsBaseUrlDefault(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
        ]);

        $config = new ServerConfig($path);
        $config->load();

        $this->assertSame('https://data.shipard.org/persons', $config->getRegistryPersonsBaseUrl());
    }

    public function testGetRegistryPersonsBaseUrlCustom(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
            'registry'       => [
                'persons' => [
                    'baseUrl' => 'https://dev-example.shpd.dev/abcd-efgh-ijkl-mnop/www/data.shipard.org/persons',
                ],
            ],
        ]);

        $config = new ServerConfig($path);
        $config->load();

        $this->assertSame(
            'https://dev-example.shpd.dev/abcd-efgh-ijkl-mnop/www/data.shipard.org/persons',
            $config->getRegistryPersonsBaseUrl(),
        );
    }

    public function testGetRegistryPersonsBaseUrlStripsTrailingSlash(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
            'registry'       => ['persons' => ['baseUrl' => 'https://example.org/persons/']],
        ]);

        $config = new ServerConfig($path);
        $config->load();

        $this->assertSame('https://example.org/persons', $config->getRegistryPersonsBaseUrl());
    }

    public function testGetRegistryPersonsBaseUrlRejectsNonHttpScheme(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
            'registry'       => ['persons' => ['baseUrl' => 'ftp://example.org/persons']],
        ]);

        $config = new ServerConfig($path);
        $config->load();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/registry\.persons\.baseUrl.*http/i');

        $config->getRegistryPersonsBaseUrl();
    }

    public function testLoadMissingEachRequiredField(): void
    {
        $required = ['host', 'port', 'admin_user', 'admin_password', 'mode'];
        $fullData = [
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
        ];

        foreach ($required as $field) {
            $data = $fullData;
            unset($data[$field]);

            $path = $this->createConfig($data);
            $config = new ServerConfig($path);

            try {
                $config->load();
                $this->fail("Expected RuntimeException for missing field: {$field}");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsStringIgnoringCase('missing required', $e->getMessage());
            }
        }
    }

    public function testGetMailRelayMissingReturnsNull(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
        ]);

        $config = new ServerConfig($path);
        $config->load();

        $this->assertNull($config->getMailRelay());
    }

    public function testGetMailRelayConfigured(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
            'mail'           => [
                'relay' => [
                    'host'     => 'relay.example.com',
                    'username' => 'shipard',
                    'password' => 'relay-pw',
                ],
            ],
        ]);

        $config = new ServerConfig($path);
        $config->load();
        $relay = $config->getMailRelay();

        $this->assertNotNull($relay);
        $this->assertSame('relay.example.com', $relay->host);
        $this->assertSame(587, $relay->port);
        $this->assertSame('starttls', $relay->security);
        $this->assertSame('shipard', $relay->username);
        $this->assertSame('relay-pw', $relay->password);
    }

    public function testGetMailRelayInvalidThrows(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
            'mail'           => ['relay' => 'not-an-object'],
        ]);

        $config = new ServerConfig($path);
        $config->load();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/mail\.relay/');

        $config->getMailRelay();
    }

    public function testGetMailSafetyFollowsServerModeWhenMissing(): void
    {
        $base = ['host' => '127.0.0.1', 'port' => 3306, 'admin_user' => 'root', 'admin_password' => 'secret'];

        $production = new ServerConfig($this->createConfig($base + ['mode' => 'production']));
        $production->load();
        $this->assertSame('off', $production->getMailSafety()->mode);

        $development = new ServerConfig($this->createConfig($base + ['mode' => 'development']));
        $development->load();
        $this->assertSame('drop', $development->getMailSafety()->mode);
    }

    public function testGetMailSafetyConfiguredNextToRelay(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
            'mail'           => [
                'relay'  => ['host' => 'relay.example.com'],
                'safety' => ['mode' => 'redirect', 'redirectTo' => 'testy@example.com'],
            ],
        ]);

        $config = new ServerConfig($path);
        $config->load();

        $this->assertSame('redirect', $config->getMailSafety()->mode);
        $this->assertSame('testy@example.com', $config->getMailSafety()->redirectTo);
        $this->assertSame('relay.example.com', $config->getMailRelay()->host);
    }

    public function testGetMailSafetyInvalidDropsInsteadOfThrowing(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
            'mail'           => ['safety' => ['mode' => 'redirect']],
        ]);

        $config = new ServerConfig($path);
        $config->load();
        $safety = $config->getMailSafety();

        $this->assertSame('drop', $safety->mode);
        $this->assertSame('invalid', $safety->source);
        $this->assertNotNull($safety->problem);
    }

    public function testGetRenderMissingReturnsNull(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
        ]);

        $config = new ServerConfig($path);
        $config->load();

        $this->assertNull($config->getRender());
    }

    public function testGetRenderConfigured(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
            'render'         => ['url' => 'http://127.0.0.1:3000', 'timeoutSec' => 45],
        ]);

        $config = new ServerConfig($path);
        $config->load();
        $render = $config->getRender();

        $this->assertNotNull($render);
        $this->assertSame('http://127.0.0.1:3000', $render->url);
        $this->assertSame(45, $render->timeoutSec);
    }

    public function testGetRenderInvalidThrows(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
            'render'         => 'not-an-object',
        ]);

        $config = new ServerConfig($path);
        $config->load();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/render.*object/i');

        $config->getRender();
    }

    public function testGetHostingMissingReturnsNull(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
        ]);

        $config = new ServerConfig($path);
        $config->load();

        $this->assertNull($config->getHosting());
    }

    public function testGetHostingConfigured(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
            'hosting'        => [
                'url'      => 'https://portal.example.com/',
                'serverId' => 3,
                'apiKey'   => 'shpd_hk_' . str_repeat('a', 43),
            ],
        ]);

        $config = new ServerConfig($path);
        $config->load();
        $hosting = $config->getHosting();

        $this->assertNotNull($hosting);
        $this->assertSame('https://portal.example.com', $hosting->url);
        $this->assertSame(3, $hosting->serverId);
        $this->assertStringStartsWith('shpd_hk_', $hosting->apiKey);
    }

    public function testGetHostingInvalidThrows(): void
    {
        $path = $this->createConfig([
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => 'secret',
            'mode'           => 'production',
            'hosting'        => ['url' => 'https://portal.example.com', 'serverId' => 1, 'apiKey' => 'wrong-prefix'],
        ]);

        $config = new ServerConfig($path);
        $config->load();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/shpd_hk_/');

        $config->getHosting();
    }
}
