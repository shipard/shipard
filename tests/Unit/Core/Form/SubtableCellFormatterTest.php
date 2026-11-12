<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\SubtableCellFormatter;

class SubtableCellFormatterTest extends TestCase
{
    public function testMoneyUsesCzechSeparatorsAndTwoDecimals(): void
    {
        $this->assertSame('1 000,00', SubtableCellFormatter::money(1000));
        $this->assertSame('1 234 567,89', SubtableCellFormatter::money('1234567.891'));
        $this->assertSame('-12,50', SubtableCellFormatter::money(-12.5));
        $this->assertSame('0,00', SubtableCellFormatter::money(0));
    }

    public function testEmptyInputIsEmptyCellNotZero(): void
    {
        $this->assertNull(SubtableCellFormatter::money(null));
        $this->assertNull(SubtableCellFormatter::money(''));
        $this->assertNull(SubtableCellFormatter::number(null, 4));
        $this->assertNull(SubtableCellFormatter::trimmedNumber('', 4));
        $this->assertNull(SubtableCellFormatter::date(null, 'cs'));
        $this->assertNull(SubtableCellFormatter::dateTime('', 'en'));
    }

    public function testNumberKeepsRequestedScale(): void
    {
        $this->assertSame('2,0000', SubtableCellFormatter::number('2.0000', 4));
        $this->assertSame('21', SubtableCellFormatter::number(21, 0));
    }

    public function testTrimmedNumberDropsTrailingZeros(): void
    {
        $this->assertSame('10', SubtableCellFormatter::trimmedNumber('10.0000', 4));
        $this->assertSame('2,5', SubtableCellFormatter::trimmedNumber('2.5000', 4));
        $this->assertSame('21', SubtableCellFormatter::trimmedNumber('21.00', 2));
        $this->assertSame('1 000', SubtableCellFormatter::trimmedNumber(1000, 2));
    }

    public function testPriceKeepsSignificantDecimalsWithMinimumTwo(): void
    {
        $this->assertSame('1 000,00', SubtableCellFormatter::price('1000.0000'));
        $this->assertSame('12,3456', SubtableCellFormatter::price('12.3456'));
        $this->assertSame('12,345', SubtableCellFormatter::price('12.3450'));
        $this->assertSame('0,50', SubtableCellFormatter::price(0.5));
        $this->assertSame('7', SubtableCellFormatter::price(7, maxDecimals: 2, minDecimals: 0));
        $this->assertNull(SubtableCellFormatter::price(null));
    }

    public function testDateFormats(): void
    {
        // Zápis podle jazyka požadavku tvoří DisplayDate (#133 D73).
        $this->assertSame('05.03.2026', SubtableCellFormatter::date('2026-03-05', 'cs'));
        $this->assertSame('05.03.2026', SubtableCellFormatter::date('2026-03-05 00:00:00', 'cs'));
        $this->assertSame('05.03.2026', SubtableCellFormatter::date(new \DateTimeImmutable('2026-03-05'), 'cs'));
        $this->assertSame('05.03.2026 14:07', SubtableCellFormatter::dateTime('2026-03-05T14:07:33', 'cs'));
        $this->assertSame('2026-03-05', SubtableCellFormatter::date('2026-03-05', 'en'));
        $this->assertSame('2026-03-05 14:07', SubtableCellFormatter::dateTime('2026-03-05T14:07:33', 'en'));
    }

    public function testUnparsableDateIsReturnedVerbatim(): void
    {
        $this->assertSame('not-a-date', SubtableCellFormatter::date('not-a-date', 'cs'));
    }

    public function testBooleanLabels(): void
    {
        $this->assertSame('Ano', SubtableCellFormatter::boolean(1, 'Ano', 'Ne'));
        $this->assertSame('Ano', SubtableCellFormatter::boolean('1', 'Ano', 'Ne'));
        $this->assertSame('Ano', SubtableCellFormatter::boolean(true, 'Ano', 'Ne'));
        $this->assertSame('Ne', SubtableCellFormatter::boolean(0, 'Ano', 'Ne'));
        $this->assertSame('Ne', SubtableCellFormatter::boolean('0', 'Ano', 'Ne'));
        $this->assertSame('Ne', SubtableCellFormatter::boolean(false, 'Ano', 'Ne'));
        $this->assertSame('Ne', SubtableCellFormatter::boolean(null, 'Ano', 'Ne'));
    }
}
