<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Analysis;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiBackendResolver;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\Analysis\AnalysisBackendProbe;

/**
 * Použitelné připojení = aktivní výchozí profil → aktivní připojení
 * s klíčem; připojení jako v resolveru (tasks/ai-functions.md D40):
 * přepsání v profilu → řádek funkce mail.extraction → výchozí připojení.
 * Bez vlastního klíče a bez výslovného odkazu platí serverové připojení
 * pro vývoj (tasks/testing-phase4.md D40).
 */
class AnalysisBackendProbeTest extends TestCase
{
    public function testQueryFollowsTheResolverConnectionOrder(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(static function (...$args) use (&$captured): ?array {
            $captured = $args;
            return ['api_key' => 'v1:cipher', 'profile_backend' => null, 'function_connection' => null];
        });

        $this->assertTrue(new AnalysisBackendProbe($db)->hasUsableBackend());
        $sql = (string) $captured[0];
        $this->assertStringContainsString('SELECT b.api_key, p.backend AS profile_backend, f.connection AS function_connection FROM %n p', $sql);
        $this->assertStringContainsString('LEFT JOIN %n f ON f.function_id = %s', $sql);
        $this->assertStringContainsString('LEFT JOIN %n b ON b.id = COALESCE(p.backend, f.connection,', $sql);
        $this->assertStringContainsString('d.is_default = %i AND d.is_active = %i LIMIT 1', $sql);
        $this->assertStringContainsString('AND b.is_active = %i', $sql);
        $this->assertStringContainsString('WHERE p.is_default = %i AND p.is_active = %i LIMIT 1', $sql);
        $this->assertSame(
            ['core_mail_ai_profiles', 'core_ai_function_settings', 'mail.extraction', 'core_ai_backends', 'core_ai_backends', 1, 1, 1, 1, 1],
            array_slice($captured, 1),
        );
    }

    public function testMissingProfileOrEmptyKeyIsNotUsableWithoutTheServerConnection(): void
    {
        foreach ([null, [], ['api_key' => null], ['api_key' => '']] as $row) {
            $db = $this->createMock(DataSourceConnection::class);
            $db->method('fetchRow')->willReturn($row);
            $this->assertFalse(new AnalysisBackendProbe($db)->hasUsableBackend(), json_encode($row));
        }
    }

    public function testServerConnectionCountsOnlyWithoutAnExplicitConnection(): void
    {
        $serverRow = ['id' => 0, 'backend_id' => 'server', 'api_key' => '(server)', '_server' => true];
        $dsConfig = $this->createMock(DataSourceConfig::class);

        $probe = function (?array $row, ?array $server) use ($dsConfig): bool {
            $db = $this->createMock(DataSourceConnection::class);
            $db->method('fetchRow')->willReturn($row);
            $backends = $this->createMock(AiBackendResolver::class);
            $backends->method('serverBackend')->willReturn($server);
            return new AnalysisBackendProbe($db, $dsConfig, $backends)->hasUsableBackend();
        };

        $keyless = ['api_key' => '', 'profile_backend' => null, 'function_connection' => null];
        $this->assertTrue($probe($keyless, $serverRow), 'bez klíče → serverové připojení');
        $this->assertFalse($probe($keyless, null), 'server připojení nenabízí / bez příznaku');
        $this->assertFalse($probe(null, $serverRow), 'bez výchozího profilu nikdy');
        $this->assertFalse($probe(['profile_backend' => 7] + $keyless, $serverRow), 'profil ukazuje na připojení bez klíče');
        $this->assertFalse($probe(['function_connection' => 7] + $keyless, $serverRow), 'řádek funkce ukazuje na připojení bez klíče');
        $this->assertTrue($probe(['api_key' => 'enc', 'profile_backend' => 7, 'function_connection' => null], null), 'vlastní klíč stačí');
    }
}
