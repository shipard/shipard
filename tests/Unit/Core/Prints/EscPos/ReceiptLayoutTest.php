<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints\EscPos;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Prints\EscPos\Cp852;
use Shipard\Core\Prints\EscPos\PrinterProfile;
use Shipard\Core\Prints\EscPos\ReceiptCommand;
use Shipard\Core\Prints\EscPos\ReceiptLayout;
use Shipard\Core\Prints\EscPos\ReceiptLine;
use Shipard\Core\Prints\EscPos\ReceiptMarkupParser;

final class ReceiptLayoutTest extends TestCase
{
    use EscPosConfig;

    private const NBSP = "\u{00A0}";

    /** @return list<ReceiptLine|ReceiptCommand> */
    private static function layout(string $body, ?PrinterProfile $profile = null): array
    {
        return (new ReceiptLayout($profile ?? self::profile(), new Cp852()))
            ->layout((new ReceiptMarkupParser())->parse("<receipt>{$body}</receipt>"));
    }

    /** @return list<string> */
    private static function texts(string $body, ?PrinterProfile $profile = null): array
    {
        return array_map(
            static fn(ReceiptLine|ReceiptCommand $item): string => $item instanceof ReceiptLine ? $item->text : "[{$item->type}]",
            self::layout($body, $profile),
        );
    }

    public function testTextWrapsAtWords(): void
    {
        $this->assertSame(['aaa bbb', 'ccc ddd', 'eee'], ReceiptLayout::wrap('aaa bbb ccc ddd eee', 7));
        $this->assertSame(['aaa bbb'], ReceiptLayout::wrap('aaa bbb', 7));
        $this->assertSame(['aaa', 'bbbb'], ReceiptLayout::wrap('aaa bbbb', 7));
        $this->assertSame(['aaa bbb'], ReceiptLayout::wrap('aaa    bbb', 7));
        $this->assertSame([''], ReceiptLayout::wrap('', 7));
        $this->assertSame([''], ReceiptLayout::wrap('    ', 7));
    }

    public function testWordLongerThanLineIsSplit(): void
    {
        $this->assertSame(['abcdefg', 'hij'], ReceiptLayout::wrap('abcdefghij', 7));
        $this->assertSame(['aa', 'abcdefg', 'hij kk'], ReceiptLayout::wrap('aa abcdefghij kk', 7));
        $this->assertSame(['abcdefg', 'abcdefg'], ReceiptLayout::wrap('abcdefgabcdefg', 7));
        $this->assertSame(['a', 'b', 'c'], ReceiptLayout::wrap('abc', 0));
    }

    public function testIndentStaysOnTheFirstLineOnly(): void
    {
        $this->assertSame(['  aa bb', 'cc'], ReceiptLayout::wrap('  aa bb cc', 7));
        // Odsazení ustoupí slovu, které se na řádek vejde jen bez něj.
        $this->assertSame(['abcdef'], ReceiptLayout::wrap('  abcdef', 7));
        $this->assertSame(['  abcde', 'fgh'], ReceiptLayout::wrap('  abcdefgh', 7));
        $this->assertSame(['aa'], ReceiptLayout::wrap('          aa', 7));
    }

    public function testNonBreakingSpaceKeepsTextTogether(): void
    {
        $amount = '1' . self::NBSP . '210,00' . self::NBSP . 'CZK';

        $this->assertSame(['Celkem', $amount], ReceiptLayout::wrap('Celkem ' . $amount, 14));
        $this->assertSame(['Celkem ' . $amount], ReceiptLayout::wrap('Celkem ' . $amount, 19));
    }

    public function testWidthCountsCharactersNotBytes(): void
    {
        $this->assertSame(['žluťoučký kůň'], ReceiptLayout::wrap('žluťoučký kůň', 13));
        $this->assertSame(['žluťoučký', 'kůň'], ReceiptLayout::wrap('žluťoučký kůň', 12));
    }

    public function testRowPutsRightTextOnTheLastLineOfLeftText(): void
    {
        $this->assertSame(['Rohlik    7,00'], ReceiptLayout::row('Rohlik', '7,00', 14));
        $this->assertSame(['Rohlik'], ReceiptLayout::row('Rohlik', '', 14));
        $this->assertSame(['Rohlik tukovy', 'velky     7,00'], ReceiptLayout::row('Rohlik tukovy velky', '7,00', 14));
        // Přesně na šířku: jedna mezera mezi částmi.
        $this->assertSame(['Rohlikovy 7,00'], ReceiptLayout::row('Rohlikovy', '7,00', 14));
    }

    public function testRowMovesRightTextToItsOwnLineWhenItDoesNotFit(): void
    {
        $this->assertSame(['Rohlikovyy', '          7,00'], ReceiptLayout::row('Rohlikovyy', '7,00', 14));
        $this->assertSame(
            ['Rohlik tukovy', 'velky cely', '          7,00'],
            ReceiptLayout::row('Rohlik tukovy velky cely', '7,00', 14),
        );
    }

    public function testRowWithLongRightText(): void
    {
        $this->assertSame(['a', '12345678901234', '           567'], ReceiptLayout::row('a', '12345678901234567', 14));
        $this->assertSame(['12345678901234'], ReceiptLayout::row('', '12345678901234', 14));
        $this->assertSame(['          7,00'], ReceiptLayout::row('', '7,00', 14));
        $this->assertSame(['          7,00'], ReceiptLayout::row('   ', '7,00', 14));
    }

    public function testRowKeepsIndentOfLeftText(): void
    {
        $this->assertSame(['  2 x 3,50   7,00'], ReceiptLayout::row('  2 x 3,50', '7,00', 17));
    }

    public function testWidthFollowsFontAndCharacterSize(): void
    {
        $word = str_repeat('x', 100);

        $this->assertSame(42, mb_strlen(self::texts("<text>{$word}</text>")[0]));
        $this->assertSame(56, mb_strlen(self::texts("<text font=\"small\">{$word}</text>")[0]));
        $this->assertSame(21, mb_strlen(self::texts("<text size=\"2x1\">{$word}</text>")[0]));
        // Výška znaku šířku řádku nemění.
        $this->assertSame(42, mb_strlen(self::texts("<text size=\"1x2\">{$word}</text>")[0]));
        $this->assertSame(28, mb_strlen(self::texts("<text font=\"small\" size=\"2x2\">{$word}</text>")[0]));
        $this->assertSame(14, mb_strlen(self::texts("<row size=\"3x1\"><l>{$word}</l></row>")[0]));

        $narrow = self::profile(['columns' => 32, 'columnsSmall' => 42]);
        $this->assertSame(32, mb_strlen(self::texts("<text>{$word}</text>", $narrow)[0]));
        $this->assertSame(42, mb_strlen(self::texts("<row font=\"small\"><l>{$word}</l></row>", $narrow)[0]));
    }

    public function testRowFillsTheLineOfItsFont(): void
    {
        $this->assertSame(
            ['Celkem' . str_repeat(' ', 30) . '7,00 x'],
            self::texts('<row><l>Celkem</l><r>7,00 x</r></row>'),
        );
        $this->assertSame(
            ['Celkem' . str_repeat(' ', 9) . '7,00 x'],
            self::texts('<row size="2x1"><l>Celkem</l><r>7,00 x</r></row>'),
        );
    }

    public function testLineFillsTheWidthInNormalFont(): void
    {
        $this->assertSame([str_repeat('-', 42)], self::texts('<line/>'));
        $this->assertSame([str_repeat('=', 42)], self::texts('<line char="="/>'));
        $this->assertSame([str_repeat('─', 32)], self::texts('<line char="─"/>', self::profile(['columns' => 32, 'columnsSmall' => 42])));
        // Znak mimo stránku se nahradí jako v textu.
        $this->assertSame([str_repeat('-', 42)], self::texts('<line char="–"/>'));
    }

    public function testStyleBelongsToAllLinesOfTheElement(): void
    {
        $items = self::layout('<text align="center" bold="1" size="2x2">' . str_repeat('slovo ', 8) . '</text><text>x</text>');

        $this->assertCount(4, $items);
        foreach (array_slice($items, 0, 3) as $line) {
            $this->assertInstanceOf(ReceiptLine::class, $line);
            $this->assertSame(['center', true, 2, 2, false], [$line->align, $line->bold, $line->width, $line->height, $line->small]);
        }
        $this->assertSame(['left', false, 1, 1, false], [$items[3]->align, $items[3]->bold, $items[3]->width, $items[3]->height, $items[3]->small]);
    }

    public function testTextIsNormalizedBeforeMeasuring(): void
    {
        // € → EUR: tři sloupce místo jednoho.
        $this->assertSame(['Cena' . str_repeat(' ', 32) . '12 EUR'], self::texts('<row><l>Cena</l><r>12 €</r></row>'));
        $this->assertSame(['Prodejka - danovy doklad'], self::texts('<text>Prodejka – danovy doklad</text>'));
    }

    public function testEmptyTextIsAnEmptyLine(): void
    {
        $this->assertSame(['', 'a'], self::texts('<text></text><text>a</text>'));
    }

    public function testCommandsFollowFeaturesOfTheProfile(): void
    {
        $body = '<feed lines="2"/><qr>https://example.com/abc</qr><cut/><drawer/>';

        $all = self::layout($body, self::profile(['features' => ['cut', 'drawer', 'qr']]));
        $this->assertSame(['feed', 'qr', 'cut', 'drawer'], array_map(static fn($i) => $i->type, $all));
        $this->assertSame(2, $all[0]->lines);
        $this->assertSame('https://example.com/abc', $all[1]->data);

        // Bez funkcí: QR jako text, ořez jako posun, šuplík nic.
        $none = self::layout($body, self::profile(['features' => []]));
        $this->assertCount(3, $none);
        $this->assertSame('feed', $none[0]->type);
        $this->assertInstanceOf(ReceiptLine::class, $none[1]);
        $this->assertSame('https://example.com/abc', $none[1]->text);
        $this->assertSame(['feed', ReceiptLayout::FEED_INSTEAD_OF_CUT], [$none[2]->type, $none[2]->lines]);
    }

    public function testLongQrContentPrintedAsTextIsSplit(): void
    {
        $texts = self::texts('<qr>' . str_repeat('a', 50) . '</qr>', self::profile(['features' => []]));

        $this->assertSame([str_repeat('a', 42), str_repeat('a', 8)], $texts);
    }
}
