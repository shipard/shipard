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
     */
    public function __construct(
        public int $sentMessageId,
        public int $outboxId,
        public string $transportState,
        public array $messages = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sentMessageId'  => $this->sentMessageId,
            'transportState' => $this->transportState,
            'messages'       => $this->messages,
        ];
    }
}
