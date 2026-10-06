<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Mcp;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Mcp\FeedCardsTool;
use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Core\Alerts\AlertCheckRegistry;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Feed\FeedCollector;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModuleDefinition;

/**
 * FeedCardsTool (UI shells Fáze 5) — čtecí nástroj nad FeedCollectorem:
 * sekční filtr, projekce polí (žádná id/context/actions), degradace bez
 * filtru. Řazení a strop karet testuje FeedCollectorTest.
 */
final class FeedCardsToolTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = sys_get_temp_dir() . '/shpd_feedtool_' . uniqid('', true) . '.log';
        ErrorLogger::resetForTesting();
        ErrorLogger::setLogPath($this->logPath);
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        @unlink($this->logPath);
    }

    /** @return array<string, TableDefinition> */
    private function tables(string ...$names): array
    {
        $map = [];
        foreach ($names as $name) {
            $map[$name] = new TableDefinition(
                tableId: 999,
                name: $name,
                displayPattern: null,
                columnGroups: [],
                columns: [],
                indexes: [],
                childTables: [],
                docStates: null,
            );
        }
        return $map;
    }

    /** DB mock s jedním alertem (check `chk`). */
    private function alertDb(): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql): array {
                if (str_contains($sql, 'core_alerts_alerts') && str_contains($sql, 'GROUP BY')) {
                    return [[
                        'check_id' => 'chk', 'cnt' => 1, 'max_severity' => 30,
                        'last_at' => '2026-06-28 08:00:00', 'first_at' => '2026-06-28 08:00:00',
                    ]];
                }
                if (str_contains($sql, 'core_alerts_alerts')) {
                    return [[
                        'id' => 7, 'check_id' => 'chk', 'title' => 'Chyba', 'message' => 'm',
                        'severity' => 30, 'actions' => null,
                        'first_seen_at' => '2026-06-28 08:00:00', 'last_seen_at' => '2026-06-28 08:00:00',
                    ]];
                }
                return [];
            },
        );
        return $db;
    }

    private function accountingRegistry(): AlertCheckRegistry
    {
        return new AlertCheckRegistry(
            [ModuleDefinition::fromArray([
                'id'          => 'economy.accounting',
                'name'        => 'Accounting',
                'alertChecks' => [[
                    'id'         => 'chk',
                    'name'       => 'Chyby účtování',
                    'class'      => 'X',
                    'interval'   => '1h',
                    'navSection' => 'accounting',
                ]],
            ])],
            'cs',
        );
    }

    private function ctx(): McpInvocationContext
    {
        return new McpInvocationContext(
            new AuthContext(true, 100, 'session', 'shpd_st_xxx'),
            $this->alertDb(),
            $this->tables('core_alerts_alerts'),
            null,
        );
    }

    public function testReturnsProjectedCardsWithoutFilter(): void
    {
        $tool = new FeedCardsTool('cs', $this->accountingRegistry());
        $result = $tool->call([], $this->ctx());

        $this->assertCount(1, $result['items']);
        $item = $result['items'][0];
        // Projekce (R3): jen kind/feedSection/title/subtitle/navSection/
        // timestamp — žádná interní pole (id, context, actions, icon, stateStyle).
        $this->assertSame(
            ['kind', 'feedSection', 'title', 'subtitle', 'navSection', 'timestamp'],
            array_keys($item),
        );
        $this->assertSame('urgent', $item['kind']);
        $this->assertSame('alerts', $item['feedSection']);
        $this->assertSame('Chyba', $item['title']);
        $this->assertSame('accounting', $item['navSection']);
        $this->assertSame(1, $result['pagination']['returned']);
        $this->assertSame(FeedCollector::MAX_CARDS_PER_SECTION, $result['pagination']['limit']);
        $this->assertFalse($result['pagination']['has_more']);
        $this->assertStringContainsString('1 karet', $result['summary']);
        $this->assertStringNotContainsString('zkrácený', $result['summary']);
    }

    public function testHasMoreWhenSectionExceedsCap(): void
    {
        // 31 checků po jednom alertu (pod prahem agregace) → sekce Upozornění
        // 31 karet, strop 30 → has_more true a upozornění v summary (#101).
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql): array {
                if (!str_contains($sql, 'core_alerts_alerts')) {
                    return [];
                }
                $rows = [];
                for ($i = 1; $i <= 31; $i++) {
                    $rows[] = str_contains($sql, 'GROUP BY')
                        ? [
                            'check_id' => "chk$i", 'cnt' => 1, 'max_severity' => 20,
                            'last_at' => '2026-06-28 08:00:00', 'first_at' => '2026-06-28 08:00:00',
                        ]
                        : [
                            'id' => $i, 'check_id' => "chk$i", 'title' => "Alert $i", 'message' => 'm',
                            'severity' => 20, 'actions' => null,
                            'first_seen_at' => '2026-06-28 08:00:00', 'last_seen_at' => '2026-06-28 08:00:00',
                        ];
                }
                return $rows;
            },
        );
        $ctx = new McpInvocationContext(
            new AuthContext(true, 100, 'session', 'shpd_st_xxx'),
            $db,
            $this->tables('core_alerts_alerts'),
            null,
        );

        $result = (new FeedCardsTool('cs', null))->call([], $ctx);

        $this->assertCount(30, $result['items']);
        $this->assertSame(30, $result['pagination']['returned']);
        $this->assertTrue($result['pagination']['has_more']);
        $this->assertStringContainsString('zkrácený', $result['summary']);
    }

    public function testSectionFilterMatchesAndMisses(): void
    {
        $tool = new FeedCardsTool('cs', $this->accountingRegistry());

        $hit = $tool->call(['section' => 'accounting'], $this->ctx());
        $this->assertCount(1, $hit['items']);

        $miss = $tool->call(['section' => 'purchase'], $this->ctx());
        $this->assertSame([], $miss['items']);
        $this->assertStringContainsString('Žádné karty', $miss['summary']);
    }

    public function testWithoutAlertRegistryCardsHaveNullNavSection(): void
    {
        // Bez injektovaného AlertCheckRegistry (degradace) je navSection
        // alertů null — sekční filtr je mine, bez filtru se vrátí.
        $tool = new FeedCardsTool('cs', null);

        $all = $tool->call([], $this->ctx());
        $this->assertCount(1, $all['items']);
        $this->assertNull($all['items'][0]['navSection']);

        $filtered = $tool->call(['section' => 'accounting'], $this->ctx());
        $this->assertSame([], $filtered['items']);
    }
}
