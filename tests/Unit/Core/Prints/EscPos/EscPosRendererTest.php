<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints\EscPos;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Prints\EscPos\EscPosConfigException;
use Shipard\Core\Prints\EscPos\EscPosDrivers;
use Shipard\Core\Prints\EscPos\EscPosOutput;
use Shipard\Core\Prints\EscPos\EscPosRenderer;
use Shipard\Core\Prints\EscPos\PrinterProfile;
use Shipard\Core\Prints\EscPos\ReceiptMarkupException;
use Shipard\Core\Prints\PrintMessage;

final class EscPosRendererTest extends TestCase
{
    use EscPosConfig;

    private const INIT = "\x1B@\x1C.\x1Bt\x12";

    private static function render(string $body, ?PrinterProfile $profile = null): EscPosOutput
    {
        return (new EscPosRenderer(self::drivers()))
            ->render("<receipt>{$body}</receipt>", $profile ?? self::profile());
    }

    public function testEveryReceiptStartsWithInitAndCodePage(): void
    {
        $this->assertSame(self::INIT, self::render('')->bytes);
        $this->assertSame(self::INIT . "Ahoj\n", self::render('<text>Ahoj</text>')->bytes);
    }

    public function testTextIsEncodedInCp852(): void
    {
        $this->assertSame(
            self::INIT . "P\xFD\xA1li\xE7 \xA7lu\x9Cou\x9Fk\xEC k\x85\xE5\n",
            self::render('<text>Příliš žluťoučký kůň</text>')->bytes,
        );
        // Nezlomitelná mezera z formátovacích filtrů je na papíře mezera.
        $this->assertSame(
            self::INIT . "1 210,00 CZK\n",
            self::render("<text>1\u{00A0}210,00\u{00A0}CZK</text>")->bytes,
        );
    }

    public function testStyleIsSentOnChangeAndReturnsToDefaultAfterTheElement(): void
    {
        $bytes = self::render(
            '<text align="center" bold="1" size="2x2">A</text><text>b</text><text>c</text>',
        )->bytes;

        $this->assertSame(
            self::INIT
            . "\x1BE\x01" . "\x1D!\x11" . "\x1Ba\x01" . "A\n"
            . "\x1BE\x00" . "\x1D!\x00" . "\x1Ba\x00" . "b\n"
            . "c\n",
            $bytes,
        );
    }

    public function testStyleIsNotRepeatedBetweenLinesOfOneElement(): void
    {
        $bytes = self::render('<text bold="1">' . str_repeat('a', 42) . ' b</text>')->bytes;

        $this->assertSame(
            self::INIT . "\x1BE\x01" . str_repeat('a', 42) . "\nb\n" . "\x1BE\x00",
            $bytes,
        );
    }

    public function testFontCommandGoesFirstBecauseItResetsBoldAndSizeOnThePrinter(): void
    {
        // ESC ! n nastavuje na tiskárně i tučné a velikost: po něm se musí
        // tučné a velikost poslat znovu, i když se mezi prvky nezměnily.
        $bytes = self::render(
            '<text bold="1" size="2x1">A</text><text bold="1" size="2x1" font="small">B</text><text bold="1">C</text>',
        )->bytes;

        $this->assertSame(
            self::INIT
            . "\x1BE\x01" . "\x1D!\x10" . "A\n"
            . "\x1B!\x01" . "\x1BE\x01" . "\x1D!\x10" . "B\n"
            . "\x1B!\x00" . "\x1BE\x01" . "C\n"
            . "\x1BE\x00",
            $bytes,
        );
    }

    public function testSmallFontAloneNeedsNoOtherCommands(): void
    {
        $this->assertSame(
            self::INIT . "\x1B!\x01" . "a\n" . "\x1B!\x00" . "b\n",
            self::render('<row font="small"><l>a</l></row><text>b</text>')->bytes,
        );
    }

    public function testCommandsRunInDefaultStyle(): void
    {
        $bytes = self::render('<text bold="1">A</text><feed lines="2"/><cut/><drawer/>')->bytes;

        $this->assertSame(
            self::INIT . "\x1BE\x01" . "A\n" . "\x1BE\x00" . "\x1Bd\x02" . "\x1DVA\x05" . "\x1Bp\x30\x37\x79",
            $bytes,
        );
    }

    public function testQrCodeIsCenteredCommandOrTextWithWarning(): void
    {
        $withQr = self::render('<qr>https://example.com/</qr>', self::profile(['features' => ['qr']]));
        $this->assertSame(
            self::INIT . "\x1Ba\x01" . self::driver()->qr('https://example.com/') . "\x1Ba\x00",
            $withQr->bytes,
        );
        $this->assertSame([], $withQr->warnings);

        $asText = self::render('<qr>https://example.com/</qr><qr>x</qr>');
        $this->assertSame(self::INIT . "https://example.com/\nx\n", $asText->bytes);
        $this->assertCount(1, $asText->warnings);
        $this->assertInstanceOf(PrintMessage::class, $asText->warnings[0]);
        $this->assertSame(EscPosRenderer::WARNING_QR_AS_TEXT, $asText->warnings[0]->code);
    }

    public function testQrDataAreSentInUtf8(): void
    {
        $bytes = self::render('<qr>Žluťoučký</qr>', self::profile(['features' => ['qr']]))->bytes;

        $this->assertStringContainsString('1P0Žluťoučký', $bytes);
    }

    public function testCutWithoutFeatureFeedsPaperAndDrawerDoesNothing(): void
    {
        $this->assertSame(
            self::INIT . "a\n" . "\x1Bd\x03",
            self::render('<text>a</text><cut/><drawer/>', self::profile(['features' => []]))->bytes,
        );
    }

    public function testPreviewHasTheShapeOfTheFrontendDecoder(): void
    {
        $preview = self::render(
            '<text align="center" bold="1" size="2x1">A</text>'
            . "<row font=\"small\"><l>b</l><r>1\u{00A0}000</r></row>"
            . '<feed lines="2"/><qr>https://example.com/</qr><cut/><drawer/>',
            self::profile(['features' => ['cut', 'drawer', 'qr']]),
        )->preview;

        $plain = ['text' => '', 'align' => 'left', 'bold' => false, 'size' => ['w' => 1, 'h' => 1], 'font' => 'normal'];
        $this->assertSame([
            ['text' => 'A', 'align' => 'center', 'bold' => true, 'size' => ['w' => 2, 'h' => 1], 'font' => 'normal'],
            ['text' => 'b' . str_repeat(' ', 50) . '1 000', 'align' => 'left', 'bold' => false, 'size' => ['w' => 1, 'h' => 1], 'font' => 'small'],
            $plain,
            $plain,
        ], $preview['lines']);
        $this->assertSame([
            ['at' => 4, 'kind' => 'qr', 'data' => ['content' => 'https://example.com/']],
            ['at' => 4, 'kind' => 'cut', 'data' => null],
            ['at' => 4, 'kind' => 'drawer', 'data' => null],
        ], $preview['marks']);
    }

    public function testNoLineIsLongerThanTheWidthOfItsFont(): void
    {
        $profile = self::profile(['columns' => 32, 'columnsSmall' => 42]);
        $preview = self::render(
            '<text>' . str_repeat('slovo ', 30) . '</text>'
            . '<row font="small"><l>' . str_repeat('polozka ', 12) . '</l><r>123,00</r></row>'
            . '<row size="2x1"><l>Celkem</l><r>1 234 567,00 CZK</r></row><line/>',
            $profile,
        )->preview;

        foreach ($preview['lines'] as $line) {
            $limit = intdiv($line['font'] === 'small' ? 42 : 32, $line['size']['w']);
            $this->assertLessThanOrEqual($limit, mb_strlen($line['text']), $line['text']);
        }
    }

    public function testInvalidMarkupAndMissingDriverAreErrors(): void
    {
        try {
            self::render('<image/>');
            $this->fail('Expected ReceiptMarkupException');
        } catch (ReceiptMarkupException $e) {
            $this->assertStringContainsString('unknown element <image>', $e->getMessage());
        }

        $this->expectException(EscPosConfigException::class);
        (new EscPosRenderer(new EscPosDrivers([])))->render('<receipt/>', self::profile());
    }

    public function testWarningsCanBePrepended(): void
    {
        $output = self::render('<qr>x</qr>')->withWarnings([PrintMessage::warning('FIRST', 'first')]);

        $this->assertSame(['FIRST', EscPosRenderer::WARNING_QR_AS_TEXT], array_map(
            static fn(PrintMessage $m): string => $m->code,
            $output->warnings,
        ));
    }
}
