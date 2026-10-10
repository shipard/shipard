<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Analysis;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\Analysis\AnalysisBackendProbe;

/**
 * Použitelné připojení = aktivní výchozí profil → aktivní připojení
 * s klíčem; připojení jako v resolveru (tasks/ai-functions.md D40):
 * přepsání v profilu → řádek funkce mail.extraction → výchozí připojení.
 */
class AnalysisBackendProbeTest extends TestCase
{
    public function testQueryFollowsTheResolverConnectionOrder(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(static function (...$args) use (&$captured): ?array {
            $captured = $args;
            return ['api_key' => 'v1:cipher'];
        });

        $this->assertTrue(new AnalysisBackendProbe($db)->hasUsableBackend());
        $sql = (string) $captured[0];
        $this->assertStringContainsString('SELECT b.api_key FROM %n p', $sql);
        $this->assertStringContainsString('LEFT JOIN %n f ON f.function_id = %s', $sql);
        $this->assertStringContainsString('COALESCE(p.backend, f.connection,', $sql);
        $this->assertStringContainsString('d.is_default = %i AND d.is_active = %i LIMIT 1', $sql);
        $this->assertStringContainsString('p.is_default = %i AND p.is_active = %i AND b.is_active = %i', $sql);
        $this->assertSame(
            ['core_mail_ai_profiles', 'core_ai_function_settings', 'mail.extraction', 'core_ai_backends', 'core_ai_backends', 1, 1, 1, 1, 1],
            array_slice($captured, 1),
        );
    }

    public function testMissingRowOrEmptyKeyIsNotUsable(): void
    {
        foreach ([null, [], ['api_key' => null], ['api_key' => '']] as $row) {
            $db = $this->createMock(DataSourceConnection::class);
            $db->method('fetchRow')->willReturn($row);
            $this->assertFalse(new AnalysisBackendProbe($db)->hasUsableBackend(), json_encode($row));
        }
    }
}
