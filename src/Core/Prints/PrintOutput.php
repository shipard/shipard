<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

/**
 * Výsledek běhu tisku. `pdfContent` je vyplněný jen u formátu PDF,
 * `document` jen u HTML; `printData` vždy — nese název souboru i měkká
 * hlášení builderu.
 *
 * Tisk na účtenkovou tiskárnu (#177 U35): `markup` je výstup šablony účtenky
 * (formáty `markup` a `escpos`), `bytes` bajty ESC/POS (jen `escpos`)
 * a `warnings` varování skládání — záložní profil, QR jako text.
 */
final class PrintOutput
{
    /** @param list<PrintMessage> $warnings */
    public function __construct(
        public readonly PrintFormat $format,
        public readonly PrintData $printData,
        public readonly ?string $pdfContent = null,
        public readonly ?PrintDocument $document = null,
        public readonly ?string $bytes = null,
        public readonly ?string $markup = null,
        public readonly array $warnings = [],
    ) {}
}
