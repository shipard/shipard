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

    /**
     * @param array<string, mixed> $data Tvar `toArray()`.
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $severity = is_string($data['severity'] ?? null)
            ? PrintMessageSeverity::tryFrom($data['severity'])
            : null;
        if ($severity === null || !is_string($data['code'] ?? null) || !is_string($data['text'] ?? null)) {
            throw new \InvalidArgumentException("Print message must carry 'severity', 'code' and 'text'");
        }
        return new self($severity, $data['code'], $data['text']);
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
