<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints\EscPos;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Prints\EscPos\PrinterProfile;
use Shipard\Core\Prints\EscPos\PrinterProfiles;
use Shipard\Core\Prints\EscPos\PrinterTestReceipt;
use Shipard\Core\Prints\EscPos\ReceiptMarkupParser;

final class PrinterTestReceiptTest extends TestCase
{
    use EscPosConfig;

    private static function receipt(string $language = 'cs'): PrinterTestReceipt
    {
        return new PrinterTestReceipt(
            self::drivers(),
            self::printsJsonc('printerTestTexts.jsonc', $language),
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-28 10:15:42'),
        );
    }

    private static function catalogProfile(string $id): PrinterProfile
    {
        return (new PrinterProfiles(self::printsJsonc('printerProfiles.jsonc')))->get($id)['profile'];
    }

    /** @return list<string> texty řádků náhledu */
    private static function lines(PrinterTestReceipt $receipt, PrinterProfile $profile): array
    {
        return array_column($receipt->render($profile)->preview['lines'], 'text');
    }

    public function testReceiptShowsParametersOfTheProfile(): void
    {
        $lines = self::lines(self::receipt(), self::catalogProfile('escpos-80-cp852'));

        $this->assertSame('ZKUŠEBNÍ ÚČTENKA', $lines[0]);
        $this->assertSame('ESC/POS 80 mm, CP852', $lines[1]);
        $this->assertContains('Profil' . str_repeat(' ', 42 - 6 - 15) . 'escpos-80-cp852', $lines);
        $this->assertContains('Znaků na řádek' . str_repeat(' ', 42 - 14 - 7) . '42 / 56', $lines);
        $this->assertContains('Bodů na řádek' . str_repeat(' ', 42 - 13 - 3) . '512', $lines);
        $this->assertContains('Kódová stránka' . str_repeat(' ', 42 - 14 - 5) . 'cp852', $lines);
        $this->assertContains('Funkce' . str_repeat(' ', 42 - 6 - 11) . 'cut, drawer', $lines);
        $this->assertContains('2026-11-28 10:15:42', $lines);
    }

    public function testRulersFillTheLineInBothFonts(): void
    {
        foreach ([['escpos-80-cp852', 42, 56], ['escpos-58-cp852', 32, 42]] as [$id, $columns, $small]) {
            $preview = self::receipt()->render(self::catalogProfile($id))->preview;
            $rulers  = array_values(array_filter(
                $preview['lines'],
                static fn (array $line): bool => str_starts_with($line['text'], '1234567890'),
            ));

            $this->assertCount(2, $rulers, $id);
            $this->assertSame(['normal', $columns], [$rulers[0]['font'], strlen($rulers[0]['text'])], $id);
            $this->assertSame(['small', $small], [$rulers[1]['font'], strlen($rulers[1]['text'])], $id);
            // Poslední číslice říká šířku: 42 končí dvojkou.
            $this->assertSame((string) ($columns % 10), substr($rulers[0]['text'], -1), $id);
        }
    }

    public function testPangramIsPrintedInAllStyles(): void
    {
        $preview = self::receipt()->render(self::catalogProfile('escpos-80-cp852'))->preview;
        $pangram = PrinterTestReceipt::PANGRAM;
        $styles  = [];
        foreach ($preview['lines'] as $line) {
            if ($line['text'] === $pangram || $line['text'] === mb_strtoupper($pangram)) {
                $styles[] = [$line['text'] === $pangram ? 'lower' : 'upper', $line['font'], $line['bold'], $line['size']['w']];
            }
        }

        $this->assertSame([
            ['lower', 'normal', false, 1],
            ['upper', 'normal', false, 1],
            ['lower', 'small', false, 1],
            ['upper', 'small', false, 1],
            ['lower', 'normal', true, 1],
        ], $styles);

        // Dvojitá velikost se na řádek nevejde celá — zalomí se na 21 znaků.
        $double = array_values(array_filter($preview['lines'], static fn (array $l): bool => $l['size'] === ['w' => 2, 'h' => 2]));
        $this->assertGreaterThanOrEqual(2, count($double));
        $this->assertSame($pangram, implode(' ', array_column(array_slice($double, 1), 'text')));
    }

    public function testReceiptEndsWithFeedAndCut(): void
    {
        $output = self::receipt()->render(self::catalogProfile('escpos-58-cp852'));

        $this->assertStringEndsWith("\x1Bd\x02\x1DVA\x05", $output->bytes);
        $this->assertSame('cut', $output->preview['marks'][0]['kind']);
        $this->assertSame(count($output->preview['lines']), $output->preview['marks'][0]['at']);
        $this->assertSame([], $output->warnings);
    }

    public function testProfileWithoutFeaturesSaysSo(): void
    {
        $lines = self::lines(self::receipt(), self::profile(['features' => []]));

        $this->assertContains('Funkce' . str_repeat(' ', 42 - 6 - 5) . 'žádné', $lines);
    }

    public function testMarkupIsValidForTheParser(): void
    {
        $profile = self::profile(['name' => 'A & B <80 mm> "x"']);
        $markup  = self::receipt()->markup($profile);

        $elements = (new ReceiptMarkupParser())->parse($markup);
        $this->assertSame('A & B <80 mm> "x"', $elements[1]->text);
        $this->assertSame('cut', end($elements)->type);
    }

    public function testEnglishFallbackMatchesTheCatalog(): void
    {
        // Bez katalogu (zdroj dat před ds-upgrade) stejné texty jako anglická varianta.
        $withCatalog = self::receipt('en')->markup(self::profile(['features' => []]));
        $without     = (new PrinterTestReceipt(
            self::drivers(),
            null,
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-28 10:15:42'),
        ))->markup(self::profile(['features' => []]));

        $this->assertSame($withCatalog, $without);
        $this->assertStringContainsString('TEST RECEIPT', $without);

        // Holé `name` katalogu je anglická varianta.
        foreach (self::printsJsonc('printerTestTexts.jsonc', 'en') as $key => $entry) {
            $this->assertSame($entry['name'], self::printsJsonc('printerTestTexts.jsonc', 'xx')[$key]['name'], $key);
        }
    }

    public function testFactoryReadsDriversAndTextsFromConfig(): void
    {
        $items = [
            'core.prints.printerDrivers'   => self::printsJsonc('printerDrivers.jsonc'),
            PrinterTestReceipt::CFG_ITEM   => self::printsJsonc('printerTestTexts.jsonc'),
        ];
        $config = $this->createStub(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $items[$id] ?? null);

        $output = PrinterTestReceipt::fromConfig($config)->render(self::catalogProfile('escpos-80-cp852'));

        $this->assertSame('ZKUŠEBNÍ ÚČTENKA', $output->preview['lines'][0]['text']);
        $this->assertStringStartsWith("\x1B@\x1C.\x1Bt\x12", $output->bytes);
    }
}
