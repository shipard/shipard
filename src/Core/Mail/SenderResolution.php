<?php

declare(strict_types=1);

namespace Shipard\Core\Mail;

/**
 * Výsledek volby odesílatele. Neúspěch (`errorCode`) není výjimka — návrh
 * odeslání ho ukáže uživateli vedle ostatních hlášení a nabídne výběr.
 */
final readonly class SenderResolution
{
    public const NO_SENDER          = 'NO_SENDER';
    public const SENDER_NOT_ALLOWED = 'SENDER_NOT_ALLOWED';

    public const SOURCE_CHOSEN  = 'chosen';
    public const SOURCE_RECORD  = 'record';
    public const SOURCE_DEFAULT = 'default';

    /**
     * @param ?string $source Odkud adresa je (`SOURCE_*`); null u neúspěchu.
     * @param ?string $errorText Hláška pro uživatele v jazyce rozhraní.
     */
    public function __construct(
        public ?string $email,
        public ?string $name,
        public ?string $source,
        public ?string $errorCode = null,
        public ?string $errorText = null,
    ) {}

    public function isResolved(): bool
    {
        return $this->errorCode === null && $this->email !== null;
    }
}
