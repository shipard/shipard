<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets\Depreciation;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Assets\Depreciation\Months;
use Shipard\Module\Economy\Assets\Depreciation\Period;
use Shipard\Module\Economy\Assets\Depreciation\PeriodCalendar;

class PeriodCalendarTest extends TestCase
{
    private const YEARS = [
        ['id' => 12, 'date_begin' => '2023-04-01', 'date_end' => '2024-03-31'],
        ['id' => 11, 'date_begin' => '2022-04-01', 'date_end' => '2023-03-31'],
    ];

    public function testYearOfReturnsFoundedFiscalYear(): void
    {
        $calendar = PeriodCalendar::yearly(self::YEARS);

        $this->assertSame(['id' => 11, 'begin' => '2022-04-01', 'end' => '2023-03-31'], $calendar->yearOf('2022-04-01')->toArray());
        $this->assertSame(11, $calendar->yearOf('2023-03-31')->id);
        $this->assertSame(12, $calendar->yearOf('2023-04-01')->id);
        $this->assertSame(12, $calendar->periodOf('2023-12-24')->id);
        $this->assertFalse($calendar->isMonthly());
    }

    public function testYearsAreExtrapolatedForwardByTwelveMonths(): void
    {
        $calendar = PeriodCalendar::yearly(self::YEARS);

        $this->assertSame(['id' => null, 'begin' => '2024-04-01', 'end' => '2025-03-31'], $calendar->yearOf('2024-04-01')->toArray());
        $this->assertSame(['id' => null, 'begin' => '2030-04-01', 'end' => '2031-03-31'], $calendar->yearOf('2031-01-15')->toArray());
    }

    public function testYearsAreExtrapolatedBackwardForHistoryBeforeFirstYear(): void
    {
        $calendar = PeriodCalendar::yearly(self::YEARS);

        $this->assertSame(['id' => null, 'begin' => '2021-04-01', 'end' => '2022-03-31'], $calendar->yearOf('2022-03-31')->toArray());
        $this->assertSame(['id' => null, 'begin' => '1992-04-01', 'end' => '1993-03-31'], $calendar->yearOf('1992-06-30')->toArray());
    }

    public function testExtrapolationKeepsShapeOfNearestFoundedYear(): void
    {
        // Přechod z kalendářního na hospodářský rok přes zkrácené období.
        $calendar = PeriodCalendar::yearly([
            new Period(1, '2022-01-01', '2022-12-31'),
            new Period(2, '2023-01-01', '2023-06-30'),
            new Period(3, '2023-07-01', '2024-06-30'),
        ]);

        $this->assertSame('2021-01-01', $calendar->yearOf('2021-08-01')->begin);
        $this->assertSame(2, $calendar->yearOf('2023-06-30')->id);
        $this->assertSame(['id' => null, 'begin' => '2024-07-01', 'end' => '2025-06-30'], $calendar->yearOf('2024-12-01')->toArray());
    }

    public function testWithoutFoundedYearsCalendarYearsApply(): void
    {
        $calendar = PeriodCalendar::yearly([]);

        $this->assertSame(['id' => null, 'begin' => '2022-01-01', 'end' => '2022-12-31'], $calendar->yearOf('2022-07-04')->toArray());
    }

    public function testMonthlyCalendarGivesMonthsWithKnownIds(): void
    {
        $calendar = PeriodCalendar::monthly(self::YEARS, [
            ['id' => 501, 'date_begin' => '2023-02-01', 'date_end' => '2023-02-28'],
        ]);

        $this->assertTrue($calendar->isMonthly());
        $this->assertSame(['id' => 501, 'begin' => '2023-02-01', 'end' => '2023-02-28'], $calendar->periodOf('2023-02-14')->toArray());
        $this->assertSame(['id' => null, 'begin' => '2024-02-01', 'end' => '2024-02-29'], $calendar->periodOf('2024-02-14')->toArray());
        // Rok zůstává účetní rok, i když období jsou měsíce.
        $this->assertSame(11, $calendar->yearOf('2023-02-14')->id);
        $this->assertSame('2023-02-28', $calendar->periodOfMonth(Months::of('2023-02-01'))->end);
        $this->assertSame(12, $calendar->yearOfMonth(Months::of('2023-04-10'))->id);
    }

    public function testMonthsHelper(): void
    {
        $december = Months::of('2022-12-10');
        $this->assertSame('2022-12-01', Months::firstDay($december));
        $this->assertSame('2022-12-31', Months::lastDay($december));
        $this->assertSame('2023-01-01', Months::firstDay($december + 1));
        $this->assertSame('2024-02-29', Months::lastDay(Months::of('2024-02-01')));
        $this->assertSame(14, Months::of('2024-02-01') - $december);
    }

    public function testPeriodRejectsInvalidDates(): void
    {
        $period = new Period(null, '2022-04-01', '2023-03-31');
        $this->assertTrue($period->contains('2023-03-31'));
        $this->assertFalse($period->contains('2023-04-01'));
        $this->assertSame(11, $period->endMonth() - $period->beginMonth());

        $this->expectException(\InvalidArgumentException::class);
        new Period(null, '2023-03-31', '2022-04-01');
    }
}
