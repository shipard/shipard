<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiBackendResolver;
use Shipard\Core\Ai\DevAiKeyStore;
use Shipard\Core\Ai\ServerAiConnection;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Serverové připojení v resolveru připojení (tasks/testing-phase4.md D40):
 * vlastní připojení s klíčem má vždy přednost; bez klíče → serverové jen
 * s příznakem zdroje dat a dostupným serverem; připojení podle kódu;
 * klíč serverového řádku ze souboru.
 */
final class AiBackendResolverTest extends TestCase
{
    private const DEFAULT_WITH_KEY = ['id' => 1, 'backend_id' => 'default', 'provider' => 'anthropic', 'base_url' => null, 'api_key' => 'enc', 'is_default' => 1, 'is_active' => 1];
    private const DEFAULT_WITHOUT_KEY = ['id' => 1, 'backend_id' => 'default', 'provider' => 'anthropic', 'base_url' => null, 'api_key' => '', 'is_default' => 1, 'is_active' => 1];
    private const GATEWAY = ['id' => 2, 'backend_id' => 'gateway', 'provider' => 'anthropic', 'base_url' => 'https://portal.example/_hosting/ai-gw', 'api_key' => 'enc2', 'is_default' => 0, 'is_active' => 1];

    private string $configDir;

    protected function setUp(): void
    {
        $this->configDir = sys_get_temp_dir() . '/shpd_backendres_' . bin2hex(random_bytes(4));
        mkdir($this->configDir . '/secrets', 0700, true);
        DevAiKeyStore::write($this->configDir, 'anthropic', 'sk-server', $this->configDir);
        ServerAiConnection::overrideForTesting(null);
    }

    protected function tearDown(): void
    {
        ServerAiConnection::resetForTesting();
        @unlink(DevAiKeyStore::keyFilePath($this->configDir, 'anthropic'));
        @rmdir($this->configDir . '/secrets');
        @rmdir($this->configDir);
    }

    /** @param array<string, mixed>|null $default */
    private function db(?array $default): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(static function (string $sql, mixed ...$args) use ($default): ?array {
            if (str_contains($sql, '`is_default` = %i')) {
                return $default;
            }
            if (str_contains($sql, '`backend_id` = %s')) {
                return $args[0] === 'gateway' ? self::GATEWAY : null;
            }
            if (str_contains($sql, '`id` = %i')) {
                return $args[0] === 2 ? self::GATEWAY : null;
            }
            return null;
        });
        return $db;
    }

    private function dsConfig(bool $flag): DataSourceConfig
    {
        $config = $this->createMock(DataSourceConfig::class);
        $config->method('usesServerAiConnection')->willReturn($flag);
        return $config;
    }

    private function server(): ServerAiConnection
    {
        return new ServerAiConnection('anthropic', null, $this->configDir);
    }

    public function testOwnConnectionWithAKeyAlwaysWins(): void
    {
        $resolver = new AiBackendResolver($this->db(self::DEFAULT_WITH_KEY), $this->dsConfig(true), $this->server());
        $this->assertSame(self::DEFAULT_WITH_KEY, $resolver->defaultBackend());
    }

    public function testWithoutAKeyTheServerConnectionNeedsTheFlagAndTheServer(): void
    {
        $resolver = new AiBackendResolver($this->db(self::DEFAULT_WITHOUT_KEY), $this->dsConfig(true), $this->server());
        $row = $resolver->defaultBackend();
        $this->assertNotNull($row);
        $this->assertTrue(ServerAiConnection::isServerRow($row));
        $this->assertSame('server', $row['backend_id']);
        $this->assertSame('sk-server', $resolver->apiKey($row));

        // Bez výchozího řádku totéž.
        $resolver = new AiBackendResolver($this->db(null), $this->dsConfig(true), $this->server());
        $this->assertTrue(ServerAiConnection::isServerRow((array) $resolver->defaultBackend()));

        // Bez příznaku zůstává řádek bez klíče (resolver hlásí KEY_MISSING jako dřív).
        $resolver = new AiBackendResolver($this->db(self::DEFAULT_WITHOUT_KEY), $this->dsConfig(false), $this->server());
        $this->assertSame(self::DEFAULT_WITHOUT_KEY, $resolver->defaultBackend());
        $this->assertNull($resolver->serverBackend());

        // S příznakem, ale server připojení nenabízí (jiný režim, bez klíče) → null.
        $resolver = new AiBackendResolver($this->db(null), $this->dsConfig(true), null, false);
        $this->assertNull($resolver->defaultBackend());

        // Bez konfigurace zdroje dat nikdy (příznak nejde přečíst).
        $resolver = new AiBackendResolver($this->db(null), null, $this->server());
        $this->assertNull($resolver->defaultBackend());
    }

    public function testDetectionWithoutInjectedServerFollowsTheTestOverride(): void
    {
        // overrideForTesting(null) v setUp: resolver nic nedetekuje ze stroje.
        $resolver = new AiBackendResolver($this->db(null), $this->dsConfig(true));
        $this->assertNull($resolver->defaultBackend());

        ServerAiConnection::overrideForTesting($this->server());
        $resolver = new AiBackendResolver($this->db(null), $this->dsConfig(true));
        $this->assertTrue(ServerAiConnection::isServerRow((array) $resolver->defaultBackend()));
    }

    public function testConnectionByCode(): void
    {
        $resolver = new AiBackendResolver($this->db(self::DEFAULT_WITH_KEY), $this->dsConfig(true), $this->server());
        $this->assertSame(self::GATEWAY, $resolver->backendByCode('gateway'));
        $this->assertSame(self::GATEWAY, $resolver->backendByCode(' gateway '));
        $this->assertNull($resolver->backendByCode('nope'));
        $this->assertNull($resolver->backendByCode(''));
        $this->assertTrue(ServerAiConnection::isServerRow((array) $resolver->backendByCode('server')));

        $resolver = new AiBackendResolver($this->db(self::DEFAULT_WITH_KEY), $this->dsConfig(false), $this->server());
        $this->assertNull($resolver->backendByCode('server'), 'kód server bez příznaku zdroje dat');
    }

    public function testServerRowKeyWithoutAServerIsAnError(): void
    {
        $resolver = new AiBackendResolver($this->db(null), $this->dsConfig(true), null, false);
        $this->expectException(\RuntimeException::class);
        $resolver->apiKey($this->server()->row());
    }
}
