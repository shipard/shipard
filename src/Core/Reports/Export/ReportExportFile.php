<?php

declare(strict_types=1);

namespace Shipard\Core\Reports\Export;

/** Hotový soubor exportu — tělo, MIME typ a název pro uložení. */
final class ReportExportFile
{
    public function __construct(
        public readonly string $body,
        public readonly string $contentType,
        public readonly string $fileName,
    ) {}
}
