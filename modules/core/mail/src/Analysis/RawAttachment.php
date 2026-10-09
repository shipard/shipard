<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

/**
 * Příloha zprávy tak, jak leží na disku — vstup {@see AttachmentPreparer}.
 * Soubor rozbalený ze ZIPu dědí `ndx` rodiče (nemá vlastní řádek
 * v `core_attachments_files`) a název `<zip>/<vnitřní název>`.
 */
final readonly class RawAttachment
{
    public function __construct(
        public int $ndx,
        public string $filename,
        public string $mimeType,
        public string $content,
    ) {}
}
