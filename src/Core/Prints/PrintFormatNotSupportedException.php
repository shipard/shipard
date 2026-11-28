<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

/**
 * Tisk tento formát nedává (#177 U35): z tisku `output: escpos` nejde PDF
 * ani HTML, z tisku do PDF nejde účtenka (→ 409 `PRINT_FORMAT_UNSUPPORTED`).
 */
final class PrintFormatNotSupportedException extends \RuntimeException
{
    public function __construct(PrintDefinition $definition, PrintFormat $format)
    {
        parent::__construct(sprintf(
            "Print '%s' (output '%s') cannot be rendered to '%s'",
            $definition->id,
            $definition->output,
            $format->value,
        ));
    }
}
