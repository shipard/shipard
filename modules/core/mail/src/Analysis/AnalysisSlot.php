<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

/**
 * Držený slot souběhu ({@see AnalysisSlots::tryAcquire()}): `flock` na
 * souboru `ai-analysis-<n>.lock`. Uvolní se voláním `release()` nebo
 * zánikem objektu (konec procesu uvolní zámek i bez toho).
 */
final class AnalysisSlot
{
    /** @param resource $handle */
    public function __construct(
        public readonly int $number,
        private $handle,
    ) {}

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
