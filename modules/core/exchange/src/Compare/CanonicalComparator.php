<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Compare;

/**
 * Porovnávač canonicalu `shpd.docs.document.v1` nad papírovou projekcí
 * (#103 D27; tasks/testing-phase3.md). Obě strany projde stejnou cestou:
 * {@see Projection::project()} → {@see ValueShapes::normalize()} →
 * {@see EquivalentForms::collapse()}, pak porovná list po listu — čísla
 * s tolerancí, pole po pozicích, objekty po klíčích projekce. Výstup je
 * seznam rozdílů `{cesta, očekáváno, skutečnost}`.
 *
 * Ve F3 hlídá věrnost apply (export potvrzeného dokladu ≟ návrh), ve F4
 * výstup analýzy ≟ očekávání korpusu. Výjimky věrnosti (pole, která apply
 * mění záměrně) nejsou věc porovnávače — volající je předá jako
 * `$ignorePaths` a zdůvodní u sebe.
 */
final class CanonicalComparator
{
    public const TOLERANCE = 0.005;

    public function __construct(
        private readonly Projection $projection,
        private readonly float $tolerance = self::TOLERANCE,
    ) {}

    public function projection(): Projection
    {
        return $this->projection;
    }

    /**
     * Jedna strana připravená k porovnání (projekce, tvary, rovnocenné tvary).
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public function prepare(array $doc): array
    {
        return EquivalentForms::collapse(ValueShapes::normalize($this->projection, $this->projection->project($doc)));
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     * @param list<string>         $ignorePaths cesty uzlů projekce (`rows[].item.ean`, `customer`) —
     *                                          podstrom se neporovnává
     * @return list<Difference>
     */
    public function compare(array $expected, array $actual, array $ignorePaths = []): array
    {
        $differences = [];
        $this->diff($this->prepare($expected), $this->prepare($actual), '', $differences);
        if ($ignorePaths === []) {
            return $differences;
        }
        return array_values(array_filter(
            $differences,
            static fn (Difference $d): bool => !self::isIgnored($d->nodePath(), $ignorePaths),
        ));
    }

    /** @param list<string> $ignorePaths */
    public static function isIgnored(string $nodePath, array $ignorePaths): bool
    {
        foreach ($ignorePaths as $ignored) {
            if ($nodePath === $ignored || str_starts_with($nodePath, $ignored . '.') || str_starts_with($nodePath, $ignored . '[')) {
                return true;
            }
        }
        return false;
    }

    /** @param list<Difference> $out */
    private function diff(mixed $a, mixed $b, string $path, array &$out): void
    {
        if ($a === null && $b === null) {
            return;
        }
        if (is_array($a) && is_array($b)) {
            $aList = array_is_list($a);
            $bList = array_is_list($b);
            if ($aList && $bList) {
                if (count($a) !== count($b)) {
                    $out[] = new Difference($path, count($a), count($b));
                }
                $n = min(count($a), count($b));
                for ($i = 0; $i < $n; $i++) {
                    $this->diff($a[$i], $b[$i], "{$path}[{$i}]", $out);
                }
                return;
            }
            if (!$aList && !$bList) {
                foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
                    $childPath = $path === '' ? (string) $key : "{$path}.{$key}";
                    $this->diff($a[$key] ?? null, $b[$key] ?? null, $childPath, $out);
                }
                return;
            }
            $out[] = new Difference($path, $a, $b);
            return;
        }
        if (self::isNumber($a) && self::isNumber($b)) {
            if (abs((float) $a - (float) $b) > $this->tolerance) {
                $out[] = new Difference($path, $a, $b);
            }
            return;
        }
        if ($a === $b) {
            return;
        }
        $out[] = new Difference($path, $a, $b);
    }

    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }
}
