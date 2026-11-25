<?php

declare(strict_types=1);

namespace Shipard\Core\RecordAction;

/**
 * Zvalidovaný vstup akce záznamu (#172 D13) — hodnoty polí dialogu podle
 * `describe()`, převedené podle typu pole:
 *
 *  - `text`, `textarea`, `password` → `string`, nevyplněné `null`;
 *  - `number` → `int|float`, nevyplněné `null`;
 *  - `date`, `datetime` → `\DateTimeImmutable` (datum o půlnoci; čas je
 *    místní čas serveru), nevyplněné `null`;
 *  - `checkbox` → `bool`;
 *  - `select` → hodnota z nabídky v typu, v jakém ji nabídka deklaruje,
 *    nevyplněné `null`;
 *  - `file` → {@see self::file()}.
 *
 * Každé pole dialogu s hodnotou tu klíč má. Akce bez dialogu dostane
 * prázdný vstup. Heslo a soubor jdou jen sem — jádro je neloguje ani
 * nevrací; výpis objektu (`var_dump`, log) hodnoty záměrně neukazuje.
 */
final class RecordActionInput
{
    /**
     * @param array<string, mixed> $values id pole → hodnota
     * @param array<string, RecordActionFile> $files id pole → nahraný soubor
     */
    public function __construct(
        private readonly array $values = [],
        private readonly array $files = [],
    ) {
    }

    /** Hodnota pole; `null` pro nevyplněné i neznámé pole. */
    public function value(string $field): mixed
    {
        return $this->values[$field] ?? null;
    }

    /** Hodnota textového pole (`text`, `textarea`, `password`); nevyplněné `null`. */
    public function string(string $field): ?string
    {
        $value = $this->values[$field] ?? null;
        return is_string($value) ? $value : null;
    }

    public function bool(string $field): bool
    {
        return ($this->values[$field] ?? false) === true;
    }

    public function dateTime(string $field): ?\DateTimeImmutable
    {
        $value = $this->values[$field] ?? null;
        return $value instanceof \DateTimeImmutable ? $value : null;
    }

    public function file(string $field): ?RecordActionFile
    {
        return $this->files[$field] ?? null;
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * Jen jména polí — hodnoty (heslo) se do výpisu nedostanou.
     *
     * @return array{fields: list<string>, files: list<string>}
     */
    public function __debugInfo(): array
    {
        return ['fields' => array_keys($this->values), 'files' => array_keys($this->files)];
    }
}
