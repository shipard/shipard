<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Compare;

/**
 * Porovnávač canonicalu `shpd.docs.document.v1` nad papírovou projekcí
 * (#103 D27; tasks/testing-phase3.md). Obě strany projde stejnou cestou:
 * {@see Projection::project()} → {@see ValueShapes::normalize()} →
 * {@see EquivalentForms::collapse()}, pak porovná list po listu — čísla
 * s tolerancí, pole po pozicích (klíčovaná pole podle klíče prvku,
 * {@see KEYED_LISTS}), objekty po klíčích projekce. Výstup je seznam
 * rozdílů `{cesta, očekáváno, skutečnost}`.
 *
 * Ve F3 hlídá věrnost apply (export potvrzeného dokladu ≟ návrh), ve F4
 * výstup analýzy ≟ očekávání korpusu. Výjimky věrnosti (pole, která apply
 * mění záměrně) nejsou věc porovnávače — volající je předá jako
 * `$ignorePaths` a zdůvodní u sebe.
 *
 * Režim evaluace ({@see evaluate()}, #103 D37): místo seznamu rozdílů
 * stav **každého listu** projekce ({@see FieldOutcome}, {@see FieldState})
 * — `match` (shoda syrových hodnot po projekci), `matchNormalized` (shoda
 * až po tvarech hodnot, rovnocenných tvarech, toleranci čísel, sjednocení
 * mezer nebo podobnosti textu ≥ {@see TEXT_SIMILARITY_MATCH}), `missing`,
 * `extra`, `wrong`. Podobnost textu jen u uzlů {@see FREE_TEXT_NODES};
 * sjednocení mezer u každého listu tvaru `text`. Pole po pozicích; rozdíl
 * délky je jeden výsledek na cestě pole s počty prvků. Výjimka: pole
 * z {@see KEYED_LISTS} se párují hodnotou klíče prvku (výjimky štítků
 * řádků podle `rowIndex`) — prvek jen na jedné straně je jeden `missing`
 * / `extra` na cestě `pole[klíč]`, nic se neposouvá. Nullable uzel
 * (`customer`) prázdný na obou stranách je jeden `match` na cestě uzlu.
 */
final class CanonicalComparator
{
    public const TOLERANCE = 0.005;
    /** Podobnost volného textu, od které se liší jen tvarem (D37). */
    public const TEXT_SIMILARITY_MATCH = 0.9;
    /** Jméno tvaru projekce, u kterého se sjednocují mezery. */
    private const TEXT_SHAPE = 'text';
    /**
     * Uzly volného textu (D37: názvy stran a položek, popisy řádků, poznámka
     * na dokladu, jména kontaktů, spisová značka) — jen u nich se měří
     * podobnost; číslo dokladu, adresy nebo jednotka s překlepem jsou chyba.
     */
    public const FREE_TEXT_NODES = [
        'supplier.name', 'customer.name', 'supplier.courtRegistration', 'customer.courtRegistration',
        'supplier.contact.name', 'customer.contact.name',
        'rows[].item.name', 'rows[].item.description', 'notes.onDocument',
    ];
    /**
     * Pole, jejichž prvek identifikuje hodnota klíče, ne pozice (uzel →
     * klíč): výjimka štítku řádku patří k `rows[rowIndex]`. Po pozicích by
     * výjimka navíc na nižším řádku posunula všechny další do `wrong`
     * (baseline 2026-10-15: 3 výjimky navíc = 16× „rowIndex posunutý
     * o −3“, tasks/testing-phase4.md § 8). V cestě výsledku je pak
     * v hranaté závorce klíč (`rowExceptions[28].tag`), ne pozice. Prvek
     * bez celočíselného klíče ≥ 0 nebo duplicitní klíč na jedné straně →
     * celé pole po pozicích.
     */
    public const KEYED_LISTS = ['_resolve.contentTag.rowExceptions' => 'rowIndex'];

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

    /**
     * Režim evaluace: stav každého listu projekce (viz docblock třídy).
     * Obě strany projdou projekcí (syrové hodnoty do výsledků) i plnou
     * přípravou jako {@see compare()} (rozhodnutí o shodě po normalizaci).
     *
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     * @return list<FieldOutcome>
     */
    public function evaluate(array $expected, array $actual): array
    {
        $out = [];
        $this->evaluateNode(
            $this->projection->document(),
            $this->projection->project($expected),
            $this->projection->project($actual),
            $this->prepare($expected),
            $this->prepare($actual),
            '',
            null,
            $out,
        );
        return $out;
    }

    /**
     * Režim evaluace pro selhané volání (#103 D44): stav listů očekávání
     * proti prázdnému výstupu. Každý neprázdný list očekávání (po
     * normalizaci — `0` ≡ null ani prázdný text se nepočítá) je `missing`
     * na své cestě, pole po prvcích, nullable uzel prázdný v očekávání
     * nedává nic; shody se nevracejí. Metriky polí tak selhání započítají
     * jako chybějící pole dokladu, ne jako doklad, který neexistoval.
     *
     * @param array<string, mixed> $expected
     * @return list<FieldOutcome> jen `missing`
     */
    public function evaluateAgainstNothing(array $expected): array
    {
        $blank = $this->projection->map($this->projection->project($expected), static fn (): mixed => null);
        return array_values(array_filter(
            $this->evaluate($expected, $blank),
            static fn (FieldOutcome $o): bool => !$o->state->isHit(),
        ));
    }

    /**
     * @param array<string, mixed>|null $parent syrový projektovaný rodič (pro `byCountry`)
     * @param list<FieldOutcome>        $out
     */
    private function evaluateNode(mixed $spec, mixed $rawE, mixed $rawA, mixed $normE, mixed $normA, string $path, ?array $parent, array &$out): void
    {
        $spec = $this->projection->resolve($spec);

        if (is_array($spec) && array_is_list($spec)) {
            $listE = self::asList($rawE);
            $listA = self::asList($rawA);
            if ($listE === null || $listA === null) {
                $out[] = new FieldOutcome($path, FieldState::Wrong, $rawE, $rawA);
                return;
            }
            $normListE = self::asList($normE) ?? [];
            $normListA = self::asList($normA) ?? [];
            $keyed = self::keyedPositions($path, $listE, $listA);
            if ($keyed !== null) {
                [$posE, $posA] = $keyed;
                foreach ($posE + $posA as $key => $_) {
                    $iE = $posE[$key] ?? null;
                    $iA = $posA[$key] ?? null;
                    $this->evaluateNode(
                        $spec[0],
                        $iE === null ? null : $listE[$iE],
                        $iA === null ? null : $listA[$iA],
                        $iE === null ? null : ($normListE[$iE] ?? null),
                        $iA === null ? null : ($normListA[$iA] ?? null),
                        "{$path}[{$key}]",
                        null,
                        $out,
                    );
                }
                return;
            }
            $countE = count($listE);
            $countA = count($listA);
            if ($countE !== $countA) {
                $out[] = new FieldOutcome($path, $countE > $countA ? FieldState::Missing : FieldState::Extra, $countE, $countA);
            }
            for ($i = 0, $n = min($countE, $countA); $i < $n; $i++) {
                $this->evaluateNode($spec[0], $listE[$i], $listA[$i], $normListE[$i] ?? null, $normListA[$i] ?? null, "{$path}[{$i}]", null, $out);
            }
            return;
        }

        if (is_array($spec)) {
            // Objekt na obou stranách (i samé null — projekce chybějící objekt
            // doplní): rozhodují listy. Jinak jeden výsledek za uzel — nullable
            // uzel (`customer`) prázdný, nebo skalár místo objektu.
            if (self::isObject($rawE) && self::isObject($rawA)) {
                foreach ($spec as $key => $sub) {
                    $childPath = $path === '' ? (string) $key : "{$path}.{$key}";
                    $this->evaluateNode(
                        $sub,
                        $rawE[$key] ?? null,
                        $rawA[$key] ?? null,
                        is_array($normE) ? ($normE[$key] ?? null) : null,
                        is_array($normA) ? ($normA[$key] ?? null) : null,
                        $childPath,
                        $rawE,
                        $out,
                    );
                }
                return;
            }
            if ($rawE === null && $rawA === null) {
                $out[] = new FieldOutcome($path, FieldState::Match, null, null);
                return;
            }
            if ($normE === null && $normA === null) {
                $out[] = new FieldOutcome($path, FieldState::MatchNormalized, $rawE, $rawA);   // null ≡ objekt se samými null
                return;
            }
            if ($normE === null) {
                $out[] = new FieldOutcome($path, FieldState::Extra, $rawE, $rawA);
                return;
            }
            if ($normA === null) {
                $out[] = new FieldOutcome($path, FieldState::Missing, $rawE, $rawA);
                return;
            }
            $out[] = new FieldOutcome($path, FieldState::Wrong, $rawE, $rawA);
            return;
        }

        $out[] = $this->evaluateLeaf(is_string($spec) ? $spec : null, $rawE, $rawA, $normE, $normA, $path, $parent);
    }

    /** @param array<string, mixed>|null $parent */
    private function evaluateLeaf(?string $shapeName, mixed $rawE, mixed $rawA, mixed $normE, mixed $normA, string $path, ?array $parent): FieldOutcome
    {
        if ($rawE === $rawA) {
            return new FieldOutcome($path, FieldState::Match, $rawE, $rawA);
        }
        if ($normE === null && $normA === null) {
            return new FieldOutcome($path, FieldState::MatchNormalized, $rawE, $rawA);   // 0 ≡ null, prázdné pole ≡ null
        }
        if ($normE === null) {
            return new FieldOutcome($path, FieldState::Extra, $rawE, $rawA);
        }
        if ($normA === null) {
            return new FieldOutcome($path, FieldState::Missing, $rawE, $rawA);
        }
        if (self::isNumber($normE) && self::isNumber($normA)) {

            $state = abs((float) $normE - (float) $normA) > $this->tolerance ? FieldState::Wrong : FieldState::MatchNormalized;
            return new FieldOutcome($path, $state, $rawE, $rawA);
        }
        if ($normE === $normA) {
            return new FieldOutcome($path, FieldState::MatchNormalized, $rawE, $rawA);
        }
        if ($shapeName !== null && is_string($normE) && is_string($normA) && $this->isTextShape($shapeName, $parent)) {
            $textE = TextSimilarity::collapseWhitespace($normE);
            $textA = TextSimilarity::collapseWhitespace($normA);
            if ($textE === $textA) {
                return new FieldOutcome($path, FieldState::MatchNormalized, $rawE, $rawA, 1.0);
            }
            if (!in_array(Projection::nodePath($path), self::FREE_TEXT_NODES, true)) {
                return new FieldOutcome($path, FieldState::Wrong, $rawE, $rawA);
            }
            $similarity = TextSimilarity::ratio($textE, $textA);
            $state = $similarity >= self::TEXT_SIMILARITY_MATCH ? FieldState::MatchNormalized : FieldState::Wrong;
            return new FieldOutcome($path, $state, $rawE, $rawA, $similarity);
        }
        return new FieldOutcome($path, FieldState::Wrong, $rawE, $rawA);
    }

    /** @param array<string, mixed>|null $parent */
    private function isTextShape(string $shapeName, ?array $parent): bool
    {
        try {
            [$name] = $this->projection->shapeOf($shapeName, $parent);
        } catch (ProjectionException) {
            return false;
        }
        return $name === self::TEXT_SHAPE;
    }

    /**
     * Párování klíčovaného pole ({@see KEYED_LISTS}): pro obě strany mapa
     * klíč prvku → pozice v seznamu, v pořadí seznamu. Null = pole není
     * klíčované, nebo některá strana nemá použitelné klíče → po pozicích.
     *
     * @param list<mixed> $listE
     * @param list<mixed> $listA
     * @return array{0: array<int, int>, 1: array<int, int>}|null
     */
    private static function keyedPositions(string $path, array $listE, array $listA): ?array
    {
        $keyField = self::KEYED_LISTS[Projection::nodePath($path)] ?? null;
        if ($keyField === null) {
            return null;
        }
        $posE = self::keyPositions($listE, $keyField);
        $posA = self::keyPositions($listA, $keyField);
        return $posE === null || $posA === null ? null : [$posE, $posA];
    }

    /**
     * @param list<mixed> $list
     * @return array<int, int>|null klíč → pozice; null = prvek bez celočíselného klíče ≥ 0 nebo duplicita
     */
    private static function keyPositions(array $list, string $keyField): ?array
    {
        $positions = [];
        foreach ($list as $i => $entry) {
            $key = is_array($entry) ? ($entry[$keyField] ?? null) : null;
            if (!is_int($key) && !(is_numeric($key) && (string) (int) $key === (string) $key)) {
                return null;
            }
            $key = (int) $key;
            if ($key < 0 || isset($positions[$key])) {
                return null;
            }
            $positions[$key] = $i;
        }
        return $positions;
    }

    /** @return list<mixed>|null null = není seznam (null a `[]` jsou prázdný seznam) */
    private static function asList(mixed $value): ?array
    {
        if ($value === null || $value === []) {
            return [];
        }
        return is_array($value) && array_is_list($value) ? array_values($value) : null;
    }

    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
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
                $keyed = self::keyedPositions($path, $a, $b);
                if ($keyed !== null) {
                    [$posA, $posB] = $keyed;
                    foreach ($posA + $posB as $key => $_) {
                        $this->diff(
                            isset($posA[$key]) ? $a[$posA[$key]] : null,
                            isset($posB[$key]) ? $b[$posB[$key]] : null,
                            "{$path}[{$key}]",
                            $out,
                        );
                    }
                    return;
                }
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
