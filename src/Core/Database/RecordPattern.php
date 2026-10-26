<?php

declare(strict_types=1);

namespace Shipard\Core\Database;

/**
 * Vzor popisku záznamu s placeholdery `{sloupec}` — `displayPattern`
 * tabulky, `detailHeader` vieweru, popisek dimenze deníku (#136 D19).
 *
 * Prázdná hodnota (null, prázdný řetězec) se vynechá i se svým
 * oddělovačem: `{code} — {name}` bez kódu dá „Název“, ne „— Název“.
 * Oddělovačem je literál ze sady `SEPARATORS`; jiný literál (`#`, `/`)
 * zůstává vždy. Datum (`DateTimeInterface`) se formátuje `j. n. Y`.
 */
final class RecordPattern
{
    /** Literály mezi placeholdery, které se při prázdném sousedovi zahazují. */
    private const SEPARATORS = [' — ', ' – ', ' · ', ' - ', ' '];

    private const PLACEHOLDER = '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/';

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
        return array_values(array_unique($m[1]));
    }

    /**
     * Vyplní vzor hodnotami záznamu; `$prefix` = prefix aliasů sloupců ve
     * výsledku dotazu (`d_asset__name`). Null, když vzor má placeholdery
     * a žádný z nich nemá hodnotu, nebo když je výsledek prázdný.
     *
     * @param array<string, mixed> $record
     */
    public function render(array $record, string $prefix = ''): ?string
    {
        $tokens = preg_split(
            '/(\{[a-zA-Z_][a-zA-Z0-9_]*\})/',
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
                $value = self::stringify($record[$prefix . $m[1]] ?? null);
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

    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('j. n. Y');
        }
        return trim((string) $value);
    }
}
