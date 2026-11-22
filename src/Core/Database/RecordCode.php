<?php

declare(strict_types=1);

namespace Shipard\Core\Database;

/**
 * Kód záznamu jako přirozený klíč (`exchangeKey`, #166 D16, D17) — jediné
 * místo pravidla tvaru. Čte ho validace Document tříd i generátory kódů
 * (číselné řady dokladů, registrace DPH).
 *
 * Tvar: písmena bez diakritiky, číslice, tečka, podtržítko a pomlčka,
 * první znak písmeno nebo číslice. Bez mezer, bez `@` a bez dvojtečky —
 * kód jde do reference `"@kód"` a dvojtečka by v ní oddělovala sloupec
 * (#166 D15).
 *
 * Jednoznačnost se posuzuje bez ohledu na velikost písmen: kolace
 * `utf8mb4_czech_ci` bere `FV` a `fv` jako týž kód a unikátní index by
 * druhý z nich odmítl. Kód je jen z ASCII znaků, takže převod na malá
 * písmena ({@see self::key()}) vidí totéž co databáze.
 */
final class RecordCode
{
    public const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/D';

    /** Věta pro validační chybu tvaru — bez názvu pole, ten doplní volající. */
    public const SHAPE_HINT = 'smí obsahovat jen písmena bez diakritiky, číslice, tečku, pomlčku a podtržítko'
        . ' a musí začínat písmenem nebo číslicí';

    public static function isValid(string $code): bool
    {
        return preg_match(self::PATTERN, $code) === 1;
    }

    /** Klíč pro porovnání kódů tak, jak je porovnává databáze. */
    public static function key(string $code): string
    {
        return strtolower($code);
    }

    /**
     * Část kódu z cizí hodnoty (kód pokladny, kód z čísla dokladu, země):
     * ořízne okraje a každý znak mimo povolenou sadu nahradí pomlčkou.
     */
    public static function part(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9._-]/u', '-', trim($value));
    }

    /**
     * Základ kódu složený z částí spojených pomlčkou; prázdné části se
     * vynechají. Výsledek začíná písmenem nebo číslicí, nebo je prázdný
     * (žádná část nenesla použitelný znak).
     */
    public static function base(string ...$parts): string
    {
        $parts = array_filter(array_map(self::part(...), $parts), static fn(string $p): bool => $p !== '');
        return (string) preg_replace('/^[^A-Za-z0-9]+/', '', implode('-', $parts));
    }

    /**
     * Volný kód: `$base`, a je-li obsazený, `$base-2`, `$base-3`, …
     *
     * @param array<string, true> $taken obsazené kódy jako {@see self::key()}
     */
    public static function unique(string $base, array $taken, int $maxLength): string
    {
        $candidate = substr($base, 0, $maxLength);
        if ($candidate !== '' && !isset($taken[self::key($candidate)])) {
            return $candidate;
        }
        return self::numbered($base, $taken, $maxLength);
    }

    /**
     * První volný kód `$base-<n>`, n od `$from` — další záznam téhož druhu
     * (druhá řada typu, druhá registrace země).
     *
     * @param array<string, true> $taken obsazené kódy jako {@see self::key()}
     */
    public static function numbered(string $base, array $taken, int $maxLength, int $from = 2): string
    {
        for ($n = $from; ; $n++) {
            $suffix = '-' . $n;
            $candidate = substr($base, 0, max(0, $maxLength - strlen($suffix))) . $suffix;
            // Prázdný základ by dal kód začínající pomlčkou.
            $candidate = ltrim($candidate, '-');
            if (!isset($taken[self::key($candidate)])) {
                return $candidate;
            }
        }
    }
}
