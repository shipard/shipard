<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

/**
 * Zpráva sady pro evaluaci: očekávání = `canonicalJson` ideální analýzy
 * a `primaryType` ze souboru sady (ne ze zdroje dat), buňka korpusu
 * z `expected/messages.json` (stem, varianta, podoba).
 */
final readonly class EvalMessage
{
    /** Podoba buňky korpusu s ISDOC jako druhou přílohou (#119 D10) — v evaluaci jde cestou `isdoc` (D76). */
    public const string FORM_PDF_ISDOC = 'pdfIsdoc';

    /** @param array<string, mixed> $expectedCanonical */
    public function __construct(
        public string $messageId,
        public string $receivedAt,
        public array $expectedCanonical,
        public ?string $expectedPrimaryType,
        public ?string $stem,
        public ?string $variant,
        public ?string $form,
        /** Relativní cesta záznamu v sadě (`mail/0001-….jsonc`) — pro přílohy v sidecar složce. */
        public ?string $file = null,
    ) {}

    /** Zpráva podoby PDF + ISDOC — odhad ceny ji počítá jen za štítky. */
    public function hasIsdoc(): bool
    {
        return $this->form === self::FORM_PDF_ISDOC;
    }
}
