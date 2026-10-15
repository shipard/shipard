<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

/**
 * Výsledek {@see AnalysisResultWriter::validateCanonical()}: canonical
 * návrhu k uložení (obohacený, nebo forenzní wrapper nevalidního výstupu)
 * a příznak platnosti. Nese ho {@see AnalysisOutcome}, aby zápis výsledku
 * validaci (včetně LLM klasifikace štítků) neopakoval.
 */
final readonly class ValidatedCanonical
{
    public function __construct(
        public ?string $json,
        public bool $valid,
    ) {}

    /** @return array<string, mixed>|null validní canonical jako pole; null u nevalidního */
    public function canonical(): ?array
    {
        if (!$this->valid || $this->json === null) {
            return null;
        }
        $decoded = json_decode($this->json, true);
        return is_array($decoded) ? $decoded : null;
    }
}
