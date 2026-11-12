<?php

declare(strict_types=1);

namespace Shipard\Core\Database;

use Shipard\Core\I18n\DisplayDate;

/**
 * Vzor popisku záznamu s placeholdery `{sloupec}` — `displayPattern`
 * tabulky, `detailHeader` vieweru, popisek dimenze deníku (#136 D19).
 *
 * Prázdná hodnota (null, prázdný řetězec) se vynechá i se svým
 * oddělovačem: `{code} — {name}` bez kódu dá „Název“, ne „— Název“.
 * Oddělovačem je literál ze sady `SEPARATORS`; jiný literál (`#`, `/`)
 * zůstává vždy. Datum (`DateTimeInterface` nebo hodnota ve tvaru `Y-m-d`,
 * s časem `Y-m-d\TH:i:s` / `Y-m-d H:i:s`) se formátuje přes `DisplayDate`
 * v jazyce volajícího (#133 D73).
 *
 * Placeholder s náhradníky `{account_number|iban}` dá první neprázdnou
 * hodnotu v pořadí zápisu (#118 D28: účet osoby bez čísla účtu se
 * ukazuje IBANem); `columns()` vrací všechny jeho sloupce.
 */
final class RecordPattern
{
    /** Literály mezi placeholdery, které se při prázdném sousedovi zahazují. */
    private const SEPARATORS = [' — ', ' – ', ' · ', ' - ', ' '];

    private const NAME = '[a-zA-Z_][a-zA-Z0-9_]*';
    private const PLACEHOLDER = '/\{(' . self::NAME . '(?:\|' . self::NAME . ')*)\}/';

    /** Hodnota sloupce ve tvaru data / data s časem, jak ji vrací databázová vrstva. */
    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';
    private const DATE_TIME = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/';

    public function __construct(public readonly string $pattern)
    {
    }

    /**
     * Sloupce z placeholderů v pořadí výskytu, bez opakování.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        if (!preg_match_all(self::PLACEHOLDER, $this->pattern, $m)) {
            return [];
        }
        $columns = [];
        foreach ($m[1] as $group) {
            foreach (explode('|', $group) as $column) {
                $columns[] = $column;
            }
        }
        return array_values(array_unique($columns));
    }

    /**
     * Vyplní vzor hodnotami záznamu; `$prefix` = prefix aliasů sloupců ve
     * výsledku dotazu (`d_asset__name`). Null, když vzor má placeholdery
     * a žádný z nich nemá hodnotu, nebo když je výsledek prázdný.
     * `$language` = jazyk, ve kterém se zapíše datum (bez něj anglicky —
     * popisky mimo obrazovku, např. dimenze na tisku Kontace).
     *
     * @param array<string, mixed> $record
     */
    public function render(array $record, string $prefix = '', string $language = 'en'): ?string
    {
        $tokens = preg_split(
            '/(\{' . self::NAME . '(?:\|' . self::NAME . ')*\})/',
            $this->pattern,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
        ) ?: [];

        $out = '';
        $hasPlaceholder = false;
        $anyValue = false;
        $hasContent = false;      // vlevo už něco je — oddělovač má co oddělovat
        $pendingSeparator = null; // oddělovač čekající na další obsah vpravo

        foreach ($tokens as $token) {
            if (preg_match(self::PLACEHOLDER, $token, $m) && $token === $m[0]) {
                $hasPlaceholder = true;
                $value = '';
                foreach (explode('|', $m[1]) as $column) {
                    $value = self::stringify($record[$prefix . $column] ?? null, $language);
                    if ($value !== '') {
                        break;
                    }
                }
                if ($value === '') {
                    continue;
                }
                $out .= ($pendingSeparator ?? '') . $value;
                $pendingSeparator = null;
                $hasContent = true;
                $anyValue = true;
                continue;
            }

            if (in_array($token, self::SEPARATORS, true)) {
                if ($hasContent) {
                    $pendingSeparator = $token;
                }
                continue;
            }

            $out .= ($pendingSeparator ?? '') . $token;
            $pendingSeparator = null;
            $hasContent = true;
        }

        if ($hasPlaceholder && !$anyValue) {
            return null;
        }
        $out = trim($out);
        return $out === '' ? null : $out;
    }

    private static function stringify(mixed $value, string $language): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return (string) DisplayDate::date($value, $language);
        }
        $text = trim((string) $value);
        if (preg_match(self::DATE, $text)) {
            return (string) DisplayDate::date($text, $language);
        }
        if (preg_match(self::DATE_TIME, $text)) {
            return (string) DisplayDate::dateTime($text, $language);
        }
        return $text;
    }
}
