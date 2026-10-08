<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders\Invoicing;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\WorkOrders\Invoicing\Period;
use Shipard\Module\Economy\WorkOrders\Invoicing\PeriodRepository;

/**
 * Evidence období: zápisy (INSERT IGNORE přes UNIQUE, přechody stavů),
 * odvozené „zastaveno“ z dokladu v koši nebo chybějícího.
 */
class PeriodRepositoryTest extends TestCase
{
    /** Repository se zachycenými zápisy v `$repo->calls`. */
    private function repo(array $fetchAll = [], ?array $fetch = null): PeriodRepository
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturn($fetchAll);
        $db->method('fetch')->willReturn($fetch === null ? null : new Row($fetch));
        return new class($db) extends PeriodRepository {
            /** @var list<array> */
            public array $calls = [];

            protected function execute(string $sql, mixed ...$args): void
            {
                $this->calls[] = [$sql, ...$args];
            }
        };
    }

    public function testEnsurePlannedInsertsIgnoringExisting(): void
    {
        $repo = $this->repo();
        $repo->ensurePlanned(6, [new Period('2026-08-01', '2026-08-31'), new Period('2026-09-01', '2026-09-30')], '2026-10-08 03:17:00');
        $calls = $repo->calls;

        $this->assertCount(2, $calls);
        $this->assertStringContainsString('INSERT IGNORE INTO [economy_work_orders_periods]', $calls[0][0]);
        $this->assertSame(
            ['work_order' => 6, 'period_from' => '2026-08-01', 'period_to' => '2026-08-31', 'state' => 'planned', 'created_at' => '2026-10-08 03:17:00', 'updated_at' => '2026-10-08 03:17:00'],
            $calls[0][1],
        );
        $this->assertSame('2026-09-01', $calls[1][1]['period_from']);
    }

    public function testStateTransitionsWriteExpectedColumns(): void
    {
        $repo = $this->repo();
        $now = '2026-10-08 03:17:00';
        $repo->markIssued(1, 100, 'abc', $now);
        $repo->markWaiting(2, 101, 'def', 'Čekám na podklady', $now, null);
        $repo->markPlanned(3, PeriodRepository::RESULT_FAILED, 'Chyba', $now);
        $repo->markResult(4, PeriodRepository::RESULT_EDITED, 'Upraveno', $now);
        $repo->unlinkDoc(5, $now);
        $calls = $repo->calls;

        $this->assertCount(5, $calls);
        foreach ($calls as $call) {
            $this->assertStringContainsString('UPDATE [economy_work_orders_periods] SET %a WHERE [id] = %i', $call[0]);
        }
        $this->assertSame(['state' => 'issued', 'doc' => 100, 'content_hash' => 'abc', 'result' => null, 'message' => null, 'waiting_since' => null, 'updated_at' => $now], $calls[0][1]);
        $this->assertSame(1, $calls[0][2]);
        $this->assertSame('waiting', $calls[1][1]['state']);
        $this->assertSame('waiting', $calls[1][1]['result']);
        $this->assertSame($now, $calls[1][1]['waiting_since']);
        $this->assertSame(['state' => 'planned', 'doc' => null, 'result' => 'failed', 'message' => 'Chyba', 'updated_at' => $now], $calls[2][1]);
        $this->assertSame(['result' => 'edited', 'message' => 'Upraveno', 'updated_at' => $now], $calls[3][1]);
        $this->assertSame(['state' => 'planned', 'doc' => null, 'content_hash' => null, 'result' => null, 'message' => null, 'waiting_since' => null, 'updated_at' => $now], $calls[4][1]);
    }

    public function testWaitingKeepsOriginalWaitingSince(): void
    {
        $repo = $this->repo();
        $repo->markWaiting(2, 101, 'def', null, '2026-10-09 03:17:00', '2026-10-01 03:17:00');
        $this->assertSame('2026-10-01 03:17:00', $repo->calls[0][1]['waiting_since']);
    }

    public function testStoppedIsDerivedFromDeletedOrMissingDocument(): void
    {
        $docs = [100 => ['id' => 100, 'docState' => 10], 101 => ['id' => 101, 'docState' => 90]];
        $this->assertFalse(PeriodRepository::isStopped(['state' => 'issued', 'doc' => 100], $docs));
        $this->assertTrue(PeriodRepository::isStopped(['state' => 'issued', 'doc' => 101], $docs));
        $this->assertTrue(PeriodRepository::isStopped(['state' => 'issued', 'doc' => 999], $docs));
        $this->assertFalse(PeriodRepository::isStopped(['state' => 'planned', 'doc' => null], $docs));
        $this->assertSame('stopped', PeriodRepository::effectiveState(['state' => 'issued', 'doc' => 101], $docs));
        $this->assertSame('waiting', PeriodRepository::effectiveState(['state' => 'waiting', 'doc' => 100], $docs));
    }

    public function testLockForUpdateAndDocInfo(): void
    {
        $repo = $this->repo(
            fetchAll: [new Row(['id' => 100, 'docState' => 10, 'doc_type' => 'invno', 'doc_number' => null, 'total_amount' => '14000.00', 'doc_currency' => 'czk'])],
            fetch: ['id' => 7, 'state' => 'planned'],
        );
        $this->assertSame(['id' => 7, 'state' => 'planned'], $repo->lockForUpdate(7));
        $info = $repo->docInfo([100, 100, 0]);
        $this->assertSame([100], array_keys($info));
        $this->assertSame('invno', $info[100]['doc_type']);
        $this->assertSame([], $repo->docInfo([]));
    }
}
