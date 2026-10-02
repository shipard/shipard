<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

/**
 * Měkké hlášení builderu (#90 D12 doplnění) — tisk vznikne, ale něco na něm
 * chybí (QR platba bez účtu). `code` je strojově čitelný, `text` lidský
 * v jazyce tisku. Tvrdé chyby (chybí snapshot) jdou výjimkou, ne sem.
 */
final class PrintMessage
{
    public function __construct(
        public readonly PrintMessageSeverity $severity,
        public readonly string $code,
        public readonly string $text,
    ) {}

    public static function warning(string $code, string $text): self
    {
        return new self(PrintMessageSeverity::Warning, $code, $text);
    }

    /** @return array{severity: string, code: string, text: string} */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity->value,
            'code'     => $this->code,
            'text'     => $this->text,
        ];
    }
}
