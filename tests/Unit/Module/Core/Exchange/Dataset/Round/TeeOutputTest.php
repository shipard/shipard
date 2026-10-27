<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Round;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\Round\TeeOutput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/** Výstup kroku jde celý do logu a ohraničeně (začátek + konec) do paměti pro parser. */
final class TeeOutputTest extends TestCase
{
    public function testForwardsEverythingAndKeepsHeadAndTail(): void
    {
        $inner = new BufferedOutput();
        $tee = new TeeOutput($inner, 10, 8);

        $tee->write('0123456789', false, OutputInterface::OUTPUT_RAW);
        $tee->write('abcdefghijklmnop', false, OutputInterface::OUTPUT_RAW);
        $tee->write("<info>raw</info>\n", false, OutputInterface::OUTPUT_RAW);

        $this->assertSame("0123456789abcdefghijklmnop<info>raw</info>\n", $inner->fetch(), 'log dostane vše, surově');
        $captured = $tee->captured();
        $this->assertStringStartsWith('0123456789', $captured);
        $this->assertStringEndsWith("</info>\n", $captured, 'posledních 8 B');
        $this->assertStringContainsString('…[vynecháno 25 B]…', $captured);
    }

    public function testSmallOutputIsKeptWhole(): void
    {
        $tee = new TeeOutput(new BufferedOutput());
        $tee->write("hello\n", false, OutputInterface::OUTPUT_RAW);
        $tee->writeln('world', OutputInterface::OUTPUT_RAW);
        $this->assertSame("hello\nworld\n", $tee->captured());
    }
}
