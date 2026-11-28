<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints\EscPos;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Prints\EscPos\ReceiptElement;
use Shipard\Core\Prints\EscPos\ReceiptMarkupException;
use Shipard\Core\Prints\EscPos\ReceiptMarkupParser;

final class ReceiptMarkupParserTest extends TestCase
{
    /** @return list<ReceiptElement> */
    private static function parse(string $body): array
    {
        return (new ReceiptMarkupParser())->parse("<receipt>\n{$body}\n</receipt>");
    }

    public function testExampleFromTheSpecification(): void
    {
        $elements = self::parse(<<<'XML'
              <text align="center" bold="1" size="2x2">Prodejka – daňový doklad</text>
              <text align="center">Firma s.r.o.</text>
              <line/>
              <row><l>Rohlík tukový</l></row>
              <row font="small"><l>  2 × 3,50 · 12 %</l><r>7,00</r></row>
              <line char="="/>
              <row bold="1" size="2x1"><l>Celkem</l><r>7,00 Kč</r></row>
              <feed lines="2"/>
              <qr>https://example.com/?a=1&amp;b=2</qr>
              <cut/>
              <drawer/>
            XML);

        $this->assertSame(
            ['text', 'text', 'line', 'row', 'row', 'line', 'row', 'feed', 'qr', 'cut', 'drawer'],
            array_map(static fn(ReceiptElement $e): string => $e->type, $elements),
        );

        [$title, $plain, $line, $item, $detail, $double, $total, $feed, $qr] = $elements;

        $this->assertSame('Prodejka – daňový doklad', $title->text);
        $this->assertSame(['center', true, 2, 2, false], [$title->align, $title->bold, $title->width, $title->height, $title->small]);
        $this->assertSame(['center', false, 1, 1, false], [$plain->align, $plain->bold, $plain->width, $plain->height, $plain->small]);
        $this->assertSame('-', $line->char);
        $this->assertSame(['Rohlík tukový', null], [$item->text, $item->right]);
        // Mezery na řádku značky jsou odsazení.
        $this->assertSame(['  2 × 3,50 · 12 %', '7,00', true], [$detail->text, $detail->right, $detail->small]);
        $this->assertSame('=', $double->char);
        $this->assertSame([true, 2, 1, 'Celkem', '7,00 Kč'], [$total->bold, $total->width, $total->height, $total->text, $total->right]);
        $this->assertSame(2, $feed->lines);
        $this->assertSame('https://example.com/?a=1&b=2', $qr->text);
        $this->assertSame(2, $title->markupLine);
        $this->assertSame(9, $feed->markupLine);
    }

    public function testWhitespaceAndCommentsBetweenElementsAreIgnored(): void
    {
        $elements = self::parse("\n\n   <text>a</text>\n\n  <!-- poznámka -->\n\t<cut/>  \n");

        $this->assertCount(2, $elements);
    }

    public function testLineBreaksOfTheTemplateInsideContentAreOneSpace(): void
    {
        $elements = self::parse(<<<'XML'
            <text>
                První řádek
                druhý řádek
            </text>
            <text>bez   změny  </text>
            <text>   </text>
            <text></text>
            <row>
                <l>
                    Položka
                </l>
                <r>
                    1,00
                </r>
            </row>
            XML);

        $this->assertSame('První řádek druhý řádek', $elements[0]->text);
        $this->assertSame('bez   změny', $elements[1]->text);
        $this->assertSame('', $elements[2]->text);
        $this->assertSame('', $elements[3]->text);
        $this->assertSame(['Položka', '1,00'], [$elements[4]->text, $elements[4]->right]);
    }

    public function testNonBreakingSpaceIsNotWhitespace(): void
    {
        $nbsp     = "\u{00A0}";
        $elements = self::parse("<text>{$nbsp}{$nbsp}1{$nbsp}210,00{$nbsp}</text><text>&#160;x</text>");

        $this->assertSame("{$nbsp}{$nbsp}1{$nbsp}210,00{$nbsp}", $elements[0]->text);
        $this->assertSame("{$nbsp}x", $elements[1]->text);
    }

    public function testEscapedValuesOfTwigAutoescapeAreRead(): void
    {
        // Twig autoescape `html`: & < > " '
        $elements = self::parse('<text>A &amp; B &lt;s.r.o.&gt; &quot;x&quot; &#039;y&#039;</text>');

        $this->assertSame('A & B <s.r.o.> "x" \'y\'', $elements[0]->text);
    }

    public function testEmptyRightPartIsNoRightPart(): void
    {
        $elements = self::parse('<row><l>a</l><r>  </r></row><feed/>');

        $this->assertNull($elements[0]->right);
        $this->assertSame(1, $elements[1]->lines);
    }

    /** @return array<string, array{string, string, int}> tělo, část hlášky, řádek */
    public static function invalidMarkup(): array
    {
        return [
            'unknown element'        => ['<image src="x"/>', 'unknown element <image>', 2],
            'unknown element line'   => ["<text>a</text>\n<line/>\n<bold>x</bold>", 'unknown element <bold>', 4],
            'unknown attribute'      => ['<text color="red">a</text>', 'unknown attribute color of <text>', 2],
            'align on row'           => ['<row align="center"><l>a</l></row>', 'unknown attribute align of <row>', 2],
            'attribute on l'         => ['<row><l bold="1">a</l></row>', 'unknown attribute bold of <l>', 2],
            'attribute on cut'       => ['<cut mode="partial"/>', 'unknown attribute mode of <cut>', 2],
            'invalid align'          => ['<text align="justify">a</text>', 'align="justify"', 2],
            'invalid bold'           => ['<text bold="true">a</text>', 'bold="true"', 2],
            'invalid size'           => ['<text size="2">a</text>', 'size="2"', 2],
            'size out of range'      => ['<text size="9x1">a</text>', 'size="9x1"', 2],
            'invalid font'           => ['<row font="tiny"><l>a</l></row>', 'font="tiny"', 2],
            'char too long'          => ['<line char="=="/>', 'exactly one character', 2],
            'char empty'             => ['<line char=""/>', 'exactly one character', 2],
            'feed zero'              => ['<feed lines="0"/>', 'lines="0"', 2],
            'feed too many'          => ['<feed lines="11"/>', 'lines="11"', 2],
            'feed not a number'      => ['<feed lines="x"/>', 'lines="x"', 2],
            'row without l'          => ['<row><r>1</r></row>', '<row> has no <l>', 2],
            'row with two l'         => ['<row><l>a</l><l>b</l></row>', 'one <l> and at most one <r>', 2],
            'row with other element' => ['<row><l>a</l><m>b</m></row>', 'found <m>', 2],
            'text in row'            => ['<row>a<l>b</l></row>', 'text outside of an element in <row>', 2],
            // libxml hlásí u volného textu řádek, kde text končí.
            'text in receipt'        => ["<line/>\nvolný text", 'text outside of an element in <receipt>', 3],
            'element in text'        => ['<text>a <b>b</b></text>', 'must contain text only, found <b>', 2],
            'content in line'        => ['<line>x</line>', '<line> must be empty', 2],
            'content in cut'         => ['<cut><feed/></cut>', '<cut> must be empty', 2],
            'empty qr'               => ['<qr>  </qr>', '<qr> has no data', 2],
        ];
    }

    #[DataProvider('invalidMarkup')]
    public function testInvalidMarkupIsAnErrorWithLineNumber(string $body, string $message, int $line): void
    {
        try {
            self::parse($body);
            $this->fail('Expected ReceiptMarkupException');
        } catch (ReceiptMarkupException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertSame($line, $e->markupLine);
            $this->assertStringStartsWith("Receipt markup line {$line}: ", $e->getMessage());
        }
    }

    /** @return array<string, array{string, string}> */
    public static function brokenDocuments(): array
    {
        return [
            'empty'           => ['', 'markup is empty'],
            'whitespace'      => ["  \n ", 'markup is empty'],
            'other root'      => ['<html><text>a</text></html>', 'root element must be <receipt>'],
            'root attribute'  => ['<receipt width="42"/>', 'unknown attribute width of <receipt>'],
            'not well formed' => ["<receipt>\n<text>a</receipt>", 'Receipt markup line 2'],
            'unescaped value' => ['<receipt><text>A & B</text></receipt>', 'Receipt markup line 1'],
            'doctype'         => ['<!DOCTYPE receipt [<!ENTITY x "y">]><receipt><text>&x;</text></receipt>', 'DOCTYPE is not allowed'],
        ];
    }

    #[DataProvider('brokenDocuments')]
    public function testBrokenDocumentIsRejected(string $markup, string $message): void
    {
        $this->expectException(ReceiptMarkupException::class);
        $this->expectExceptionMessage($message);

        (new ReceiptMarkupParser())->parse($markup);
    }

    public function testParserLeavesLibxmlErrorHandlingAsItWas(): void
    {
        $before = libxml_use_internal_errors(false);
        try {
            (new ReceiptMarkupParser())->parse('<receipt><text>a</receipt>');
        } catch (ReceiptMarkupException) {
        }
        $this->assertFalse(libxml_use_internal_errors($before));
        $this->assertFalse(libxml_get_last_error());
    }
}
