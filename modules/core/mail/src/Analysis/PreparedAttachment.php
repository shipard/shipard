<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

/**
 * Příloha připravená pro model (tasks/mail-analysis-inprocess.md D16):
 * `pdf` a `image` nesou obsah v base64 (`mimeType` vybírá kódování bloku),
 * `text` nese dekódovaný prostý text. `ndx` = `core_attachments_files.id`
 * přílohy nebo ZIPu, ze kterého soubor pochází — drží seznam příloh
 * v promptu u skutečných příloh zprávy.
 */
final readonly class PreparedAttachment
{
    public const KIND_PDF = 'pdf';
    public const KIND_IMAGE = 'image';
    public const KIND_TEXT = 'text';

    public function __construct(
        public int $ndx,
        public string $kind,
        public string $filename,
        public string $mimeType,
        public ?string $base64 = null,
        public ?string $text = null,
    ) {}

    /** Bajty, které příloha zabere v požadavku na model (base64 nebo UTF-8 text). */
    public function aiBytes(): int
    {
        if ($this->base64 !== null) {
            return strlen($this->base64);
        }
        return $this->text !== null ? strlen($this->text) : 0;
    }

    /** Původní velikost souboru (base64 ≈ 4/3) — pro `size_human` v promptu. */
    public function sizeBytes(): int
    {
        if ($this->base64 !== null) {
            return intdiv(strlen($this->base64) * 3, 4);
        }
        return $this->text !== null ? strlen($this->text) : 0;
    }
}
