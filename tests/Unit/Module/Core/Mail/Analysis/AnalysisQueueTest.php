<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Analysis;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\Analysis\AnalysisQueue;
use Shipard\Module\Core\Mail\Analysis\AnalysisStates;

/**
 * Predikát fronty AI analýzy — každá podmínka zvlášť, shodně pro výdej
 * (`eligible`), počet (`countEligible`), gate runneru (`isEligible`)
 * i zaseklé zprávy (`stalled`). Reálné vyhodnocení SQL kryjí integrační
 * testy runneru.
 */
class AnalysisQueueTest extends TestCase
{
    private const NOW = '2026-10-09 12:00:00';

    /**
     * Zachytí argumenty všech čtyř dotazů.
     *
     * @return array<string, list<mixed>> klíč = metoda fronty
     */
    private function capturedArgs(): array
    {
        $db = $this->createMock(DataSourceConnection::class);
        $captured = [];
        $db->method('fetchAll')->willReturnCallback(
            static function (...$args) use (&$captured): array {
                $captured['eligible'] = $args;
                return [];
            },
        );
        $db->method('fetchRow')->willReturnCallback(
            static function (...$args) use (&$captured): array {
                $captured['stalled'] = $args;
                return ['cnt' => 0, 'oldest' => null];
            },
        );
        $single = 0;
        $db->method('fetchSingle')->willReturnCallback(
            static function (...$args) use (&$captured, &$single): int {
                $captured[$single === 0 ? 'countEligible' : 'isEligible'] = $args;
                $single++;
                return 0;
            },
        );

        $queue = new AnalysisQueue($db);
        $queue->eligible(5, self::NOW);
        $queue->countEligible(self::NOW);
        $queue->isEligible(42, self::NOW);
        $queue->stalled(900, self::NOW);

        return $captured;
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function predicateConditions(): iterable
    {
        yield 'stav Ve frontě' => ['m.analysis_state = %i', AnalysisStates::QUEUED];
        yield 'mimo Archiv a Koš' => ['AND m.docState NOT IN %in', [80, 90]];
        yield 'gate předzpracování' => ['AND m.preprocess_state NOT IN %in', AnalysisStates::PREPROCESS_BLOCKING_STATES];
        yield 'příznak zprávy' => ['AND (m.ai_analysis_enabled IS NULL OR m.ai_analysis_enabled = %i)', 1];
        yield 'schránka nebo explicitní povolení' => ['AND (mb.ai_analysis_disabled = %i OR m.ai_analysis_enabled = %i)', 0];
        yield 'bez aktivního claimu' => ['AND c.expires_at > %s', self::NOW];
        yield 'tabulka claimů' => ['SELECT 1 FROM %n c', 'core_mail_analysis_claims'];
    }

    #[DataProvider('predicateConditions')]
    public function testEveryQueryCarriesCondition(string $sqlFragment, mixed $param): void
    {
        foreach ($this->capturedArgs() as $method => $args) {
            $this->assertStringContainsString($sqlFragment, (string) $args[0], $method);
            $this->assertContains($param, $args, $method);
        }
    }

    public function testEveryQueryJoinsMailbox(): void
    {
        foreach ($this->capturedArgs() as $method => $args) {
            $this->assertStringContainsString('JOIN %n mb ON mb.id = m.mailbox', (string) $args[0], $method);
            $this->assertSame('core_mail_incoming_messages', $args[1], $method);
            $this->assertSame('core_mail_mailboxes', $args[2], $method);
        }
    }

    public function testEligibleOrdersOldestFirstAndLimits(): void
    {
        $args = $this->capturedArgs()['eligible'];

        $this->assertStringContainsString('ORDER BY m.received_at ASC, m.id ASC', (string) $args[0]);
        $this->assertStringContainsString('LIMIT %i', (string) $args[0]);
        $this->assertSame(5, end($args));
    }

    public function testIsEligibleRestrictsToMessageAndReturnsBool(): void
    {
        $args = $this->capturedArgs()['isEligible'];
        $this->assertStringContainsString('WHERE m.id = %i', (string) $args[0]);
        $this->assertSame(42, $args[3]);

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturnOnConsecutiveCalls(1, 0, '1');
        $queue = new AnalysisQueue($db);

        $this->assertTrue($queue->isEligible(42));
        $this->assertFalse($queue->isEligible(42));
        $this->assertTrue($queue->isEligible(42));
    }

    public function testStalledAddsModifiedCutoffAfterPredicateAndCasts(): void
    {
        $args = $this->capturedArgs()['stalled'];
        $sql = (string) $args[0];
        $this->assertStringContainsString('SELECT COUNT(*) AS cnt, MIN(m.modified) AS oldest', $sql);
        $this->assertStringContainsString("c.expires_at > %s
                )
                AND m.modified <= %s", $sql, 'cutoff až za celým predikátem');
        $this->assertSame('2026-10-09 11:45:00', end($args), 'cutoff = now − 900 s');

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['cnt' => '2', 'oldest' => new \DateTimeImmutable('2026-10-09 11:30:00')],
            ['cnt' => 0, 'oldest' => null],
            null,
        );
        $queue = new AnalysisQueue($db);
        $this->assertSame(['count' => 2, 'oldest' => '2026-10-09 11:30:00'], $queue->stalled(900));
        $this->assertSame(['count' => 0, 'oldest' => null], $queue->stalled(900));
        $this->assertSame(['count' => 0, 'oldest' => null], $queue->stalled(900));
    }

    public function testCountEligibleCastsToInt(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturn('3');

        $this->assertSame(3, new AnalysisQueue($db)->countEligible());
    }

    public function testEligibleExcludesGivenIdsOnlyWhenAsked(): void
    {
        $captured = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(static function (...$args) use (&$captured): array {
            $captured[] = $args;
            return [];
        });
        $queue = new AnalysisQueue($db);

        $queue->eligible(1, self::NOW, [42, 8]);
        $queue->eligible(1, self::NOW);
        $queue->eligible(1, self::NOW, []);

        $withExclude = $captured[0];
        $this->assertStringContainsString('AND m.id NOT IN %in', (string) $withExclude[0]);
        $this->assertStringContainsString('NOT IN %in
              ORDER BY', (string) $withExclude[0], 'vyloučení až za celým predikátem');
        $this->assertSame([42, 8], $withExclude[count($withExclude) - 2], 'parametr vyloučení před limitem');
        $this->assertSame(1, end($withExclude));

        foreach ([$captured[1], $captured[2]] as $args) {
            $this->assertStringNotContainsString('m.id NOT IN', (string) $args[0]);
            $this->assertNotContains([], $args);
        }
    }

    public function testEligibleReturnsRowsAsGiven(): void
    {
        $rows = [['ndx' => 7, 'subject' => 'x']];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn($rows);

        $this->assertSame($rows, new AnalysisQueue($db)->eligible(10));
    }
}
