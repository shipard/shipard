<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

/**
 * Výsledek {@see AnalysisResultWriter::validateCanonical()}: canonical
 * návrhu k uložení (obohacený, nebo forenzní wrapper nevalidního výstupu)
 * a příznak platnosti. Nese ho {@see AnalysisOutcome}, aby zápis výsledku
 * validaci (včetně LLM klasifikace štítků) neopakoval. `tagsUsage` =
 * spotřeba LLM klasifikace štítků z obohacení (tokeny, model, cena; #103
 * F6b D56) — čte ji evaluace `ai-eval`; produkční zápis `cost_usd` analýzy
 * ji zatím nepřičítá (jen extrakce).
 */
final readonly class ValidatedCanonical
{
    /** @param array{tokensIn: int, tokensOut: int, model: ?string, costUsd: ?float}|null $tagsUsage */
    public function __construct(
        public ?string $json,
        public bool $valid,
        public ?array $tagsUsage = null,
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
