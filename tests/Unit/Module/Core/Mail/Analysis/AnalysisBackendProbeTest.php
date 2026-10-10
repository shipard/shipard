<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Analysis;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\Analysis\AnalysisBackendProbe;

/** Použitelný backend = aktivní výchozí profil → aktivní backend s klíčem. */
class AnalysisBackendProbeTest extends TestCase
{
    public function testQueryJoinsDefaultActiveProfileWithActiveBackend(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(static function (...$args) use (&$captured): ?array {
            $captured = $args;
            return ['api_key' => 'v1:cipher'];
        });

        $this->assertTrue(new AnalysisBackendProbe($db)->hasUsableBackend());
        $this->assertStringContainsString('SELECT b.api_key FROM %n p JOIN %n b ON b.id = p.backend', (string) $captured[0]);
        $this->assertStringContainsString('p.is_default = %i AND p.is_active = %i AND b.is_active = %i', (string) $captured[0]);
        $this->assertSame(['core_mail_ai_profiles', 'core_ai_backends', 1, 1, 1], array_slice($captured, 1));
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
