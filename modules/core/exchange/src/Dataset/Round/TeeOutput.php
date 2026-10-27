<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Round;

use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Výstup kroku kola jde dvěma směry: surově dál do logu kola (`output.log`
 * = stderr orchestrátoru) a do **ohraničené** paměti pro parser souhrnu
 * ({@see CodeStepParser}) — začátek (první selhání bývá po tečkách PHPUnit)
 * a konec (součty). Celý výstup se nedrží: PHPUnit s tisíci testy ho má
 * stovky kB, Playwright víc.
 */
final class TeeOutput extends Output
{
    public const int DEFAULT_HEAD_BYTES = 65_536;
    public const int DEFAULT_TAIL_BYTES = 262_144;

    private string $head = '';
    private string $tail = '';
    private int $dropped = 0;

    public function __construct(
        private readonly OutputInterface $inner,
        private readonly int $headBytes = self::DEFAULT_HEAD_BYTES,
        private readonly int $tailBytes = self::DEFAULT_TAIL_BYTES,
    ) {
        parent::__construct(OutputInterface::VERBOSITY_NORMAL, false);
    }

    protected function doWrite(string $message, bool $newline): void
    {
        $this->inner->write($message, $newline, OutputInterface::OUTPUT_RAW);
        $this->capture($message . ($newline ? "\n" : ''));
    }

    private function capture(string $chunk): void
    {
        $room = $this->headBytes - strlen($this->head);
        if ($room > 0) {
            $this->head .= substr($chunk, 0, $room);
            $chunk = (string) substr($chunk, $room);
        }
        if ($chunk === '') {
            return;
        }
        $this->tail .= $chunk;
        $excess = strlen($this->tail) - $this->tailBytes;
        if ($excess > 0) {
            $this->tail = substr($this->tail, $excess);
            $this->dropped += $excess;
        }
    }

    /** Zachycený výstup: začátek, případně značka vynechání, konec. */
    public function captured(): string
    {
        if ($this->dropped === 0) {
            return $this->head . $this->tail;
        }
        return $this->head . "\n…[vynecháno {$this->dropped} B]…\n" . $this->tail;
    }
}
