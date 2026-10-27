<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Round;

/**
 * Evaluace v kole (D131): varianta z `tests/eval/variants/`, počet opakování
 * a **strop útraty za celé kolo** v USD (povinný — bez něj kolo s evaluací
 * nezačne). Dílčí `eval` dostává zbytek stropu po dřívějších evaluacích.
 */
final readonly class RoundEvalOptions
{
    public const int MAX_REPEAT = 5;

    public function __construct(
        public string $variant,
        public int $repeat,
        public float $maxCostUsd,
    ) {
        if ($variant === '' || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $variant) !== 1) {
            throw new \InvalidArgumentException("Invalid eval variant name '{$variant}'");
        }
        if ($repeat < 1 || $repeat > self::MAX_REPEAT) {
            throw new \InvalidArgumentException('repeat must be between 1 and ' . self::MAX_REPEAT);
        }
        if (!is_finite($maxCostUsd) || $maxCostUsd <= 0) {
            throw new \InvalidArgumentException('maxCostUsd must be a positive spend cap in USD');
        }
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $repeat = $raw['repeat'] ?? 1;
        $cost = $raw['maxCostUsd'] ?? null;
        return new self(
            is_string($raw['variant'] ?? null) ? $raw['variant'] : '',
            is_int($repeat) || (is_string($repeat) && ctype_digit($repeat)) ? (int) $repeat : 0,
            is_numeric($cost) ? (float) $cost : 0.0,
        );
    }

    /** @return array{variant: string, repeat: int, maxCostUsd: float} */
    public function toArray(): array
    {
        return ['variant' => $this->variant, 'repeat' => $this->repeat, 'maxCostUsd' => $this->maxCostUsd];
    }
}
