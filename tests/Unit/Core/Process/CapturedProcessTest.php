<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Process;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Process\CapturedProcess;

final class CapturedProcessTest extends TestCase
{
    protected function setUp(): void
    {
        if (trim((string) shell_exec('command -v sh 2>/dev/null')) === '') {
            $this->markTestSkipped('sh is not available');
        }
    }

    public function testSplitsStdoutAndStderrAndReturnsExitCode(): void
    {
        $r = CapturedProcess::run(['sh', '-c', 'echo out; echo err >&2; exit 3'], null, 5.0);

        $this->assertSame(3, $r['exitCode']);
        $this->assertNull($r['termSignal']);
        $this->assertFalse($r['timedOut']);
        $this->assertSame("out\n", $r['stdout']);
        $this->assertSame("err\n", $r['stderr']);
    }

    public function testTimeoutTerminatesTheProcess(): void
    {
        $started = microtime(true);
        $r = CapturedProcess::run(['sleep', '5'], null, 0.3);
        $elapsed = microtime(true) - $started;

        $this->assertTrue($r['timedOut']);
        $this->assertSame(15, $r['termSignal']);
        $this->assertNull($r['exitCode']);
        $this->assertLessThan(6.0, $elapsed, 'timeout must not wait for the full sleep');
    }

    public function testEmptyArgvFails(): void
    {
        $r = CapturedProcess::run([], null, 1.0);

        $this->assertNull($r['exitCode']);
        $this->assertSame('proc_open failed', $r['stderr']);
    }

    public function testCapsCapturedBytes(): void
    {
        $r = CapturedProcess::run(['sh', '-c', 'head -c 5000 /dev/zero | tr "\\0" x'], null, 5.0, 100);

        $this->assertSame(0, $r['exitCode']);
        $this->assertSame(100, strlen($r['stdout']));
    }
}
