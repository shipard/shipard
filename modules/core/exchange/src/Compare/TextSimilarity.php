<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Compare;

/**
 * Podobnost volného textu pro evaluaci (#103 D37): Levenshteinova
 * vzdálenost nad znaky (ne byty — čeština), poměr `1 − d / max(délka)`
 * v rozsahu 0–1. Sjednocení mezer (`collapseWhitespace`) dělá
 * porovnávač před výpočtem podobnosti.
 */
final class TextSimilarity
{
    private function __construct()
    {
    }

    /** Okrajové mezery pryč, bílé znaky uvnitř na jednu mezeru. */
    public static function collapseWhitespace(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function ratio(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }
        $ca = mb_str_split($a);
        $cb = mb_str_split($b);
        $max = max(count($ca), count($cb));
        if ($max === 0) {
            return 1.0;
        }
        return round(1 - self::distance($ca, $cb) / $max, 4);
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function distance(array $a, array $b): int
    {
        $n = count($a);
        $m = count($b);
        if ($n === 0) {
            return $m;
        }
        if ($m === 0) {
            return $n;
        }
        $previous = range(0, $m);
        for ($i = 1; $i <= $n; $i++) {
            $current = [$i];
            for ($j = 1; $j <= $m; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $current[$j] = min($previous[$j] + 1, $current[$j - 1] + 1, $previous[$j - 1] + $cost);
            }
            $previous = $current;
        }
        return $previous[$m];
    }
}
