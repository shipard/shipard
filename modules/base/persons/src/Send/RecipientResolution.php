<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons\Send;

/**
 * Výsledek hledání příjemců: adresy do „Komu“ a hlášení pro uživatele.
 * Prázdné `recipients` vždy doprovází chyba `NO_RECIPIENT` v `messages`.
 */
final readonly class RecipientResolution
{
    public const NO_RECIPIENT    = 'NO_RECIPIENT';
    public const INVALID_ADDRESS = 'INVALID_RECIPIENT_ADDRESS';

    /**
     * @param list<Recipient> $recipients
     * @param list<array{severity: string, code: string, text: string}> $messages
     */
    public function __construct(
        public array $recipients,
        public array $messages = [],
    ) {}

    /** @return list<string> */
    public function emails(): array
    {
        return array_map(static fn (Recipient $r): string => $r->email, $this->recipients);
    }

    public function hasRecipients(): bool
    {
        return $this->recipients !== [];
    }
}
