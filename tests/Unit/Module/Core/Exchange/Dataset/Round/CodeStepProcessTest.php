<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Round;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\Round\CodeStepProcess;
use Shipard\Module\Core\Exchange\Dataset\Round\TeeOutput;
use Symfony\Component\Console\Output\BufferedOutput;

/** Podproces kroku kódu: oba výstupy do logu i do zachycení, exit kód, zrušení SIGTERM. */
final class CodeStepProcessTest extends TestCase
{
    public function testCapturesBothStreamsAndExitCode(): void
    {
        $log = new BufferedOutput();
        $tee = new TeeOutput($log);
        $process = new CodeStepProcess(['sh', '-c', 'echo out; echo err 1>&2; exit 3'], sys_get_temp_dir(), static fn (): bool => false, 1.0, 20_000);

        $result = $process->run(CodeStepProcess::environment('/tmp'), $tee);

        $this->assertSame(3, $result['exitCode']);
        $this->assertNull($result['termSignal']);
        $this->assertFalse($result['cancelled']);
        $this->assertStringContainsString("out\n", $tee->captured());
        $this->assertStringContainsString("err\n", $tee->captured());
        $this->assertSame($tee->captured(), $log->fetch());
    }

    public function testCancelSendsSigterm(): void
    {
        $cancel = false;
        $process = new CodeStepProcess(['sh', '-c', 'sleep 5'], sys_get_temp_dir(), static function () use (&$cancel): bool {
            $cancel = true;
            return true;
        }, 1.0, 20_000);

        $result = $process->run(CodeStepProcess::environment('/tmp'), new BufferedOutput());

        $this->assertTrue($cancel);
        $this->assertTrue($result['cancelled']);
        $this->assertNull($result['exitCode']);
        $this->assertSame(CodeStepProcess::SIGTERM, $result['termSignal']);
        $this->assertSame(CodeStepProcess::SIGTERM, $process->cancelSignal());
    }
}
