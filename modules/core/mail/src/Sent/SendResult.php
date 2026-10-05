<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Sent;

/** Výsledek odeslání záznamu: nová zpráva v Odeslané poště a stav transportu. */
final readonly class SendResult
{
    /**
     * @param string $transportState `sent` | `queued` | `failed` — selhání
     *        okamžitého pokusu nechává zprávu ve frontě (`queued`).
     * @param list<array{severity: string, code: string, text: string}> $messages
     * @param ?array{action: string, target: ?string, label: string, style: string} $safety
     *        Zásah pojistky odchozí pošty (#95 D6) — „odesláno“ pak znamená
     *        přesměrováno nebo zachyceno.
     */
    public function __construct(
        public int $sentMessageId,
        public int $outboxId,
        public string $transportState,
        public array $messages = [],
        public ?array $safety = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sentMessageId'  => $this->sentMessageId,
            'transportState' => $this->transportState,
            'safety'         => $this->safety,
            'messages'       => $this->messages,
        ];
    }
}
