<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints\Texts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Prints\Texts\PrintTextMarkdown;

/**
 * Markdown textů na tiscích (#90 D50). Výstup jde do šablony přes `|raw`,
 * takže nesmí propustit HTML, obrázek ani nebezpečný odkaz.
 */
class PrintTextMarkdownTest extends TestCase
{
    private static function html(string $markdown): string
    {
        return (new PrintTextMarkdown())->toHtml($markdown);
    }

    public function testBasicFormatting(): void
    {
        $this->assertSame(
            '<p>Příští týden máme <strong>dovolenou</strong> a <em>zavřeno</em>.</p>',
            self::html('Příští týden máme **dovolenou** a *zavřeno*.'),
        );
        $this->assertSame("<ul>\n<li>první</li>\n<li>druhý</li>\n</ul>", self::html("- první\n- druhý"));
        $this->assertSame("<ol>\n<li>první</li>\n<li>druhý</li>\n</ol>", self::html("1. první\n2. druhý"));
        $this->assertSame("<p>jeden</p>\n<p>druhý</p>", self::html("jeden\n\ndruhý"));
        $this->assertSame('<p><del>zrušeno</del></p>', self::html('~~zrušeno~~'));
    }

    /** @return array<string, array{string}> */
    public static function htmlInput(): array
    {
        return [
            'inline tag'  => ['Text <b>tučně</b>'],
            'skript'      => ['<script>alert(1)</script>'],
            'blok'        => ["<div onclick=\"x()\">\nblok\n</div>"],
            'obrázek tag' => ['<img src="https://example.com/x.png" onerror="x()">'],
            'iframe'      => ['<iframe src="https://example.com"></iframe>'],
            'komentář'    => ['<!-- komentář -->'],
            'styl'        => ['<style>body { display: none }</style>'],
        ];
    }

    #[DataProvider('htmlInput')]
    public function testHtmlInputIsEscapedNeverPassedThrough(string $markdown): void
    {
        $html = self::html($markdown);

        $this->assertDoesNotMatchRegularExpression('#<(b|script|div|img|iframe|style|!--)#i', $html);
        $this->assertStringContainsString('&lt;', $html);
    }

    public function testImageIsReplacedByItsAltText(): void
    {
        $this->assertSame('<p>Logo: naše logo</p>', self::html('Logo: ![naše logo](https://example.com/logo.png)'));
        // Ani `data:` adresa — tu by volba allow_unsafe_links propustila.
        $this->assertSame('<p>pixel</p>', self::html('![pixel](data:image/png;base64,AAAA)'));
        $this->assertStringNotContainsString('<img', self::html('![a](x.png) ![b](y.png "titulek")'));
    }

    public function testLinkIsPrintedAsTextWithAddress(): void
    {
        $this->assertSame(
            '<p>Více na webu (https://example.com/akce?a=1&amp;b=2).</p>',
            self::html('Více na [webu](https://example.com/akce?a=1&b=2).'),
        );
        // Adresa shodná s textem se neopakuje (automatický odkaz, e-mail).
        $this->assertSame('<p>Web: https://example.com</p>', self::html('Web: https://example.com'));
        $this->assertSame('<p>Web: https://example.com</p>', self::html('Web: <https://example.com>'));
        $this->assertSame('<p>Pište na info@example.com</p>', self::html('Pište na <info@example.com>'));
        $this->assertSame('<p>Pište nám (info@example.com)</p>', self::html('[Pište nám](mailto:info@example.com)'));
        $this->assertStringNotContainsString('<a', self::html('[a](https://example.com) https://example.org'));
    }

    /** @return array<string, array{string}> */
    public static function unsafeLinks(): array
    {
        return [
            'javascript' => ['[klikni](javascript:alert(1))'],
            'vbscript'   => ['[klikni](vbscript:msgbox(1))'],
            'data'       => ['[klikni](data:text/html;base64,PHNjcmlwdD4=)'],
            'file'       => ['[klikni](file:///etc/passwd)'],
        ];
    }

    #[DataProvider('unsafeLinks')]
    public function testUnsafeLinkAddressIsNotPrinted(string $markdown): void
    {
        $this->assertSame('<p>klikni</p>', self::html($markdown));
    }

    public function testTablesAndTaskListsAreNotSupported(): void
    {
        $this->assertStringNotContainsString('<table', self::html("| a | b |\n|---|---|\n| 1 | 2 |"));
        $this->assertStringNotContainsString('<input', self::html('- [x] hotovo'));
    }

    public function testEmptyTextGivesEmptyHtml(): void
    {
        $this->assertSame('', self::html(" \n "));
    }
}
