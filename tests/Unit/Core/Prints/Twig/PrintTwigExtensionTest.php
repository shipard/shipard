<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints\Twig;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Prints\PrintLanguageResolver;
use Shipard\Core\Prints\PrintTranslator;
use Shipard\Core\Prints\Twig\PrintTwigExtension;

/**
 * Filtry a funkce tiskových šablon — formát podle jazyka tisku. Mezery
 * uvnitř hodnot jsou nezlomitelné (U+00A0).
 */
class PrintTwigExtensionTest extends TestCase
{
    private const NBSP = "\u{00A0}";

    private function extension(string $language): PrintTwigExtension
    {
        return new PrintTwigExtension(new PrintTranslator(
            ['page' => ['cs' => 'Strana {page}', 'en' => 'Page {page}']],
            $language,
        ));
    }

    public function testMoney(): void
    {
        $cs = $this->extension('cs');
        $en = $this->extension('en');

        $this->assertSame('1' . self::NBSP . '234' . self::NBSP . '567,90', $cs->money(1234567.9));
        $this->assertSame('1' . self::NBSP . '234' . self::NBSP . '567,90', $this->extension('sk')->money(1234567.9));
        $this->assertSame('1.234.567,90', $this->extension('de')->money(1234567.9));
        $this->assertSame('1,234,567.90', $en->money(1234567.9));
        $this->assertSame('1' . self::NBSP . '234,50', $cs->money(1234.5), 'tisíce se oddělují už u čtyř cifer');
        $this->assertSame('1.234,50', $this->extension('de')->money(1234.5));
        $this->assertSame('0,00', $cs->money(0));
        $this->assertSame('0,00', $cs->money(-0.004), 'žádné -0,00');
        $this->assertSame('0.00', $en->money(-0.004));
        $this->assertSame('-1' . self::NBSP . '815,00', $cs->money(-1815.0));
        $this->assertSame('-1.815,00', $this->extension('de')->money(-1815.0));
        $this->assertSame('10,01', $cs->money(10.005), 'zaokrouhluje se od pětky nahoru, ne bankéřsky');
        $this->assertSame('10.01', $en->money(10.005));
        $this->assertSame('', $cs->money(null));
        $this->assertSame('', $cs->money('abc', 'CZK'));
    }

    public function testMoneyPrintsCurrencyCodeBehindTheNumberInEveryLanguage(): void
    {
        $this->assertSame('1' . self::NBSP . '210,00' . self::NBSP . 'EUR', $this->extension('cs')->money(1210, 'EUR'));
        $this->assertSame('1' . self::NBSP . '210,00' . self::NBSP . 'EUR', $this->extension('sk')->money(1210, 'EUR'));
        $this->assertSame('1.210,00' . self::NBSP . 'EUR', $this->extension('de')->money(1210, 'EUR'));
        $this->assertSame('1,210.00' . self::NBSP . 'EUR', $this->extension('en')->money('1210.00', 'EUR'));
    }

    public function testQty(): void
    {
        $cs = $this->extension('cs');
        $en = $this->extension('en');

        $this->assertSame('6', $cs->qty(6.0));
        $this->assertSame('1,5', $cs->qty(1.5));
        $this->assertSame('1,5', $this->extension('sk')->qty(1.5));
        $this->assertSame('1,5', $this->extension('de')->qty(1.5));
        $this->assertSame('1.5', $en->qty(1.5));
        $this->assertSame('0,125', $cs->qty('0.1250'));
        $this->assertSame('0,3333', $cs->qty(1 / 3));
        $this->assertSame('1' . self::NBSP . '000', $cs->qty(1000));
        $this->assertSame('1.000,25', $this->extension('de')->qty(1000.25));
        $this->assertSame('1,000.25', $en->qty(1000.25));
        $this->assertSame('24,5', $cs->qty(24.5));
        $this->assertSame('0', $cs->qty(-0.00001), 'žádné -0');
        $this->assertSame('', $cs->qty(null));
    }

    public function testPct(): void
    {
        $this->assertSame('21' . self::NBSP . '%', $this->extension('cs')->pct(21.0));
        $this->assertSame('21' . self::NBSP . '%', $this->extension('sk')->pct(21.0));
        $this->assertSame('21' . self::NBSP . '%', $this->extension('de')->pct(21.0));
        $this->assertSame('21%', $this->extension('en')->pct(21.0));
        $this->assertSame('12,5' . self::NBSP . '%', $this->extension('cs')->pct(12.5));
        $this->assertSame('12.5%', $this->extension('en')->pct(12.5));
        $this->assertSame('33,33' . self::NBSP . '%', $this->extension('cs')->pct(100 / 3), 'nejvýš 2 desetinná místa');
        $this->assertSame('0' . self::NBSP . '%', $this->extension('cs')->pct(0));
        $this->assertSame('', $this->extension('en')->pct(null));
    }

    public function testDate(): void
    {
        $this->assertSame('2.' . self::NBSP . '10.' . self::NBSP . '2026', $this->extension('cs')->date('2026-10-02'));
        $this->assertSame('2.' . self::NBSP . '10.' . self::NBSP . '2026', $this->extension('sk')->date('2026-10-02'));
        $this->assertSame('02.10.2026', $this->extension('de')->date('2026-10-02'));
        $this->assertSame('2' . self::NBSP . 'Oct' . self::NBSP . '2026', $this->extension('en')->date('2026-10-02'));
        $this->assertSame('31.' . self::NBSP . '12.' . self::NBSP . '2026', $this->extension('cs')->date('2026-12-31T10:30:00'));
        $this->assertSame('', $this->extension('cs')->date(null));
        $this->assertSame('', $this->extension('cs')->date('2. 10. 2026'));
        $this->assertSame('', $this->extension('cs')->date('2026-02-31'), 'datum mimo kalendář');
    }

    public function testEnglishMonthIsAlwaysThreeLetters(): void
    {
        // ICU dává pro `en_GB` v září „Sept“ — zkratky proto nejsou z ICU.
        $en = $this->extension('en');

        $months = [];
        for ($month = 1; $month <= 12; $month++) {
            $months[] = explode(self::NBSP, $en->date(sprintf('2026-%02d-15', $month)))[1];
        }
        $this->assertSame(
            ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            $months,
        );
    }

    public function testEveryPrintLanguageHasAFormat(): void
    {
        foreach (PrintLanguageResolver::LANGUAGES as $language) {
            $this->assertNotSame('', $this->extension($language)->money(1), $language);
        }
    }

    public function testLanguageWithoutFormatIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Print language 'fr' has no number and date format");
        $this->extension('fr');
    }

    public function testQrSvg(): void
    {
        $extension = $this->extension('cs');

        $svg = $extension->qrSvg(['standard' => 'spayd', 'payload' => 'SPD*1.0*ACC:CZ6508000000192000145399*AM:1.00*CC:CZK']);
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('viewBox', $svg);
        $this->assertStringNotContainsString('<?xml', $svg, 'inline SVG bez XML hlavičky');
        $this->assertStringNotContainsString('base64', $svg);

        $this->assertSame('', $extension->qrSvg(null));
        $this->assertSame('', $extension->qrSvg(['standard' => 'spayd']));
        $this->assertSame('', $extension->qrSvg(['payload' => '']));
    }

    public function testRegisteredNames(): void
    {
        $extension = $this->extension('en');

        $this->assertSame(
            ['money', 'qty', 'pct', 'date'],
            array_map(static fn ($f) => $f->getName(), $extension->getFilters()),
        );
        $functions = [];
        foreach ($extension->getFunctions() as $function) {
            $functions[$function->getName()] = $function;
        }
        $this->assertSame(['t', 'qr_svg'], array_keys($functions));
        $this->assertSame('Page 3', ($functions['t']->getCallable())('page', ['page' => 3]));
    }
}
