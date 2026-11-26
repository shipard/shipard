<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders\Upgrade;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\WorkOrders\Upgrade\LegacyInvoicingAlertsCleanup;

/** Úklid osiřelých alertů po přejmenování checků periodické fakturace (#175). */
class LegacyInvoicingAlertsCleanupTest extends TestCase
{
    public function testResolvesOnlyOpenAlertsWithTheLegacyPrefix(): void
    {
        $calls = [];
        $cleanup = new LegacyInvoicingAlertsCleanup(
            $this->createMock(Connection::class),
            static function (string $sql, mixed ...$args) use (&$calls): int {
                $calls[] = [$sql, $args];
                return 3;
            },
        );

        $this->assertSame(3, $cleanup->run('2026-11-26 10:00:00'));
        $this->assertCount(1, $calls);
        [$sql, $args] = $calls[0];
        $this->assertSame('UPDATE %n SET %a WHERE [check_id] LIKE %like~ AND [alert_state] IN %in', $sql);
        $this->assertSame(
            [
                'core_alerts_alerts',
                ['alert_state' => 70, 'resolved_at' => '2026-11-26 10:00:00'],
                'economy.workorders.',
                [10, 20],
            ],
            $args,
        );
    }

    public function testPrefixMatchesNoCurrentCheck(): void
    {
        // Prefix nesmí zasáhnout živý check — nová id jsou economy.periodicinvoicing.*.
        $modules = __DIR__ . '/../../../../../../modules';
        foreach (glob($modules . '/*/*/module.jsonc') ?: [] as $file) {
            foreach (JsoncParser::parseFile($file)['alertChecks'] ?? [] as $check) {
                $this->assertStringStartsNotWith(LegacyInvoicingAlertsCleanup::CHECK_ID_PREFIX, (string) $check['id'], $file);
            }
        }
        $ids = array_column(JsoncParser::parseFile($modules . '/economy/periodicInvoicing/module.jsonc')['alertChecks'], 'id');
        $this->assertCount(4, $ids);
    }
}
