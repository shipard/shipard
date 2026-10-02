<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

use Shipard\Core\Render\RenderErrorKind;

/**
 * PDF nevzniklo — render služba selhala nebo není nakonfigurovaná. Provozní
 * stav, ne programátorská chyba: `errorKind` rozhoduje mezi 503 (služba
 * nedostupná) a 500 (engine odmítl vstup).
 */
final class PrintRenderException extends \RuntimeException
{
    public function __construct(
        public readonly RenderErrorKind $errorKind,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : "Print render failed: {$errorKind->value}");
    }

    /** Služba teď neodpovídá (zkusit později) vs. render selhal na vstupu. */
    public function isServiceUnavailable(): bool
    {
        return in_array(
            $this->errorKind,
            [RenderErrorKind::Unconfigured, RenderErrorKind::Unreachable, RenderErrorKind::Timeout],
            true,
        );
    }
}
