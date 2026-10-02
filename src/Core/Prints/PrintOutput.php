<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

/**
 * Výsledek běhu tisku. `pdfContent` je vyplněný jen u formátu PDF,
 * `document` jen u HTML; `printData` vždy — nese název souboru i měkká
 * hlášení builderu.
 */
final class PrintOutput
{
    public function __construct(
        public readonly PrintFormat $format,
        public readonly PrintData $printData,
        public readonly ?string $pdfContent = null,
        public readonly ?PrintDocument $document = null,
    ) {}
}
