<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Numbering;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Numbering\SequenceCounter;
use Shipard\Core\Numbering\SequenceStorage;

/**
 * Sled SQL čítače nad mock DB. Zápisy jdou přes injektovaný executor
 * (Connection::query je final, mock ho nenahradí) — výchozí executor
 * pokrývá až běh nad skutečným zdrojem dat.
 */
final class SequenceCounterTest extends TestCase
{
    /** @var list<array{sql: string, args: array}> */
    private array $executed = [];

    private function docsStorage(): SequenceStorage
    {
        return new SequenceStorage(
            countersTable: 'docs_core_number_counters',
            counterSeriesColumn: 'number_series',
            counterScopeColumn: 'fiscal_year',
            counterValueColumn: 'last_assigned',
            recordsTable: 'docs_core_heads',
            recordSeriesColumn: 'number_series',
            recordScopeColumn: 'fiscal_year',
            recordSequenceColumn: 'sequence_number',
        );
    }

    private function counter(Connection $db, ?SequenceStorage $storage = null, ?\Closure $execute = null): SequenceCounter
    {
        $this->executed = [];
        return new SequenceCounter(
            $db,
            $storage ?? $this->docsStorage(),
            $execute ?? function (mixed ...$args): void {
                $this->executed[] = ['sql' => (string) $args[0], 'args' => array_slice($args, 1)];
            },
        );
    }

    // ── next ───────────────────────────────────────────────────────────────

    public function testNextLocksCounterAndReturnsIncrementedValue(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('fetch')
            ->with($this->stringContains('FOR UPDATE'), 1, 100)
            ->willReturn(new Row(['last_assigned' => 4]));
        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');
        $db->expects($this->never())->method('rollback');

        $this->assertSame(5, $this->counter($db)->next(1, 100));

        $this->assertCount(2, $this->executed);
        $this->assertStringContainsString('INSERT IGNORE INTO [docs_core_number_counters]', $this->executed[0]['sql']);
        $this->assertSame([1, 100], $this->executed[0]['args']);
        $this->assertStringContainsString('SET [last_assigned] = %i', $this->executed[1]['sql']);
        $this->assertStringContainsString('[fiscal_year] <=> %iN', $this->executed[1]['sql']);
        $this->assertSame([5, 1, 100], $this->executed[1]['args']);
    }

    public function testNextStartsAtOneForFreshCounterAndNullScope(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->with($this->anything(), 7, null)->willReturn(new Row(['last_assigned' => 0]));

        $this->assertSame(1, $this->counter($db)->next(7, null));
        $this->assertSame([7, null], $this->executed[0]['args']);
        $this->assertSame([1, 7, null], $this->executed[1]['args']);
    }

    public function testNextInsideExternalTransactionNeverBeginsOrCommits(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['last_assigned' => 2]));
        $db->expects($this->never())->method('begin');
        $db->expects($this->never())->method('commit');
        $db->expects($this->never())->method('rollback');

        $this->assertSame(3, $this->counter($db)->next(1, 100, ownTransaction: false));
        $this->assertCount(2, $this->executed);
    }

    public function testNextRollsBackOwnTransactionOnFailure(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['last_assigned' => 2]));
        $db->expects($this->once())->method('begin');
        $db->expects($this->never())->method('commit');
        $db->expects($this->once())->method('rollback');

        $failing = $this->counter($db, execute: static function (mixed ...$args): void {
            if (str_contains((string) $args[0], 'UPDATE')) {
                throw new \RuntimeException('deadlock');
            }
        });

        $this->expectException(\RuntimeException::class);
        $failing->next(1, 100);
    }

    // ── syncImported ───────────────────────────────────────────────────────

    public function testSyncImportedEmitsInsertIgnoreAndGreatestWithoutTransaction(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('begin');
        $db->expects($this->never())->method('fetch');

        $this->counter($db)->syncImported(1, 100, 42);

        $this->assertCount(2, $this->executed);
        $this->assertStringContainsString('INSERT IGNORE', $this->executed[0]['sql']);
        $this->assertSame([1, 100], $this->executed[0]['args']);
        $this->assertStringContainsString('GREATEST([last_assigned], %i)', $this->executed[1]['sql']);
        $this->assertSame([42, 1, 100], $this->executed[1]['args']);
    }

    // ── maxSequence ────────────────────────────────────────────────────────

    public function testMaxSequenceReadsRecordsTable(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('fetch')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('MAX([sequence_number])'),
                    $this->stringContains('FROM [docs_core_heads]'),
                    $this->stringContains('[fiscal_year] <=> %iN'),
                ),
                1, null,
            )
            ->willReturn(new Row(['max_seq' => 7]));

        $this->assertSame(7, $this->counter($db)->maxSequence(1, null));
        $this->assertSame([], $this->executed);
    }

    public function testMaxSequenceIsZeroWithoutRecords(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['max_seq' => null]));
        $this->assertSame(0, $this->counter($db)->maxSequence(1, 100));

        $empty = $this->createMock(Connection::class);
        $empty->method('fetch')->willReturn(null);
        $this->assertSame(0, $this->counter($empty)->maxSequence(1, 100));
    }

    // ── release ────────────────────────────────────────────────────────────

    public function testReleaseDecrementsOnlyWhenCounterSitsOnSequence(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');

        $this->counter($db)->release(1, 100, 5);

        $this->assertCount(1, $this->executed);
        $this->assertStringContainsString('SET [last_assigned] = [last_assigned] - 1', $this->executed[0]['sql']);
        $this->assertStringContainsString('AND [last_assigned] = %i', $this->executed[0]['sql']);
        $this->assertSame([1, 100, 5], $this->executed[0]['args']);
    }

    public function testReleaseInsideExternalTransactionNeverBeginsOrCommits(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('begin');
        $db->expects($this->never())->method('commit');

        $this->counter($db)->release(1, null, 5, ownTransaction: false);

        $this->assertSame([1, null, 5], $this->executed[0]['args']);
    }

    // ── storage ────────────────────────────────────────────────────────────

    public function testTableAndColumnNamesComeFromStorage(): void
    {
        $storage = new SequenceStorage(
            countersTable: 'economy_work_orders_number_counters',
            counterSeriesColumn: 'number_series',
            counterScopeColumn: 'fiscal_year',
            counterValueColumn: 'last_assigned',
            recordsTable: 'economy_work_orders_heads',
            recordSeriesColumn: 'number_series',
            recordScopeColumn: 'fiscal_year',
            recordSequenceColumn: 'sequence_number',
        );
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))->method('fetch')
            ->willReturnOnConsecutiveCalls(new Row(['last_assigned' => 0]), new Row(['max_seq' => 3]));

        $counter = $this->counter($db, $storage);
        $counter->next(2, null);
        $counter->maxSequence(2, null);

        $this->assertStringContainsString('[economy_work_orders_number_counters]', $this->executed[0]['sql']);
        $this->assertStringNotContainsString('docs_core', implode(' ', array_column($this->executed, 'sql')));
    }

    public function testStorageRejectsUnsafeIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SequenceStorage(
            countersTable: 'docs_core_number_counters; DROP',
            counterSeriesColumn: 'number_series',
            counterScopeColumn: 'fiscal_year',
            counterValueColumn: 'last_assigned',
            recordsTable: 'docs_core_heads',
            recordSeriesColumn: 'number_series',
            recordScopeColumn: 'fiscal_year',
            recordSequenceColumn: 'sequence_number',
        );
    }
}
