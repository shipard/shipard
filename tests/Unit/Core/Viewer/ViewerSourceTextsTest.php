<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Viewer;

use PHPUnit\Framework\TestCase;

/**
 * Texty viewerů nejsou literály v PHP (#133 D65–D67): popisek shodný se
 * sloupcem jde z definice tabulky (`TableViewer::columnLabel()`), ostatní
 * z katalogu modulu (`detailText()` / `labelText()`, sdílené
 * `sharedText()`). Test čte zdroje viewerů tokenizerem — komentáře se
 * vynechají samy — a hlídá tři vzory:
 *
 *  1. žádný řetězcový literál s českou diakritikou (ani část
 *     interpolovaného řetězce),
 *  2. žádný literál s písmenem jako popisek: druhý argument `addItem()`
 *     nebo hodnota klíče `'title'` / `'label'` / `'text'` v poli. Anglický
 *     fallback v `detailText(…, 'Fallback')` je argument jiné funkce
 *     a smí zůstat,
 *  3. žádný dvojjazyčný zápis přímo v PHP: literál s písmenem ve větvi
 *     ternárního výrazu, jehož podmínka je proměnná jazyka (`$cs`,
 *     `$isCs`) nebo porovnání s kódem jazyka (`$this->language === 'cs'`,
 *     `… === 'en'`), a literál s písmenem pod klíčem `'cs'` / `'en'`.
 *     Funguje v obou jazycích, ale obchází katalogy. Literál bez písmene
 *     (oddělovač čísla) porušením není — podmínka jazyka smí vybírat
 *     formát, ne text.
 *
 * Co test neuhlídá: text bez diakritiky mimo místo popisku („Ano“ / „Ne“
 * jako hodnota, text složený mimo pole) — ten hlídá jen review.
 */
class ViewerSourceTextsTest extends TestCase
{
    private const DIACRITICS = '/[áčďéěíňóřšťúůýžÁČĎÉĚÍŇÓŘŠŤÚŮÝŽ]/u';

    /** Klíče pole, jejichž hodnota je text pro uživatele. */
    private const LABEL_KEYS = ['title', 'label', 'text'];

    /** Proměnné jazyka v podmínce dvojjazyčného ternáru. */
    private const LANGUAGE_VARIABLES = ['$cs', '$isCs'];

    /** Kódy jazyka, se kterými se podmínka dvojjazyčného ternáru porovnává. */
    private const LANGUAGE_CODES = ['cs', 'en'];

    private const BILINGUAL = 'dvojjazyčný zápis — text do katalogu';

    private const HINT = 'použij columnLabel() (popisek sloupce), detailText() / labelText() (katalog modulu)'
        . ' nebo sharedText() (core.system)';

    public function testViewerSourcesHaveNoSingleLanguageTexts(): void
    {
        $files = self::viewerSources();
        $this->assertNotEmpty($files);

        $violations = [];
        foreach ($files as $file) {
            foreach (self::violationsIn((string) file_get_contents($file)) as [$line, $literal, $rule]) {
                $violations[] = sprintf('%s:%d  %s — %s', self::relative($file), $line, $literal, $rule);
            }
        }

        $this->assertTrue(
            $violations === [],
            'Texty viewerů mimo katalogy (' . count($violations) . ') — ' . self::HINT . ":\n" . implode("\n", $violations),
        );
    }

    public function testDetectsCzechLiteral(): void
    {
        $found = self::violationsIn("<?php\n\$x = ['value' => 'Účet'];\n\$y = \"č. {\$n}\";\n// 'Komentář' nevadí\n");
        $this->assertSame([[2, "'Účet'", 'literál s českou diakritikou'], [3, 'č. ', 'literál s českou diakritikou']], $found);
    }

    public function testDetectsLiteralAsLabel(): void
    {
        $source = <<<'PHP'
            <?php
            $this->addItem($items, 'Status', $value);
            $group = ['title' => 'Identity', 'items' => $items];
            $row = ['label' => 'Model', 'text' => 'from ' . $date];
            PHP;
        $this->assertSame(
            [
                [2, "'Status'", 'literál jako popisek v addItem()'],
                [3, "'Identity'", "literál jako hodnota klíče 'title'"],
                [4, "'Model'", "literál jako hodnota klíče 'label'"],
                [4, "'from '", "literál jako hodnota klíče 'text'"],
            ],
            self::violationsIn($source),
        );
    }

    public function testAllowsCatalogCallsAndNonTexts(): void
    {
        $source = <<<'PHP'
            <?php
            $this->addItem($items, $this->columnLabel('note', 'Note'), $value);
            $this->addItem($items, $this->detailText(self::LABELS, 'groupOther', 'Other'), $value ?? '');
            $group = ['title' => $this->sharedText('groupIdentity', 'Identity'), 'label' => '', 'text' => ' · '];
            $action = ['label' => $def['name'] ?? 'Open', 'class' => 'muted'];
            PHP;
        $this->assertSame([], self::violationsIn($source));
    }

    public function testBilingualNotationIsViolation(): void
    {
        $source = <<<'PHP'
            <?php
            $this->addItem($items, $cs ? 'Účet' : 'Account', $value);
            $group = ['title' => $isCs ? 'Zůstatky' : 'Balances', 'label' => $this->language === 'cs' ? 'Období' : 'Period'];
            $t = ['text' => (($this->language ?? 'en') === 'cs' ? 'splatnost ' : 'due ') . $due];
            $names = ['cs' => 'Česky', 'en' => 'Czech'];
            $yes = $on ? ($cs ? 'Ano' : 'Yes') : ($cs ? 'Ne' : 'No');
            $badge = ['text' => '⚠ ' . ($this->language === 'en' ? 'posting error' : 'chyba účtování')];
            $filed = $cs ? "podáno {$date}" : "filed {$date}";
            PHP;
        $expected = [];
        foreach ([
            [2, "'Účet'"], [2, "'Account'"],
            [3, "'Zůstatky'"], [3, "'Balances'"], [3, "'Období'"], [3, "'Period'"],
            [4, "'splatnost '"], [4, "'due '"],
            [5, "'Česky'"], [5, "'Czech'"],
            [6, "'Ano'"], [6, "'Yes'"], [6, "'Ne'"], [6, "'No'"],
            [7, "'posting error'"], [7, "'chyba účtování'"],
            [8, 'podáno '], [8, 'filed '],
        ] as [$line, $literal]) {
            $expected[] = [$line, $literal, self::BILINGUAL];
        }
        $this->assertSame($expected, self::violationsIn($source));
    }

    public function testLanguageConditionMaySelectNonText(): void
    {
        $source = <<<'PHP'
            <?php
            $amount = number_format($value, 2, $this->language === 'cs' ? ',' : '.', ' ');
            $separator = $cs ? ' · ' : ' | ';
            $marks = ['cs' => '„“', 'en' => '“”'];
            $lang = $this->language ?? 'en';
            PHP;
        $this->assertSame([], self::violationsIn($source));
    }

    public function testTernaryWithoutLanguageConditionIsNotExempt(): void
    {
        $found = self::violationsIn("<?php\n\$x = \$locked ? 'Uzamčeno' : 'Volné';\n");
        $this->assertSame([[2, "'Uzamčeno'", 'literál s českou diakritikou'], [2, "'Volné'", 'literál s českou diakritikou']], $found);
    }

    // ---------------------------------------------------------------------

    /** @return list<string> */
    private static function viewerSources(): array
    {
        $root = dirname(__DIR__, 4);
        $files = [];
        foreach (glob($root . '/modules/*/*/src', GLOB_ONLYDIR) ?: [] as $dir) {
            foreach (self::phpFiles($dir) as $file) {
                if (str_contains(basename($file), 'Viewer')) {
                    $files[] = $file;
                }
            }
        }
        foreach (self::phpFiles($root . '/src/Core/Viewer') as $file) {
            $files[] = $file;
        }
        sort($files);
        return $files;
    }

    /** @return list<string> */
    private static function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    private static function relative(string $file): string
    {
        return substr($file, strlen(dirname(__DIR__, 4)) + 1);
    }

    /**
     * Porušení v jednom zdroji: [řádek, literál, pravidlo].
     *
     * @return list<array{0: int, 1: string, 2: string}>
     */
    private static function violationsIn(string $source): array
    {
        $tokens = self::significantTokens($source);
        $violations = [];

        foreach ($tokens as $i => $token) {
            $isConstant = $token['id'] === T_CONSTANT_ENCAPSED_STRING;
            if (!$isConstant && $token['id'] !== T_ENCAPSED_AND_WHITESPACE) {
                continue;
            }
            $value = $isConstant ? substr($token['text'], 1, -1) : $token['text'];
            if (trim($value) === '') {
                continue;
            }

            $key = $isConstant ? self::arrayKeyOf($tokens, $i) : null;
            // Dvojjazyčný zápis: text má být v katalogu; literál bez písmene
            // (oddělovač čísla) text není.
            if (in_array($key, self::LANGUAGE_CODES, true) || self::inLanguageTernary($tokens, $i)) {
                if (preg_match('/\p{L}/u', $value) === 1) {
                    $violations[] = [$token['line'], $token['text'], self::BILINGUAL];
                }
                continue;
            }

            if (preg_match(self::DIACRITICS, $value) === 1) {
                $violations[] = [$token['line'], $token['text'], 'literál s českou diakritikou'];
                continue;
            }
            if (!$isConstant || preg_match('/\p{L}/u', $value) !== 1) {
                continue;
            }
            if (in_array($key, self::LABEL_KEYS, true)) {
                $violations[] = [$token['line'], $token['text'], "literál jako hodnota klíče '{$key}'"];
            } elseif (self::isAddItemLabel($tokens, $i)) {
                $violations[] = [$token['line'], $token['text'], 'literál jako popisek v addItem()'];
            }
        }

        return $violations;
    }

    /**
     * Tokeny bez mezer a komentářů; jednoznakové tokeny dostanou řádek
     * předchozího tokenu.
     *
     * @return list<array{id: ?int, text: string, line: int}>
     */
    private static function significantTokens(string $source): array
    {
        $out = [];
        $line = 1;
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $line = $token[2];
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out[] = ['id' => $token[0], 'text' => $token[1], 'line' => $line];
            } else {
                $out[] = ['id' => null, 'text' => $token, 'line' => $line];
            }
        }
        return $out;
    }

    /**
     * Klíč pole, pod kterým literál stojí hned za `=>` (`'label' => 'X'`);
     * jinak null.
     *
     * @param list<array{id: ?int, text: string, line: int}> $tokens
     */
    private static function arrayKeyOf(array $tokens, int $i): ?string
    {
        $arrow = $tokens[$i - 1] ?? null;
        $key = $tokens[$i - 2] ?? null;
        if ($arrow === null || $key === null || $arrow['id'] !== T_DOUBLE_ARROW || $key['id'] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        return substr($key['text'], 1, -1);
    }

    /**
     * Stojí literál na začátku druhého argumentu `addItem(…)`?
     *
     * @param list<array{id: ?int, text: string, line: int}> $tokens
     */
    private static function isAddItemLabel(array $tokens, int $i): bool
    {
        if (($tokens[$i - 1]['text'] ?? '') !== ',') {
            return false;
        }
        $depth = 0;
        for ($j = $i - 2; $j >= 0; $j--) {
            $text = $tokens[$j]['text'];
            if ($text === ')' || $text === ']') {
                $depth++;
            } elseif ($text === '(' || $text === '[') {
                if ($depth === 0) {
                    $function = $tokens[$j - 1] ?? null;
                    return $text === '(' && $function !== null
                        && $function['id'] === T_STRING && $function['text'] === 'addItem';
                }
                $depth--;
            } elseif ($depth === 0 && ($text === ',' || $text === ';')) {
                return false; // třetí a další argument, nebo jiný příkaz
            }
        }
        return false;
    }

    /**
     * Je literál ve větvi ternárního výrazu s jazykovou podmínkou
     * (`$cs ? … : …`, `$this->language === 'cs' ? … : …`, `… === 'en' ? … : …`)? Hledá se zpět
     * v rámci téhož výrazu — čárka, středník, přiřazení a `=>` ho ukončují.
     * Interpolovaný řetězec (`"podáno {$date}"`) se bere jako celek: od
     * jeho části se začíná před úvodní uvozovkou a řetězec dřívější větve
     * se přeskočí — složené závorky uvnitř výraz neukončují.
     *
     * @param list<array{id: ?int, text: string, line: int}> $tokens
     */
    private static function inLanguageTernary(array $tokens, int $i): bool
    {
        $depth = 0;
        $start = $i - 1;
        if ($tokens[$i]['id'] === T_ENCAPSED_AND_WHITESPACE) {
            $start = self::openingQuote($tokens, $i) - 1;
        }
        for ($j = $start; $j >= 0; $j--) {
            $token = $tokens[$j];
            $text = $token['text'];
            if ($text === '"') {
                $j = self::openingQuote($tokens, $j); // celý řetězec dřívější větve
                continue;
            }
            if ($text === ')' || $text === ']') {
                $depth++;
                continue;
            }
            if ($text === '(' || $text === '[') {
                if ($depth === 0) {
                    continue; // závorka kolem větve — výraz pokračuje vně
                }
                $depth--;
                continue;
            }
            if ($depth > 0) {
                continue;
            }
            if (in_array($text, [';', ',', '{', '}', '='], true) || $token['id'] === T_DOUBLE_ARROW) {
                return false;
            }
            if ($text === '?') {
                return self::isLanguageCondition($tokens, $j - 1);
            }
        }
        return false;
    }

    /**
     * Index úvodní uvozovky interpolovaného řetězce před pozicí `$i`
     * (uvozovka je u něj samostatný token); -1, když žádná není.
     *
     * @param list<array{id: ?int, text: string, line: int}> $tokens
     */
    private static function openingQuote(array $tokens, int $i): int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if ($tokens[$j]['text'] === '"') {
                return $j;
            }
        }
        return -1;
    }

    /**
     * Končí na pozici `$end` jazyková podmínka: proměnná jazyka, porovnání
     * s kódem jazyka (`'cs'` / `'en'`), nebo závorka, která ho obsahuje?
     *
     * @param list<array{id: ?int, text: string, line: int}> $tokens
     */
    private static function isLanguageCondition(array $tokens, int $end): bool
    {
        $last = $tokens[$end] ?? null;
        if ($last === null) {
            return false;
        }
        if ($last['id'] === T_VARIABLE) {
            return in_array($last['text'], self::LANGUAGE_VARIABLES, true);
        }
        if ($last['id'] === T_CONSTANT_ENCAPSED_STRING) {
            return in_array(substr($last['text'], 1, -1), self::LANGUAGE_CODES, true);
        }
        if ($last['text'] !== ')') {
            return false;
        }
        $depth = 0;
        for ($k = $end; $k >= 0; $k--) {
            $text = $tokens[$k]['text'];
            if ($text === ')') {
                $depth++;
            } elseif ($text === '(') {
                $depth--;
                if ($depth === 0) {
                    return false;
                }
            } elseif ($tokens[$k]['id'] === T_CONSTANT_ENCAPSED_STRING && in_array(substr($text, 1, -1), self::LANGUAGE_CODES, true)) {
                return true;
            }
        }
        return false;
    }
}
