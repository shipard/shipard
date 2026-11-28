<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints\EscPos;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Prints\EscPos\CodePage;
use Shipard\Core\Prints\EscPos\CodePages;
use Shipard\Core\Prints\EscPos\Cp852;
use Shipard\Core\Prints\EscPos\EscPosConfigException;

final class Cp852Test extends TestCase
{
    private const NBSP = "\u{00A0}";

    private static function print(string $text): string
    {
        $page = new Cp852();
        return $page->encode($page->normalize($text));
    }

    public function testCzechAndSlovakLettersInBothCases(): void
    {
        $letters = [
            'á' => 0xA0, 'Á' => 0xB5, 'ä' => 0x84, 'Ä' => 0x8E, 'č' => 0x9F, 'Č' => 0xAC,
            'ď' => 0xD4, 'Ď' => 0xD2, 'é' => 0x82, 'É' => 0x90, 'ě' => 0xD8, 'Ě' => 0xB7,
            'í' => 0xA1, 'Í' => 0xD6, 'ĺ' => 0x92, 'Ĺ' => 0x91, 'ľ' => 0x96, 'Ľ' => 0x95,
            'ň' => 0xE5, 'Ň' => 0xD5, 'ó' => 0xA2, 'Ó' => 0xE0, 'ô' => 0x93, 'Ô' => 0xE2,
            'ŕ' => 0xEA, 'Ŕ' => 0xE8, 'ř' => 0xFD, 'Ř' => 0xFC, 'š' => 0xE7, 'Š' => 0xE6,
            'ť' => 0x9C, 'Ť' => 0x9B, 'ú' => 0xA3, 'Ú' => 0xE9, 'ů' => 0x85, 'Ů' => 0xDE,
            'ý' => 0xEC, 'Ý' => 0xED, 'ž' => 0xA7, 'Ž' => 0xA6,
        ];

        foreach ($letters as $letter => $byte) {
            $this->assertSame(sprintf('%02x', $byte), bin2hex(self::print($letter)), $letter);
        }
    }

    public function testPangramRoundTripsThroughTheTable(): void
    {
        $text  = 'Příliš žluťoučký kůň úpěl ďábelské ódy';
        $bytes = self::print($text);

        $this->assertSame(mb_strlen($text), strlen($bytes));
        // Kodek cp852 knihovny mbstring čte stejné bajty jako naše tabulka.
        if (in_array('CP852', mb_list_encodings(), true)) {
            $this->assertSame($text, mb_convert_encoding($bytes, 'UTF-8', 'CP852'));
        }
        $this->assertSame('P', $bytes[0]);
        $this->assertSame(0xFD, ord($bytes[1]));
    }

    public function testAsciiIsKeptAndControlCharactersNeverReachThePrinter(): void
    {
        $this->assertSame('Abc 123 ~!@#', self::print('Abc 123 ~!@#'));
        // Řídicí znak v textu by tiskárna vzala jako příkaz (ESC, GS, LF).
        $this->assertSame('ab c d', self::print("a\x1Bb\tc\nd\x7F"));
    }

    public function testNonBreakingSpaceStaysInLayoutAndPrintsAsSpace(): void
    {
        $page       = new Cp852();
        $normalized = $page->normalize('1' . self::NBSP . '210,00' . self::NBSP . 'CZK');

        $this->assertSame('1' . self::NBSP . '210,00' . self::NBSP . 'CZK', $normalized);
        $this->assertSame('1 210,00 CZK', $page->encode($normalized));
        // Úzká nezlomitelná mezera se chová stejně.
        $this->assertSame('5' . self::NBSP . '%', $page->normalize("5\u{202F}%"));
    }

    public function testCharactersOutsideThePageHaveAsciiReplacement(): void
    {
        $this->assertSame('12 EUR', self::print('12 €'));
        $this->assertSame('Prodejka - danovy', self::print('Prodejka – danovy'));
        $this->assertSame('a - b - c', self::print('a — b − c'));
        $this->assertSame('"Ahoj" \'x\'', self::print('„Ahoj“ ‚x’'));
        $this->assertSame('a...', self::print('a…'));
        $this->assertSame('IC * DIC', self::print('IC · DIC'));
        // Náhrada mění délku — sazba proto počítá až nad normalizovaným textem.
        $this->assertSame(3, mb_strlen((new Cp852())->normalize('€')));
    }

    public function testLetterOutsideThePageLosesItsDiacritics(): void
    {
        $this->assertSame('n', self::print('ñ'));
        $this->assertSame('A', self::print('Å'));
        $this->assertSame('e', self::print('ė'));
        // Znak se zápisem v jiných znacích: horní index, zlomek, slitek.
        $this->assertSame('80 g/m2', self::print('80 g/m²'));
        $this->assertSame('1/2 kg', self::print('½ kg'));
        $this->assertSame('fi', self::print('ﬁ'));
        // Rozložený zápis se nejdřív složí: ż v CP852 je, q s čárkou ne.
        $this->assertSame("\xBE", self::print("z\u{0307}"));
        $this->assertSame('q', self::print("q\u{0301}"));
    }

    public function testUnknownCharacterIsQuestionMark(): void
    {
        $this->assertSame('?', self::print('ø'));
        $this->assertSame('a?b', self::print('a漢b'));
        $this->assertSame('?', self::print('😀'));
    }

    public function testCharactersOfThePageBesidesLettersArePrinted(): void
    {
        $this->assertSame("\x9E", self::print('×'));
        $this->assertSame("\xF5", self::print('§'));
        $this->assertSame("\xF8", self::print('°'));
        $this->assertSame("\xC4", self::print('─'));
        // Měkký rozdělovník není vidět — netiskne se.
        $this->assertSame('ab', self::print("a\u{00AD}b"));
    }

    public function testInvalidUtf8DoesNotBreakTheEncoder(): void
    {
        $this->assertSame('a?b', self::print("a\xFFb"));
    }

    public function testTableMatchesThePreviewInFrontend(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 5) . '/frontend/src/native/escpos/codePages.js',
        );
        $this->assertSame(1, preg_match('/const CP852_HIGH =(.*?);\s*\/\//s', $source, $block));
        preg_match_all("/'([^']*)'/u", $block[1], $rows);
        $table = mb_str_split(implode('', $rows[1]), 1, 'UTF-8');
        $this->assertCount(128, $table);

        $page = new Cp852();
        foreach ($table as $index => $char) {
            $byte = 0x80 + $index;
            if ($char === "\u{00AD}" || $char === self::NBSP) {
                continue;
            }
            $this->assertSame(sprintf('%02x', $byte), bin2hex($page->encode($char)), "0x" . dechex($byte));
        }
    }

    public function testRegistryKnowsOnlyCp852(): void
    {
        $this->assertTrue(CodePages::has('cp852'));
        $this->assertFalse(CodePages::has('windows-1250'));
        $this->assertInstanceOf(CodePage::class, CodePages::get('cp852'));
        $this->assertSame('cp852', CodePages::get('cp852')->name());

        $this->expectException(EscPosConfigException::class);
        CodePages::get('windows-1250');
    }
}
