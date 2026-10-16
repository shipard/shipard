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
}
