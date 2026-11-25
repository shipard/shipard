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

    // ── getSection() + CORE_KEYS (#172 D7, D10) ─────────────────────────────

    public function testGetSectionReturnsRawValueOnPath(): void
    {
        $config = new ServerConfig($this->createConfig($this->baseConfig() + [
            'exchangeRates' => ['serviceUrl' => ''],
            'ai' => ['analysis' => ['maxConcurrent' => 3], 'label' => 'x'],
            'flag' => false,
            'list' => ['a', 'b'],
        ]));
        $config->load();

        $this->assertSame(['serviceUrl' => ''], $config->getSection('exchangeRates'));
        $this->assertSame(['maxConcurrent' => 3], $config->getSection('ai.analysis'));
        $this->assertSame(3, $config->getSection('ai.analysis.maxConcurrent'));
        $this->assertSame('', $config->getSection('exchangeRates.serviceUrl'), 'prázdný řetězec není null');
        $this->assertFalse($config->getSection('flag'), 'typ hodnoty kontroluje třída sekce');
        $this->assertSame(['a', 'b'], $config->getSection('list'));
        $this->assertSame('production', $config->getSection('mode'), 'cestu neomezuje — klíče jádra hlídají pravidla modulů');
    }

    public function testGetSectionReturnsNullForMissingPath(): void
    {
        $config = new ServerConfig($this->createConfig($this->baseConfig() + [
            'ai' => ['analysis' => ['maxConcurrent' => 3], 'label' => 'x'],
            'registry' => 'not-an-object',
            'list' => ['a', 'b'],
        ]));
        $config->load();

        $this->assertNull($config->getSection('exchangeRates'), 'sekce chybí');
        $this->assertNull($config->getSection('ai.chat'), 'podsekce chybí');
        $this->assertNull($config->getSection('ai.analysis.maxConcurrent.deeper'), 'pod skalárem nic není');
        $this->assertNull($config->getSection('ai.label.x'), 'segment uprostřed není objekt');
        $this->assertNull($config->getSection('registry.persons'), 'segment uprostřed není objekt');
        $this->assertNull($config->getSection('list.persons'), 'seznam nemá pojmenované klíče');
        $this->assertNull($config->getSection(''));
    }

    public function testGetSectionBeforeLoadIsNull(): void
    {
        $this->assertNull((new ServerConfig($this->tempDir . '/missing.json'))->getSection('ai.analysis'));
    }

    /**
     * `CORE_KEYS` odpovídá tomu, co `ServerConfig` z nejvyšší úrovně
     * `server.json` opravdu čte — nový getter jádra bez klíče v seznamu
     * (sekci by si mohl zabrat modul) i klíč bez čtenáře test shodí.
     */
    public function testCoreKeysMatchTheKeysServerConfigReads(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(ServerConfig::class))->getFileName());
        preg_match_all('/\$(?:this->)?data\[\'([A-Za-z_]+)\'\]/', $source, $matches);
        $read = array_values(array_unique($matches[1]));
        // povinná pole čte load() smyčkou přes seznam, ne literálem
        $read = array_values(array_unique([...$read, 'host', 'port', 'admin_user', 'admin_password', 'mode']));

        $expected = ServerConfig::CORE_KEYS;
        sort($read);
        sort($expected);
        $this->assertSame($expected, $read);
    }

    public function testGetDevAiDefaultsToAnthropicAndReadsTheSection(): void
    {
        // tasks/testing-phase4.md D40
        $base = ['host' => 'h', 'port' => 3306, 'admin_user' => 'u', 'admin_password' => 'p', 'mode' => 'development'];

        $config = new ServerConfig($this->createConfig($base));
        $config->load();
        $this->assertSame($this->tempDir, $config->getConfigDir());
        $this->assertSame('anthropic', $config->getDevAi()->platform);
        $this->assertNull($config->getDevAi()->baseUrl);

        $config = new ServerConfig($this->createConfig($base + ['devAi' => ['platform' => 'bedrock-eu', 'baseUrl' => 'https://bedrock.example/anthropic/']]));
        $config->load();
        $this->assertSame('bedrock-eu', $config->getDevAi()->platform);
        $this->assertSame('https://bedrock.example/anthropic', $config->getDevAi()->baseUrl);

        $config = new ServerConfig($this->createConfig($base + ['devAi' => 'anthropic']));
        $config->load();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("'devAi' must be an object");
        $config->getDevAi();
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

    /** @return array<string, mixed> */
    private function baseConfig(): array
    {
        return ['host' => '127.0.0.1', 'port' => 3306, 'admin_user' => 'root', 'admin_password' => 'secret', 'mode' => 'production'];
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
