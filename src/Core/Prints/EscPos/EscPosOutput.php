<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\EscPos;

use Shipard\Core\Prints\PrintMessage;

/**
 * Výsledek skládání účtenky: bajty pro tiskárnu, varování (účtenka vznikla,
 * ale něco je jinak — QR jako text, záložní profil) a sazba ve tvaru náhledu.
 */
final class EscPosOutput
{
    /**
     * @param list<PrintMessage>   $warnings
     * @param array<string, mixed> $preview  tvar `EscPosPreview::of()`
     */
    public function __construct(
        public readonly string $bytes,
        public readonly array $warnings,
        public readonly array $preview,
    ) {
    }

    /** @param list<PrintMessage> $warnings */
    public function withWarnings(array $warnings): self
    {
        return new self($this->bytes, [...$warnings, ...$this->warnings], $this->preview);
    }
}
