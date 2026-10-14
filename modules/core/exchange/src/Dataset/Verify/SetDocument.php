<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Verify;

/**
 * Zpráva sady ve zdroji dat a doklad, který z ní vznikl (přes
 * `docs_core_heads.source_message`). Návrh = poslední úspěšná analýza
 * (`core_mail_message_analyses.status = 2`), otevřený = bez `resolution`.
 */
final class SetDocument
{
    /** @param array<string, mixed>|null $canonical canonical_json návrhu */
    public function __construct(
        public readonly string $messageId,
        public readonly int $messageNdx,
        public readonly string $receivedAt,
        public readonly ?int $analysisNdx,
        public readonly ?int $resolution,
        public readonly ?array $canonical,
        public readonly ?int $docId,
        public readonly ?int $docState,
        public readonly ?string $docNumber,
    ) {}

    /** Měna návrhu velkými (ISO 4217), null bez návrhu. */
    public function currency(): ?string
    {
        $currency = $this->canonical['currency'] ?? null;
        return is_string($currency) && $currency !== '' ? strtoupper(trim($currency)) : null;
    }

    public function hasOpenProposal(): bool
    {
        return $this->analysisNdx !== null && $this->resolution === null && $this->canonical !== null && $this->docId === null;
    }

    public function isApplied(): bool
    {
        return $this->docId !== null && $this->docState !== 90;
    }
}
