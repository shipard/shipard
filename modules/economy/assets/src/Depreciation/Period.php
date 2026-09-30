<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation;

/**
 * Účetní období (rok nebo měsíc). `id` je klíč založeného období v DS,
 * `null` u období dopočítaného za hranicí založených let.
 */
final readonly class Period
{
    public function __construct(
        public ?int $id,
        public string $begin,
        public string $end,
    ) {
        if (!self::isDate($begin) || !self::isDate($end) || $begin > $end) {
            throw new \InvalidArgumentException("Neplatné období '{$begin}' – '{$end}'");
        }
    }

    /**
     * Klíče `id`, `begin`, `end`; přijme i sloupce účetních období
     * `date_begin` / `date_end`.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['id']) ? (int) $data['id'] : null,
            (string) ($data['begin'] ?? $data['date_begin'] ?? ''),
            (string) ($data['end'] ?? $data['date_end'] ?? ''),
        );
    }

    public function contains(string $date): bool
    {
        return $date >= $this->begin && $date <= $this->end;
    }

    public function beginMonth(): int
    {
        return Months::of($this->begin);
    }

    public function endMonth(): int
    {
        return Months::of($this->end);
    }

    /** Délka období v měsících (období jsou zarovnaná na celé měsíce). */
    public function months(): int
    {
        return $this->endMonth() - $this->beginMonth() + 1;
    }

    /** @return array{id: ?int, begin: string, end: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'begin' => $this->begin, 'end' => $this->end];
    }

    public static function isDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}
