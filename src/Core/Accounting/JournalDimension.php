<?php

declare(strict_types=1);

namespace Shipard\Core\Accounting;

/**
 * Analytická dimenze deníku (`journalDimensions` v module.jsonc, assets
 * D47): hodnota řádku dokladu (`rowColumn`, prázdná → `headColumn`
 * hlavičky, je-li) se kopíruje do sloupce deníku (`journalColumn`)
 * a vstupuje do klíče seskupení. `table` je cílová tabulka reference,
 * `displayPattern` její vzor popisku (doplňuje kompilace konfigurace).
 */
final class JournalDimension
{
    public function __construct(
        public readonly string $id,
        public readonly string $rowColumn,
        public readonly ?string $headColumn,
        public readonly string $journalColumn,
        public readonly string $table,
        public readonly string $name,
        public readonly ?string $displayPattern = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['id', 'rowColumn', 'journalColumn', 'table'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
                throw new \InvalidArgumentException("Journal dimension requires '{$key}'");
            }
        }
        $headColumn = $data['headColumn'] ?? null;

        return new self(
            id: $data['id'],
            rowColumn: $data['rowColumn'],
            headColumn: is_string($headColumn) && $headColumn !== '' ? $headColumn : null,
            journalColumn: $data['journalColumn'],
            table: $data['table'],
            name: isset($data['name']) && is_string($data['name']) && $data['name'] !== '' ? $data['name'] : $data['id'],
            displayPattern: isset($data['displayPattern']) && is_string($data['displayPattern']) && $data['displayPattern'] !== ''
                ? $data['displayPattern']
                : null,
        );
    }

    /**
     * Sloupce cílové tabulky, ze kterých se skládá popisek hodnoty
     * (`{sloupec}` ve vzoru); bez vzoru jen `id`.
     *
     * @return list<string>
     */
    public function labelColumns(): array
    {
        if ($this->displayPattern === null
            || !preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $this->displayPattern, $m)
        ) {
            return ['id'];
        }
        return array_values(array_unique($m[1]));
    }

    /**
     * Popisek hodnoty z řádku cílové tabulky; `$prefix` = prefix aliasů
     * sloupců ve výsledku dotazu. Null, když řádek žádný sloupec vzoru nemá.
     *
     * @param array<string, mixed> $record
     */
    public function label(array $record, string $prefix = ''): ?string
    {
        $values = [];
        $any = false;
        foreach ($this->labelColumns() as $column) {
            $value = $record[$prefix . $column] ?? null;
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('j. n. Y');
            }
            $any = $any || ($value !== null && $value !== '');
            $values['{' . $column . '}'] = (string) ($value ?? '');
        }
        if (!$any) {
            return null;
        }
        return $this->displayPattern !== null
            ? trim(strtr($this->displayPattern, $values))
            : '#' . $values['{id}'];
    }

    /**
     * Hodnota dimenze pro řádek deníku: z řádku dokladu, prázdná z hlavičky.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $head
     */
    public function valueOf(array $row, array $head): ?int
    {
        $value = (int) ($row[$this->rowColumn] ?? 0);
        if ($value <= 0 && $this->headColumn !== null) {
            $value = (int) ($head[$this->headColumn] ?? 0);
        }
        return $value > 0 ? $value : null;
    }
}
