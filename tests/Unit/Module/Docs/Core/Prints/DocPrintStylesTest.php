<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core\Prints;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Typografické role tisku dokladu (#162 D125–D129): velikost písma, tučná
 * tloušťka a šedá jdou ve stylech tisku jen z proměnných rolí v
 * `doc-base.css`. Záhlaví a zápatí jsou samostatné dokumenty bez tohoto
 * souboru — jejich literální hodnoty musí proměnným odpovídat. Čáry mají
 * dvě role barvy a jednu tloušťku (D135).
 *
 * Hlídá návrat starých hodnot (8pt, 700 natvrdo, šedé popisky, verzálky,
 * černé čáry), ne vzhled — ten se ověřuje nad vyrenderovaným PDF.
 */
class DocPrintStylesTest extends TestCase
{
    private const LAYOUT = '/modules/docs/core/prints/_layout';

    private static function root(): string
    {
        return dirname(__DIR__, 6);
    }

    private static function read(string $path): string
    {
        $content = file_get_contents(self::root() . $path);
        self::assertIsString($content, $path);
        return $content;
    }

    /** CSS bez komentářů. */
    private static function css(string $path): string
    {
        return (string) preg_replace('~/\*.*?\*/~s', '', self::read($path));
    }

    /**
     * Deklarace vlastnosti jako [selektor, hodnota] — stačí ploché CSS bez
     * vnořených bloků, jaké tisk má.
     *
     * @return list<array{string, string}>
     */
    private static function declarations(string $css, string $property): array
    {
        $found = [];
        preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $rules, PREG_SET_ORDER);
        foreach ($rules as $rule) {
            preg_match_all('~(?<![\w-])' . preg_quote($property, '~') . '\s*:\s*([^;]+);~', $rule[2], $values);
            foreach ($values[1] as $value) {
                $found[] = [trim((string) preg_replace('~\s+~', ' ', $rule[1])), trim($value)];
            }
        }
        return $found;
    }

    /** @return array<string, string> */
    private static function roles(): array
    {
        self::assertSame(1, preg_match('~:root\s*\{([^}]*)\}~', self::css(self::LAYOUT . '/doc-base.css'), $m));
        preg_match_all('~(--print-[\w-]+)\s*:\s*([^;]+);~', $m[1], $vars);
        return array_combine($vars[1], array_map(trim(...), $vars[2]));
    }

    public function testRolesAreDeclaredOnce(): void
    {
        $roles = self::roles();

        // D126: page 13 pt, title 11,5 pt, heading 10,5 pt, body 9 pt, meta 7,5 pt.
        $this->assertSame('13pt', $roles['--print-font-page']);
        $this->assertSame('11.5pt', $roles['--print-font-title']);
        $this->assertSame('10.5pt', $roles['--print-font-heading']);
        $this->assertSame('9pt', $roles['--print-font-body']);
        $this->assertSame('7.5pt', $roles['--print-font-meta']);
        $this->assertSame('#666', $roles['--print-color-meta']);
        $this->assertContains($roles['--print-weight-strong'], ['600', '700'], 'jedna tučná tloušťka');

        // D135: dvě barvy čar, jedna tloušťka.
        $this->assertSame('#c8c8c8', $roles['--print-color-rule']);
        $this->assertSame('#e6e6e6', $roles['--print-color-rule-light']);
        $this->assertSame('0.5pt', $roles['--print-rule-width']);
    }

    /** @return array<string, array{string}> */
    public static function stylesheets(): array
    {
        return [
            'doklad'  => [self::LAYOUT . '/doc-base.css'],
            'Kontace' => ['/modules/economy/accounting/prints/docJournal/doc-journal.css'],
        ];
    }

    #[DataProvider('stylesheets')]
    public function testSizesWeightsAndGreyComeOnlyFromRoles(string $path): void
    {
        $css = self::css($path);
        $allowed = [
            'font-size'   => '~^(var\(--print-font-(page|title|heading|body|meta)\)|inherit)$~',
            'font-weight' => '~^(400|var\(--print-weight-strong\))$~',
            'color'       => '~^(#000|inherit|var\(--print-color-meta\))$~',
        ];

        foreach ($allowed as $property => $pattern) {
            foreach (self::declarations($css, $property) as [$selector, $value]) {
                // Vodoznak není text dokladu: vlastní velikost i šedá.
                if ($selector === '.doc-watermark' && $property !== 'font-weight') {
                    continue;
                }
                $this->assertMatchesRegularExpression(
                    $pattern,
                    $value,
                    "{$path}: `{$selector}` má `{$property}: {$value}` mimo role tisku",
                );
            }
        }
    }

    /**
     * Deklarace čar jako [selektor, vlastnost, hodnota] — `border`,
     * `border-<strana>` a jejich `-color` / `-width` / `-style`.
     *
     * @return list<array{string, string, string}>
     */
    private static function borders(string $css): array
    {
        $found = [];
        preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $rules, PREG_SET_ORDER);
        foreach ($rules as $rule) {
            preg_match_all(
                '~(?<![\w-])(border(?:-(?:top|right|bottom|left))?(?:-(?:color|width|style))?)\s*:\s*([^;]+);~',
                $rule[2],
                $declarations,
                PREG_SET_ORDER,
            );
            foreach ($declarations as $declaration) {
                $found[] = [
                    trim((string) preg_replace('~\s+~', ' ', $rule[1])),
                    $declaration[1],
                    trim($declaration[2]),
                ];
            }
        }
        return $found;
    }

    #[DataProvider('stylesheets')]
    public function testRulesComeOnlyFromRoles(string $path): void
    {
        $role = '~^var\(--print-rule-width\) solid var\(--print-color-rule(-light)?\)$~';

        foreach (self::borders(self::css($path)) as [$selector, $property, $value]) {
            $pattern = match ($selector) {
                // Linka k podpisu není členění dokladu — zůstává černá.
                '.doc-signature' => '~^var\(--print-rule-width\) solid #000$~',
                // Citace v uživatelském textu: silnější, barva z role.
                '.print-text blockquote' => '~^1\.5pt solid var\(--print-color-rule\)$~',
                default => $role,
            };
            if ($value === '0') {
                continue;
            }
            $this->assertMatchesRegularExpression(
                $pattern,
                $value,
                "{$path}: `{$selector}` má `{$property}: {$value}` mimo role čar",
            );
        }
    }

    public function testRuleRolesAreAssignedByPlace(): void
    {
        $byRole = ['rule' => [], 'rule-light' => []];
        foreach (self::stylesheets() as [$path]) {
            foreach (self::borders(self::css($path)) as [$selector, , $value]) {
                if (preg_match('~var\(--print-color-(rule(?:-light)?)\)~', $value, $m) === 1) {
                    $byRole[$m[1]][] = $selector;
                }
            }
        }
        $byRole = array_map(static function (array $selectors): array {
            $selectors = array_values(array_unique($selectors));
            sort($selectors);
            return $selectors;
        }, $byRole);

        // D135: světlá čára jen mezi řádky tabulek, strukturní všude jinde.
        $this->assertSame(['.doc-journal tbody tr', '.doc-rows tbody tr'], $byRole['rule-light']);
        $this->assertSame(
            [
                '.doc-info',
                '.doc-journal .row-total td',
                '.doc-totals-due th, .doc-totals-due td',
                '.print-text blockquote',
                '.print-text hr',
                'thead th',
            ],
            $byRole['rule'],
        );
    }

    public function testKeyValueIsStrong(): void
    {
        // D136: hodnota splatnosti a platební reference tučně, popisek ne.
        $weights = [];
        foreach (self::declarations(self::css(self::LAYOUT . '/doc-base.css'), 'font-weight') as [$selector, $value]) {
            $weights[$selector] = $value;
        }
        $this->assertSame('var(--print-weight-strong)', $weights['.doc-key-value'] ?? null);
        $this->assertArrayNotHasKey('dt', $weights);
    }

    public function testPartyHeadingIsHeadingSizeAndNormalWeight(): void
    {
        $css = self::css(self::LAYOUT . '/doc-base.css');

        // D137: nadpis strany heading, normální tloušťka; název heading tučně.
        $this->assertSame(1, preg_match('~(?<![\w.-])h2\s*\{([^}]*)\}~', $css, $heading));
        $this->assertStringContainsString('font-size: var(--print-font-heading);', $heading[1]);
        $this->assertStringContainsString('font-weight: 400;', $heading[1]);

        $this->assertSame(1, preg_match('~\.party-name\s*\{([^}]*)\}~', $css, $name));
        $this->assertStringContainsString('font-size: var(--print-font-heading);', $name[1]);
        $this->assertStringContainsString('font-weight: var(--print-weight-strong);', $name[1]);
    }

    public function testCourtRegistrationHasTopSpacing(): void
    {
        // D140: zápis v rejstříku odsazený jako adresa od identifikátorů.
        $css = self::css(self::LAYOUT . '/doc-base.css');
        $this->assertSame(1, preg_match('~\.party-detail\s*\{([^}]*)\}~', $css, $detail));
        $this->assertSame(1, preg_match('~\.party-address\s*\{([^}]*)\}~', $css, $address));
        $this->assertSame(1, preg_match('~margin-top:\s*([^;]+);~', $detail[1], $top));
        $this->assertSame(1, preg_match('~margin-bottom:\s*([^;]+);~', $address[1], $bottom));
        $this->assertSame(trim($bottom[1]), trim($top[1]));
    }

    #[DataProvider('stylesheets')]
    public function testNoUppercaseOrLetterSpacing(string $path): void
    {
        $css = self::css($path);

        // D127: žádné verzálky ani prostrkání.
        $this->assertSame([], self::declarations($css, 'text-transform'), $path);
        $this->assertSame([], self::declarations($css, 'letter-spacing'), $path);
    }

    public function testGreyIsOnlyForSupplements(): void
    {
        $grey = [];
        foreach (self::declarations(self::css(self::LAYOUT . '/doc-base.css'), 'color') as [$selector, $value]) {
            if ($value === 'var(--print-color-meta)') {
                $grey[] = $selector;
            }
        }
        sort($grey);

        // D128: sleva na řádku, kurz, přepočet v domácí měně, popisek QR.
        // Popisky, hlavičky tabulek, textové řádky ani zápis v rejstříku šedé nejsou.
        $this->assertSame(
            ['.doc-qr', '.doc-rate', '.doc-totals-home th, .doc-totals-home td', '.row-discount'],
            $grey,
        );
    }

    public function testCustomerHasNoFrame(): void
    {
        // D129: rámeček kolem Odběratele zanikl i s třídou.
        $this->assertStringNotContainsString('party--customer', self::read(self::LAYOUT . '/doc-base.css'));
        $this->assertStringNotContainsString(
            'party--customer',
            self::read('/modules/docs/core/prints/_partials/parties.html.twig'),
        );
        $this->assertStringNotContainsString(
            'party--customer',
            self::read('/modules/economy/accounting/prints/docJournal/page.html.twig'),
        );
    }

    public function testHeaderLiteralsMatchRoles(): void
    {
        $roles  = self::roles();
        $header = self::read(self::LAYOUT . '/header.html.twig');

        $this->assertSame(1, preg_match('~\.head-title\s*\{([^}]*)\}~', $header, $title));
        $this->assertStringContainsString('font-size: ' . $roles['--print-font-page'] . ';', $title[1]);
        $this->assertStringContainsString('font-weight: ' . $roles['--print-weight-strong'] . ';', $title[1]);

        $this->assertSame(1, preg_match('~\.head-number\s*\{([^}]*)\}~', $header, $number));
        $this->assertStringContainsString('font-size: ' . $roles['--print-font-page'] . ';', $number[1]);
        $this->assertStringContainsString('font-weight: 400;', $number[1]);
    }

    public function testFooterLiteralsMatchMetaRole(): void
    {
        $roles  = self::roles();
        $footer = self::read(self::LAYOUT . '/footer.html.twig');

        $this->assertSame(1, preg_match('~\.foot\s*\{([^}]*)\}~', $footer, $foot));
        $this->assertStringContainsString('font-size: ' . $roles['--print-font-meta'] . ';', $foot[1]);
        $this->assertStringContainsString('color: ' . $roles['--print-color-meta'] . ';', $foot[1]);
    }
}
