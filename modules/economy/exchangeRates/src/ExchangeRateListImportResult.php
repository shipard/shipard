<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates;

/** Výsledek importu jednoho lístku (ExchangeRateListImporter, §3). */
final readonly class ExchangeRateListImportResult
{
    public const string CREATED = 'created';
    public const string SKIPPED = 'skipped';
    public const string REPLACED = 'replaced';
    /** Existující lístek s jiným obsahem bez volby replace. */
    public const string CONFLICT = 'conflict';
    /** Chyba schématu nebo sémantiky (§2). */
    public const string INVALID = 'invalid';
    /** Zápis selhal (gateway). */
    public const string FAILED = 'failed';

    /** @param list<string> $errors */
    public function __construct(
        public string $outcome,
        public string $listType,
        public string $validFrom,
        public ?int $listId = null,
        public array $errors = [],
    ) {
    }

    public function ok(): bool
    {
        return in_array($this->outcome, [self::CREATED, self::SKIPPED, self::REPLACED], true);
    }

    /** „cz.cnb.daily 2026-10-16“ pro výpisy. */
    public function label(): string
    {
        return trim($this->listType . ' ' . $this->validFrom);
    }
}
