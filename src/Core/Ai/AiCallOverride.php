<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

/**
 * Přepsání volání AI funkce volajícím (tasks/ai-functions.md D40): AI
 * profil pošty (D4), volby příkazu `booking-history`. Má přednost před
 * řádkem funkce i deklarací; `null` = nepřepisovat. U `thinking` /
 * `effort` je `default` totéž co `null` (podle funkce), `auto` = výslovně
 * neposílat. `maxTokens` 0 = nepřepisovat.
 */
final readonly class AiCallOverride
{
    public function __construct(
        public ?int $connectionId = null,
        public ?string $model = null,
        public ?string $modelLike = null,
        public ?string $thinking = null,
        public ?string $effort = null,
        public ?int $maxTokens = null,
    ) {}

    public static function none(): self
    {
        return new self();
    }

    /**
     * Přepsání z řádku s přepisovými sloupci (`core_mail_ai_profiles`:
     * `backend`, `model`, `model_like`, `thinking`, `effort`, `max_tokens`).
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row, string $connectionColumn = 'backend'): self
    {
        $connection = $row[$connectionColumn] ?? null;
        $maxTokens = $row['max_tokens'] ?? null;
        return new self(
            connectionId: is_numeric($connection) && (int) $connection > 0 ? (int) $connection : null,
            model: self::text($row['model'] ?? null),
            modelLike: self::text($row['model_like'] ?? null),
            thinking: self::text($row['thinking'] ?? null),
            effort: self::text($row['effort'] ?? null),
            maxTokens: is_numeric($maxTokens) && (int) $maxTokens > 0 ? (int) $maxTokens : null,
        );
    }

    public function isEmpty(): bool
    {
        return $this->connectionId === null && $this->model === null && $this->modelLike === null
            && $this->tuningValue($this->thinking) === null && $this->tuningValue($this->effort) === null
            && ($this->maxTokens === null || $this->maxTokens <= 0);
    }

    /** `default` / prázdné = nepřepisovat (null); `auto` i konkrétní hodnota přepisují. */
    public function tuningValue(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : '';
        return $value === '' || $value === 'default' ? null : $value;
    }

    private static function text(mixed $value): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        return $text === '' ? null : $text;
    }
}
