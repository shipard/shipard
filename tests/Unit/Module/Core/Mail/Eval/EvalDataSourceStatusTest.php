<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Eval;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\Eval\EvalDataSourceStatus;

/** Čistota zdroje dat vůči sadě (D59) — sdílená preflightem `ai-eval` a panelem Zdroje dat. */
final class EvalDataSourceStatusTest extends TestCase
{
    public function testFreshlySeededDataSourceIsClean(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $status = EvalDataSourceStatus::inspect(new ArrayEvalDataSource($db, ['A' => 1, 'B' => 2], [], 0, null), ['A', 'B']);

        $this->assertSame(['messages' => 2, 'documents' => 0, 'tagRules' => 0, 'missing' => [], 'membership' => [], 'clean' => true], $status);
    }

    public function testUsedDataSourceReportsEveryReason(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $status = EvalDataSourceStatus::inspect(new ArrayEvalDataSource($db, ['A' => 1, 'X' => 9], [5 => 1], 2, null), ['A', 'B']);

        $this->assertFalse($status['clean']);
        $this->assertSame(2, $status['messages']);
        $this->assertSame(1, $status['documents']);
        $this->assertSame(2, $status['tagRules']);
        $this->assertSame(['B'], $status['missing']);
        $this->assertNotSame([], $status['membership']);
        $this->assertStringContainsString('X', implode(' ', $status['membership']));
    }
}
