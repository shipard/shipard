<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Database;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\NestedTransaction;

class NestedTransactionTest extends TestCase
{
    /**
     * Spojení, které zaznamenává volání transakcí; `$inTransaction` je
     * odpověď na `SELECT @@in_transaction`.
     *
     * @param list<string> $log
     */
    private function connection(bool $inTransaction, array &$log): \Dibi\Connection
    {
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetchSingle')->willReturn($inTransaction ? 1 : 0);
        $db->method('begin')->willReturnCallback(function (?string $sp = null) use (&$log): void {
            $log[] = 'begin:' . ($sp ?? '');
        });
        $db->method('commit')->willReturnCallback(function (?string $sp = null) use (&$log): void {
            $log[] = 'commit:' . ($sp ?? '');
        });
        $db->method('rollback')->willReturnCallback(function (?string $sp = null) use (&$log): void {
            $log[] = 'rollback:' . ($sp ?? '');
        });
        return $db;
    }

    public function testOwnTransactionOutsideOfAnother(): void
    {
        $log = [];
        $result = NestedTransaction::run($this->connection(false, $log), static fn(): int => 7);

        self::assertSame(7, $result);
        self::assertSame(['begin:', 'commit:'], $log);
    }

    public function testSavepointInsideOuterTransaction(): void
    {
        $log = [];
        NestedTransaction::run($this->connection(true, $log), static fn(): null => null);

        self::assertCount(2, $log);
        self::assertMatchesRegularExpression('/^begin:shpd_nested_\d+$/', $log[0]);
        self::assertSame(str_replace('begin:', 'commit:', $log[0]), $log[1]);
    }

    public function testFailureRollsBackOwnTransactionAndRethrows(): void
    {
        $log = [];
        try {
            NestedTransaction::run($this->connection(false, $log), static function (): void {
                throw new \RuntimeException('boom');
            });
            self::fail('Výjimka se měla propsat');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame(['begin:', 'rollback:'], $log);
    }

    public function testFailureInsideOuterRollsBackOnlyToSavepoint(): void
    {
        $log = [];
        try {
            NestedTransaction::run($this->connection(true, $log), static function (): void {
                throw new \RuntimeException('boom');
            });
            self::fail('Výjimka se měla propsat');
        } catch (\RuntimeException) {
        }
        self::assertMatchesRegularExpression('/^rollback:shpd_nested_\d+$/', $log[1]);
    }

    public function testFailingRollbackDoesNotMaskOriginalException(): void
    {
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetchSingle')->willReturn(1);
        $db->method('rollback')->willThrowException(new \RuntimeException('savepoint gone'));

        $this->expectExceptionMessage('deadlock');
        NestedTransaction::run($db, static function (): void {
            throw new \RuntimeException('deadlock');
        });
    }
}
