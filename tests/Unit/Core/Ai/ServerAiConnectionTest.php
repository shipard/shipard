<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\DevAiKeyStore;
use Shipard\Core\Ai\ServerAiConnection;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Serverové AI připojení pro vývoj (tasks/testing-phase4.md D40): jen
 * `mode = development` s uloženým klíčem; sekce `devAi`; virtuální řádek
 * připojení; klíč ze souboru; cache `detect()` s přepsáním pro testy.
 */
final class ServerAiConnectionTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        ErrorLogger::setLogLevel('error');
        $this->configDir = sys_get_temp_dir() . '/shpd_serverai_' . bin2hex(random_bytes(4));
        mkdir($this->configDir, 0750, true);
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        ServerAiConnection::resetForTesting();
        foreach (glob($this->configDir . '/secrets/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->configDir . '/secrets');
        @unlink($this->configDir . '/server.json');
        @rmdir($this->configDir);
    }

    /** @param array<string, mixed> $extra */
    private function server(string $mode, array $extra = []): ServerConfig
    {
        file_put_contents($this->configDir . '/server.json', json_encode([
            'host' => '127.0.0.1', 'port' => 3306, 'admin_user' => 'root', 'admin_password' => 'x', 'mode' => $mode,
        ] + $extra));
        $config = new ServerConfig($this->configDir . '/server.json');
        $config->load();
        return $config;
    }

    public function testOnlyADevelopmentServerWithAStoredKeyOffersTheConnection(): void
    {
        $this->assertNull(ServerAiConnection::fromServerConfig($this->server('development')), 'bez klíče');

        DevAiKeyStore::write($this->configDir, 'anthropic', 'sk-dev', $this->configDir);
        $this->assertNull(ServerAiConnection::fromServerConfig($this->server('production')), 'produkce nikdy');

        $connection = ServerAiConnection::fromServerConfig($this->server('development'));
        $this->assertNotNull($connection);
        $this->assertSame('anthropic', $connection->platform);
        $this->assertNull($connection->baseUrl);
        $this->assertSame($this->configDir, $connection->configDir);
        $this->assertSame('sk-dev', $connection->apiKey());
        $this->assertSame($this->configDir . '/secrets/dev-ai-anthropic.key', $connection->keyFilePath());

        $row = $connection->row();
        $this->assertSame(0, $row['id']);
        $this->assertSame('server', $row['backend_id']);
        $this->assertSame('anthropic', $row['provider']);
        $this->assertNull($row['base_url']);
        $this->assertNotSame('', $row['api_key'], 'marker, aby prošla kontrola uloženého klíče');
        $this->assertSame(1, $row['is_active']);
        $this->assertTrue(ServerAiConnection::isServerRow($row));
        $this->assertFalse(ServerAiConnection::isServerRow(['id' => 1, 'backend_id' => 'default']));
    }

    public function testDevAiSectionPicksThePlatformAndAddress(): void
    {
        DevAiKeyStore::write($this->configDir, 'bedrock-eu', 'sk-bedrock', $this->configDir);
        $server = $this->server('development', ['devAi' => ['platform' => 'bedrock-eu', 'baseUrl' => 'https://bedrock.example/anthropic']]);

        $connection = ServerAiConnection::detect($server);
        $this->assertNotNull($connection);
        $this->assertSame('bedrock-eu', $connection->platform);
        $this->assertSame('https://bedrock.example/anthropic', $connection->baseUrl);
        $this->assertSame('https://bedrock.example/anthropic', $connection->row()['base_url']);

        // Klíč jiné platformy nestačí.
        $this->assertNull(ServerAiConnection::detect($this->server('development', ['devAi' => ['platform' => 'anthropic']])));
        // Rozbitá sekce = bez připojení, ne výjimka.
        $this->assertNull(ServerAiConnection::detect($this->server('development', ['devAi' => ['platform' => 'Bad!']])));
    }

    public function testDetectWithoutArgumentIsCachedAndOverridableForTests(): void
    {
        ServerAiConnection::overrideForTesting(null);
        $this->assertNull(ServerAiConnection::detect());

        $fixed = new ServerAiConnection('anthropic', null, $this->configDir);
        ServerAiConnection::overrideForTesting($fixed);
        $this->assertSame($fixed, ServerAiConnection::detect());

        // Explicitní konfigurace cache obchází.
        $this->assertNull(ServerAiConnection::detect($this->server('production')));
        $this->assertSame($fixed, ServerAiConnection::detect());
    }
}
