<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Overview;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Overview\OverviewBlock;
use Shipard\Core\Overview\OverviewBlockRegistry;
use Shipard\Core\Overview\OverviewBlockResult;
use Shipard\Core\Overview\OverviewCollector;
use Shipard\Core\Overview\OverviewContext;
use Shipard\Core\Overview\OverviewItem;
use Shipard\Core\Overview\OverviewMessage;
use Shipard\Core\Reports\ReportRegistry;
use Shipard\Core\Reports\ReportRunner;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/**
 * Sběr Přehledu firmy (#120): pořadí sekcí a bloků, izolace výjimky,
 * vynechání prázdné sekce a nedostupného bloku, stav sekce
 * `unavailable` vs `ok` při smíšené sekci, neznámá sekce, fallback sekcí
 * bez compiled configu.
 */
final class OverviewCollectorTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = sys_get_temp_dir() . '/shpd_overview_' . uniqid('', true) . '.log';
        ErrorLogger::resetForTesting();
        ErrorLogger::setLogPath($this->logPath);
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }
    }

    public function testSectionsFollowConfigOrderAndBlocksFollowOrder(): void
    {
        $registry = new OverviewBlockRegistry();
        $registry->add('b.second', 'money', 20, self::block([self::item('m2')]));
        $registry->add('b.first', 'money', 10, self::block([self::item('m1')]));
        $registry->add('b.result', 'result', 10, self::block([self::item('r1')]));
        // receivables: žádný blok → sekce se neposílá

        $out = (new OverviewCollector())->collect($this->context(), $registry);

        $this->assertSame('2026-10-16', $out['asOf']);
        $this->assertSame(['money', 'result'], array_column($out['sections'], 'id'));
        $this->assertSame(['Peníze', 'Výsledek hospodaření'], array_column($out['sections'], 'title'));
        $this->assertSame(['m1', 'm2'], array_column($out['sections'][0]['items'], 'id'));
        $this->assertSame('ok', $out['sections'][0]['state']);
        $this->assertSame([], $out['sections'][0]['messages']);
    }

    public function testItemSerialization(): void
    {
        $registry = new OverviewBlockRegistry();
        $registry->add('b', 'money', 10, self::block([new OverviewItem(
            'bank:3', 'Běžný účet', 'bank', OverviewItem::amount(125000.0, 'czk'), '2026-10-15',
            OverviewItem::STATE_STALE, 'Podle výpisu č. 182', null,
            ['kind' => 'open_detail', 'target' => ['viewerId' => 'economy.bank.statements', 'recordId' => 812]],
        )]));

        $item = (new OverviewCollector())->collect($this->context(), $registry)['sections'][0]['items'][0];

        $this->assertSame([
            'id'      => 'bank:3',
            'label'   => 'Běžný účet',
            'kind'    => 'bank',
            'amount'  => ['value' => 125000.0, 'currency' => 'CZK'],
            'asOf'    => '2026-10-15',
            'state'   => 'stale',
            'note'    => 'Podle výpisu č. 182',
            'dueDate' => null,
            'link'    => ['kind' => 'open_detail', 'target' => ['viewerId' => 'economy.bank.statements', 'recordId' => 812]],
        ], $item);
    }

    public function testFailingBlockIsIsolated(): void
    {
        $registry = new OverviewBlockRegistry();
        $registry->add('b.bank', 'money', 10, self::block([self::item('bank')]));
        $registry->add('b.cash', 'money', 20, new class implements OverviewBlock {
            public function isAvailable(OverviewContext $ctx): bool { return true; }
            public function collect(OverviewContext $ctx): OverviewBlockResult { throw new \RuntimeException('boom'); }
        });
        $registry->add('b.result', 'result', 10, self::block([self::item('r1')]));

        $out = (new OverviewCollector())->collect($this->context(), $registry);

        $money = $out['sections'][0];
        $this->assertSame('error', $money['state']);
        $this->assertSame(['bank'], array_column($money['items'], 'id'));
        $this->assertSame([['severity' => 'error', 'code' => 'blockFailed', 'text' => 'Část této sekce se nepodařilo spočítat.', 'link' => null]], $money['messages']);
        $this->assertSame('ok', $out['sections'][1]['state']);
        $this->assertStringContainsString('Overview block failed: b.cash', (string) file_get_contents($this->logPath));
    }

    public function testUnavailableBlockIsSkippedWithoutTrace(): void
    {
        $registry = new OverviewBlockRegistry();
        $registry->add('b.cash', 'money', 10, new class implements OverviewBlock {
            public function isAvailable(OverviewContext $ctx): bool { return false; }
            public function collect(OverviewContext $ctx): OverviewBlockResult { throw new \LogicException('must not be called'); }
        });

        $out = (new OverviewCollector())->collect($this->context(), $registry);

        $this->assertSame([], $out['sections']);
        $this->assertFileDoesNotExist($this->logPath);
    }

    public function testSectionStateUnavailableOnlyWithoutItems(): void
    {
        $missing = new OverviewMessage(OverviewMessage::SEVERITY_WARNING, 'openingMissing', 'Počáteční stavy nejsou převedené.');
        $registry = new OverviewBlockRegistry();
        // Pohledávky: jen pojistka → unavailable + zpráva
        $registry->add('b.rec', 'receivables', 10, self::block([], OverviewBlockResult::unavailable($missing)));
        // Peníze: banka má položky, pokladna pojistku → ok + zpráva vedle položek
        $registry->add('b.bank', 'money', 10, self::block([self::item('bank')]));
        $registry->add('b.cash', 'money', 20, self::block([], OverviewBlockResult::unavailable($missing)));

        $out = (new OverviewCollector())->collect($this->context(), $registry);

        [$money, $receivables] = $out['sections'];
        $this->assertSame('money', $money['id']);
        $this->assertSame('ok', $money['state']);
        $this->assertSame(['bank'], array_column($money['items'], 'id'));
        $this->assertSame(['openingMissing'], array_column($money['messages'], 'code'));

        $this->assertSame('receivables', $receivables['id']);
        $this->assertSame('unavailable', $receivables['state']);
        $this->assertSame([], $receivables['items']);
        $this->assertSame(['openingMissing'], array_column($receivables['messages'], 'code'));
    }

    public function testBlockWithUnknownSectionIsSkippedWithWarning(): void
    {
        $registry = new OverviewBlockRegistry();
        $registry->add('b.x', 'nonsense', 10, self::block([self::item('x')]));

        $out = (new OverviewCollector())->collect($this->context(), $registry);

        $this->assertSame([], $out['sections']);
        $this->assertStringContainsString('Overview block declares unknown section', (string) file_get_contents($this->logPath));
    }

    public function testFallbackSectionsWithoutCompiledConfig(): void
    {
        $registry = new OverviewBlockRegistry();
        $registry->add('b.result', 'result', 10, self::block([self::item('r1')]));
        $registry->add('b.money', 'money', 10, self::block([self::item('m1')]));

        $out = (new OverviewCollector())->collect($this->context(withConfig: false), $registry);

        $this->assertSame(['money', 'result'], array_column($out['sections'], 'id'));
        $this->assertSame(['Money', 'Profit or loss'], array_column($out['sections'], 'title'));
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function context(bool $withConfig = true): OverviewContext
    {
        $config = null;
        if ($withConfig) {
            $config = ConfigRuntimeFactory::fromItems([
                OverviewCollector::SECTIONS_ITEM => ['sections' => [
                    ['id' => 'result', 'order' => 50, 'name' => 'Výsledek hospodaření'],
                    ['id' => 'money', 'order' => 10, 'name' => 'Peníze'],
                    ['id' => 'receivables', 'order' => 20, 'name' => 'Pohledávky'],
                ]],
                OverviewCollector::TEXTS_ITEM => [
                    'section.blockFailed' => ['text' => 'Část této sekce se nepodařilo spočítat.'],
                ],
            ]);
        }
        $db = $this->createMock(DataSourceConnection::class);
        return new OverviewContext(
            $db,
            $config,
            'cs',
            new \DateTimeImmutable('2026-10-16'),
            new ReportRunner(new ReportRegistry(), $db, null, 'test-ds'),
            [],
            'cz',
        );
    }

    private static function item(string $id): OverviewItem
    {
        return new OverviewItem($id, $id, 'test', OverviewItem::amount(1.0, 'czk'), '2026-10-16');
    }

    /** @param list<OverviewItem> $items */
    private static function block(array $items, ?OverviewBlockResult $result = null): OverviewBlock
    {
        return new class($result ?? new OverviewBlockResult($items)) implements OverviewBlock {
            public function __construct(private readonly OverviewBlockResult $result) {}
            public function isAvailable(OverviewContext $ctx): bool { return true; }
            public function collect(OverviewContext $ctx): OverviewBlockResult { return $this->result; }
        };
    }
}
