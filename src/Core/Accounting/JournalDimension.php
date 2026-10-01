<?php

declare(strict_types=1);

namespace Shipard\Core\Accounting;

/**
 * Analytická dimenze deníku (`journalDimensions` v module.jsonc, assets
 * D47): hodnota řádku dokladu (`rowColumn`, prázdná → `headColumn`
 * hlavičky, je-li) se kopíruje do sloupce deníku (`journalColumn`)
 * a vstupuje do klíče seskupení. `table` je cílová tabulka reference,
 * `displayPattern` její vzor popisku (doplňuje kompilace konfigurace).
 *
 * `rowFlag` je vlajka řádkové operace (`docs.core.rowOperations`), která
 * říká „tenhle řádek nese hodnotu sám“ (majetek: `rowAsset` u pořízení
 * a systémových operací): takový řádek výchozí hodnotu z hlavičky nedědí
 * a formulář mu pole staví podle operace, ne podle `forms`.
 *
 * `forms` říká, na kterých formulářích dokladů se pole dimenze nabízí:
 * typy dokladů, hlavička / řádky a volitelně klíč nastavení, které pole
 * zapíná (`enabledBySetting`). Řídí jen zobrazení pole — uložená hodnota
 * se do deníku propisuje vždy.
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
        /** @var list<string> typy dokladů, jejichž formulář pole nabízí */
        public readonly array $formDocTypes = [],
        public readonly bool $formHead = false,
        public readonly bool $formRows = false,
        public readonly ?string $enabledBySetting = null,
        public readonly ?string $rowFlag = null,
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
        $headColumn = is_string($headColumn) && $headColumn !== '' ? $headColumn : null;
        $forms = is_array($data['forms'] ?? null) ? $data['forms'] : [];
        $setting = $forms['enabledBySetting'] ?? null;

        return new self(
            id: $data['id'],
            rowColumn: $data['rowColumn'],
            headColumn: $headColumn,
            journalColumn: $data['journalColumn'],
            table: $data['table'],
            name: isset($data['name']) && is_string($data['name']) && $data['name'] !== '' ? $data['name'] : $data['id'],
            displayPattern: isset($data['displayPattern']) && is_string($data['displayPattern']) && $data['displayPattern'] !== ''
                ? $data['displayPattern']
                : null,
            formDocTypes: array_values(array_filter(
                is_array($forms['docTypes'] ?? null) ? $forms['docTypes'] : [],
                static fn(mixed $docType): bool => is_string($docType) && $docType !== '',
            )),
            // Pole na hlavičce nemá bez `headColumn` kam uložit hodnotu.
            formHead: !empty($forms['head']) && $headColumn !== null,
            formRows: !empty($forms['rows']),
            enabledBySetting: is_string($setting) && $setting !== '' ? $setting : null,
            rowFlag: isset($data['rowFlag']) && is_string($data['rowFlag']) && $data['rowFlag'] !== ''
                ? $data['rowFlag']
                : null,
        );
    }

    /**
     * Nese řádek s touto operací hodnotu dimenze sám (vlajka `rowFlag`)?
     *
     * @param array<string, mixed>|null $operationAttrs atributy operace řádku
     */
    public function isOwnedByRow(?array $operationAttrs): bool
    {
        return $this->rowFlag !== null && !empty($operationAttrs[$this->rowFlag]);
    }

    /** Nabízí formulář dokladu daného typu pole dimenze na hlavičce / řádku? */
    public function isOnForm(string $docType, bool $head): bool
    {
        return ($head ? $this->formHead : $this->formRows)
            && in_array($docType, $this->formDocTypes, true);
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
     * Hodnota dimenze pro řádek deníku: z řádku dokladu, prázdná z hlavičky
     * — ne u řádku, který hodnotu nese sám (`rowFlag` operace).
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $head
     * @param array<string, mixed>|null $operationAttrs atributy operace řádku
     */
    public function valueOf(array $row, array $head, ?array $operationAttrs = null): ?int
    {
        $value = (int) ($row[$this->rowColumn] ?? 0);
        if ($value <= 0 && $this->headColumn !== null && !$this->isOwnedByRow($operationAttrs)) {
            $value = (int) ($head[$this->headColumn] ?? 0);
        }
        return $value > 0 ? $value : null;
    }
}
