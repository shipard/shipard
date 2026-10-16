<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Eval;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\Eval\EvalException;
use Shipard\Module\Core\Mail\Eval\EvalProgressWriter;

/** Průběh `ai-eval --progress-file` (D61): JSON řádky s `event` a `at`, bez cesty nic, chybějící složka = odmítnutí. */
final class EvalProgressWriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shpd_progress_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testAppendsOneJsonLinePerEvent(): void
    {
        $writer = EvalProgressWriter::toFile($this->dir . '/progress.jsonl');
        $this->assertTrue($writer->enabled());

        $writer->event(EvalProgressWriter::STARTED, ['calls' => 4, 'maxCostUsd' => 0.5]);
        $writer->event(EvalProgressWriter::CALL, ['messageId' => 'M1', 'costUsd' => 0.02, 'done' => 1, 'total' => 4]);

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->dir . '/progress.jsonl'))));
        $this->assertCount(2, $lines);
        $started = json_decode($lines[0], true);
        $this->assertSame('started', $started['event']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $started['at']);
        $this->assertSame(4, $started['calls']);
        $this->assertSame(0.5, $started['maxCostUsd']);
        $call = json_decode($lines[1], true);
        $this->assertSame(['event', 'at', 'messageId', 'costUsd', 'done', 'total'], array_keys($call));
        $this->assertSame(0.02, $call['costUsd']);
    }

    public function testNoneWritesNothing(): void
    {
        $writer = EvalProgressWriter::none();
        $this->assertFalse($writer->enabled());
        $writer->event(EvalProgressWriter::FINISHED, ['file' => 'x']);
        $this->assertSame([], glob($this->dir . '/*') ?: []);
    }

    public function testMissingDirectoryIsRefused(): void
    {
        $this->expectException(EvalException::class);
        $this->expectExceptionMessage('--progress-file');
        EvalProgressWriter::toFile($this->dir . '/missing/progress.jsonl');
    }
}
