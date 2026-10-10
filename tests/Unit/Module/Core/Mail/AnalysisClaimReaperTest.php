<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\Analysis\AnalysisResultWriter;
use Shipard\Module\Core\Mail\Analysis\AnalysisRunner;
use Shipard\Module\Core\Mail\AnalysisClaimReaper;

/**
 * Reaper vypršelých claimů: uvolnění, návrat do fronty jen ze stavu 20,
 * transakce; strop vypršelých claimů (tasks/mail-analysis-queue-drain.md
 * D26) — třetí za hodinu = stav 70 + selhaný běh přes writer.
 */
class AnalysisClaimReaperTest extends TestCase
{
    private const NOW = '2026-04-26 10:05:00';

    private AnalysisResultWriter&MockObject $results;

    protected function setUp(): void
    {
        $this->results = $this->createMock(AnalysisResultWriter::class);
    }

    /**
     * Spojení s jedním vypršelým claimem; `fetchSingle` odpovídá na dotaz
     * COUNT (vypršelé claimy v okně) a na dotaz stavu zprávy.
     *
     * @param list<list<mixed>> $singleCalls zachycené argumenty fetchSingle
     */
    private function dbWithOneClaim(int $expiredCount, int $messageState, array &$singleCalls): DataSourceConnection&MockObject
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([
            ['id' => 42, 'message' => 100, 'analyzer_id' => 'uuid-analyzer-1', 'claimed_at' => '2026-04-26 10:00:00'],
        ]);
        $db->method('fetchSingle')->willReturnCallback(
            static function (...$args) use ($expiredCount, $messageState, &$singleCalls): int {
                $singleCalls[] = $args;
                return str_contains((string) $args[0], 'COUNT(*)') ? $expiredCount : $messageState;
            },
        );
        return $db;
    }

    public function testReturnsEmptyWhenNoExpiredClaims(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);
        $db->expects($this->never())->method('begin');
        $db->expects($this->never())->method('updateWhere');
        $db->expects($this->never())->method('execute');

        $reaper = new AnalysisClaimReaper($db, $this->results);
        $result = $reaper->reapExpired();

        $this->assertSame([], $result);
    }

    public function testReleasesClaimAndRequeuesMessage(): void
    {
        $singleCalls = [];
        $db = $this->dbWithOneClaim(1, 20, $singleCalls);

        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');

        $db->expects($this->once())
            ->method('updateWhere')
            ->with(
                'core_mail_analysis_claims',
                $this->callback(function (array $data): bool {
                    return $data['released'] === 1
                        && $data['release_reason'] === AnalysisClaimReaper::RELEASE_REASON_EXPIRED
                        && $data['released_at'] === self::NOW;
                }),
                '%n = %i',
                'id',
                42,
            );

        $db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('UPDATE'),
                'core_mail_incoming_messages',
                'analysis_state', 10,
                'modified', self::NOW,
                'id', 100,
                'analysis_state', 20, // jen pokud se analýza pořád "Analyzuje"
            );
        $this->results->expects($this->never())->method('recordFailedRun');

        $reaper = new AnalysisClaimReaper($db, $this->results);
        $result = $reaper->reapExpired(new \DateTimeImmutable(self::NOW));

        $this->assertCount(1, $result);
        $this->assertSame(42, $result[0]['claim_id']);
        $this->assertSame(100, $result[0]['message_id']);
        $this->assertSame('uuid-analyzer-1', $result[0]['analyzer_id']);
        $this->assertSame(300, $result[0]['duration_seconds']);
        $this->assertFalse($result[0]['failed']);
    }

    public function testExpiryCountUsesHourWindowOfExpiredReleases(): void
    {
        $singleCalls = [];
        $db = $this->dbWithOneClaim(2, 20, $singleCalls);
        $this->results->expects($this->never())->method('recordFailedRun');

        $result = new AnalysisClaimReaper($db, $this->results)->reapExpired(new \DateTimeImmutable(self::NOW));

        $this->assertFalse($result[0]['failed'], 'druhé vypršení ještě vrací do fronty');
        $this->assertCount(1, $singleCalls, 'stav zprávy se pod stropem nečte');
        $count = $singleCalls[0];
        $this->assertStringContainsString('COUNT(*)', (string) $count[0]);
        $this->assertSame('core_mail_analysis_claims', $count[1]);
        $this->assertContains(100, $count);
        $this->assertContains(AnalysisClaimReaper::RELEASE_REASON_EXPIRED, $count);
        $this->assertSame('2026-04-26 09:05:00', end($count), 'práh = teď − FAILURE_WINDOW_SECONDS');
        $this->assertSame(3600, AnalysisRunner::FAILURE_WINDOW_SECONDS);
    }

    public function testThirdExpiryWithinHourFailsMessageAndRecordsRun(): void
    {
        $singleCalls = [];
        $db = $this->dbWithOneClaim(AnalysisRunner::MAX_FAILURES_PER_HOUR, 20, $singleCalls);
        $db->expects($this->once())->method('updateWhere');
        $db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('UPDATE'),
                'core_mail_incoming_messages',
                'analysis_state', 70,
                'modified', self::NOW,
                'id', 100,
                'analysis_state', 20,
            );
        $this->results->expects($this->once())->method('recordFailedRun')->with(
            100,
            'ai_error',
            'analysis did not finish 3 times within an hour (claim expired)',
            null,
            null,
            null,
            null, // created_by = NULL
            self::NOW,
        );
        $db->expects($this->once())->method('commit');

        $result = new AnalysisClaimReaper($db, $this->results)->reapExpired(new \DateTimeImmutable(self::NOW));

        $this->assertTrue($result[0]['failed']);
        $this->assertStringContainsString('FOR UPDATE', (string) $singleCalls[1][0], 'řádek zprávy se před přepnutím zamkne');
    }

    public function testThirdExpiryLeavesMessageOutsideAnalyzingUntouched(): void
    {
        $singleCalls = [];
        $db = $this->dbWithOneClaim(AnalysisRunner::MAX_FAILURES_PER_HOUR, 30, $singleCalls);
        $db->expects($this->once())->method('updateWhere');
        $db->expects($this->never())->method('execute');
        $this->results->expects($this->never())->method('recordFailedRun');
        $db->expects($this->once())->method('commit');

        $result = new AnalysisClaimReaper($db, $this->results)->reapExpired(new \DateTimeImmutable(self::NOW));

        $this->assertFalse($result[0]['failed']);
    }

    public function testRollsBackOnError(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([
            [
                'id' => 1,
                'message' => 10,
                'analyzer_id' => 'a',
                'claimed_at' => '2026-04-26 10:00:00',
            ],
        ]);
        $db->expects($this->once())->method('begin');
        $db->method('updateWhere')->willThrowException(new \RuntimeException('DB down'));
        $db->expects($this->never())->method('commit');
        $db->expects($this->once())->method('rollback');

        $reaper = new AnalysisClaimReaper($db, $this->results);

        $this->expectException(\RuntimeException::class);
        $reaper->reapExpired();
    }

    public function testRollsBackWhenFailedRunCannotBeRecorded(): void
    {
        $singleCalls = [];
        $db = $this->dbWithOneClaim(AnalysisRunner::MAX_FAILURES_PER_HOUR, 20, $singleCalls);
        $this->results->method('recordFailedRun')->willThrowException(new \RuntimeException('insert failed'));
        $db->expects($this->never())->method('commit');
        $db->expects($this->once())->method('rollback');

        $this->expectException(\RuntimeException::class);
        new AnalysisClaimReaper($db, $this->results)->reapExpired(new \DateTimeImmutable(self::NOW));
    }

    public function testProcessesMultipleExpiredClaimsInOneTransaction(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([
            ['id' => 1, 'message' => 10, 'analyzer_id' => 'a', 'claimed_at' => '2026-04-26 10:00:00'],
            ['id' => 2, 'message' => 20, 'analyzer_id' => 'b', 'claimed_at' => '2026-04-26 10:01:00'],
            ['id' => 3, 'message' => 30, 'analyzer_id' => 'c', 'claimed_at' => '2026-04-26 10:02:00'],
        ]);
        $db->method('fetchSingle')->willReturn(1);
        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');
        $db->expects($this->exactly(3))->method('updateWhere');
        $db->expects($this->exactly(3))->method('execute');

        $reaper = new AnalysisClaimReaper($db, $this->results);
        $result = $reaper->reapExpired(new \DateTimeImmutable('2026-04-26 10:10:00'));

        $this->assertCount(3, $result);
    }
}
