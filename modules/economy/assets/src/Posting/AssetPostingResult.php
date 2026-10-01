<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Posting;

/**
 * Výsledek sestavení pro jednu kartu: řádky dokladu a události, které jimi
 * budou zaúčtované — nebo chyba karty (žádné řádky, karta se vyloučí).
 */
final readonly class AssetPostingResult
{
    /**
     * @param list<AssetPostingRow> $rows
     * @param list<int> $eventIds události k navázání na doklad
     */
    public function __construct(
        public array $rows = [],
        public array $eventIds = [],
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {
    }

    public static function failed(string $code, string $message): self
    {
        return new self([], [], $code, $message);
    }

    public function isOk(): bool
    {
        return $this->errorCode === null;
    }
}
