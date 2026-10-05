<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints\Texts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Prints\Texts\MarkdownEscaper;
use Shipard\Core\Prints\Texts\PrintTextMarkdown;

/**
 * Hodnota z dokladu vložená do uživatelského textu nesmí být Markdown
 * (#90 D50) — a po zpracování Markdownem musí vyjít beze změny.
 */
class MarkdownEscaperTest extends TestCase
{
    private const NBSP = "\u{00A0}";

    /** @return array<string, array{string}> */
    public static function values(): array
    {
        return [
            'zvýraznění'            => ['Firma *Hvězda* a __syn__'],
            'nadpis'                => ['# Akce'],
            'odkaz'                 => ['[klikni](https://example.com)'],
            'obrázek'               => ['![x](https://example.com/x.png)'],
            'kód'                   => ['`rm -rf`'],
            'HTML'                  => ['<b>tučně</b> & <script>alert(1)</script>'],
            'entita'                => ['&amp; &#42;'],
            'zpětné lomítko'        => ['C:\\data\\*.*'],
            'odrážka'               => ['- položka'],
            'plus'                  => ['+ položka'],
            'číslovaný seznam'      => ['1. první'],
            'citace'                => ['> citace'],
            'linka'                 => ['---'],
            'podtržený nadpis'      => ['==='],
            'tabulka'               => ['a | b'],
            'přeškrtnutí'           => ['~~zrušeno~~'],
            'adresa webu'           => ['https://example.com/a_b?x=1&y=2'],
            'e-mail'                => ['info@example.com'],
            'částka cs'             => ['1' . self::NBSP . '210,50' . self::NBSP . 'CZK'],
            'částka de'             => ['1.210,50 EUR'],
            'částka en'             => ['1,210.50'],
            'záporná částka'        => ['-500,00'],
            'datum cs'              => ['2.' . self::NBSP . '10.' . self::NBSP . '2026'],
            'datum cs s mezerami'   => ['2. 10. 2026'],
            'datum de'              => ['02.10.2026'],
            'procenta'              => ['21' . self::NBSP . '%'],
            'číslo dokladu'         => ['FV-2026/000123'],
            'závorky a uvozovky'    => ['Smlouva "A" (dodatek č. 2) {x} [y]'],
        ];
    }

    #[DataProvider('values')]
    public function testEscapedValueSurvivesMarkdownUnchanged(string $value): void
    {
        $html = (new PrintTextMarkdown())->toHtml(MarkdownEscaper::escape($value));

        $this->assertSame('<p>' . htmlspecialchars($value, ENT_NOQUOTES) . '</p>', str_replace('&quot;', '"', $html));
    }

    public function testLineBreaksOfValueBecomeHardBreaks(): void
    {
        $html = (new PrintTextMarkdown())->toHtml(MarkdownEscaper::escape("Dlouhá 1\r\n    760 01 Zlín\n\n# ne nadpis"));

        // Odsazený řádek není blok kódu, prázdný řádek dělí odstavce, `#` je text.
        $this->assertSame("<p>Dlouhá 1<br />\n760 01 Zlín</p>\n<p># ne nadpis</p>", $html);
    }

    public function testValueInsideUserMarkdownKeepsSurroundingFormatting(): void
    {
        $markdown = 'Děkujeme, **' . MarkdownEscaper::escape('Firma *Hvězda* s.r.o.') . '**, za úhradu.';

        $this->assertSame(
            '<p>Děkujeme, <strong>Firma *Hvězda* s.r.o.</strong>, za úhradu.</p>',
            (new PrintTextMarkdown())->toHtml($markdown),
        );
    }

    public function testValueAtLineStartCannotStartAList(): void
    {
        // Datum na začátku řádku by bez escapování začalo číslovaný seznam.
        $html = (new PrintTextMarkdown())->toHtml(MarkdownEscaper::escape('2. 10. 2026') . ' je splatnost.');

        $this->assertSame('<p>2. 10. 2026 je splatnost.</p>', $html);
    }
}
