<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\ExchangeRates\Download;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\ExchangeRates\Download\CzechWorkingDayCalendar;
use Shipard\Module\Economy\ExchangeRates\Download\DownloadSchedule;
use Shipard\Module\Economy\ExchangeRates\Download\ListDownloadConfig;

/** Očekávaná data lístků (#121 task B §4.2): pracovní dny kalendáře cz, dnešek až po vyhlášení v Praze, měsíční 1. den. */
class DownloadScheduleTest extends TestCase
{
    private function daily(): ListDownloadConfig
    {
        return ListDownloadConfig::fromArray(['serviceListType' => 1, 'frequency' => 'workingDays', 'calendar' => 'cz', 'publishAfter' => '14:35', 'timezone' => 'Europe/Prague']);
    }

    private function monthly(): ListDownloadConfig
    {
        return ListDownloadConfig::fromArray(['serviceListType' => 2, 'frequency' => 'monthly']);
    }

    private static function utc(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    public function testWorkingDaysSkipWeekendsAndHolidays(): void
    {
        // Říjen 2026: 3.–4. víkend, 28. 10. svátek (středa).
        $dates = DownloadSchedule::expectedDates($this->daily(), new CzechWorkingDayCalendar(), '2026-10-01', '2026-10-31', self::utc('2026-11-05 10:00:00'));

        $this->assertSame(
            ['2026-10-01', '2026-10-02', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16', '2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-26', '2026-10-27', '2026-10-29', '2026-10-30'],
            $dates,
        );
    }

    public function testChristmasAndNewYear(): void
    {
        $dates = DownloadSchedule::expectedDates($this->daily(), new CzechWorkingDayCalendar(), '2026-12-21', '2027-01-04', self::utc('2027-02-01 10:00:00'));

        $this->assertSame(['2026-12-21', '2026-12-22', '2026-12-23', '2026-12-28', '2026-12-29', '2026-12-30', '2026-12-31', '2027-01-04'], $dates);
    }

    public function testTodayOnlyAfterPublicationInPragueEvenWhenServerRunsUtc(): void
    {
        // 20. 10. 2026 je úterý. 12:30 UTC = 14:30 v Praze → dnešek ještě ne; 12:40 UTC → ano.
        $before = DownloadSchedule::expectedDates($this->daily(), new CzechWorkingDayCalendar(), '2026-10-19', '2026-10-20', self::utc('2026-10-20 12:30:00'));
        $after = DownloadSchedule::expectedDates($this->daily(), new CzechWorkingDayCalendar(), '2026-10-19', '2026-10-20', self::utc('2026-10-20 12:40:00'));

        $this->assertSame(['2026-10-19'], $before);
        $this->assertSame(['2026-10-19', '2026-10-20'], $after);
    }

    public function testDatesAfterTodayAreNeverExpected(): void
    {
        // `to` za dneškem (ruční --to se sice omezí na dnešek, ale schedule to hlídá sám).
        $dates = DownloadSchedule::expectedDates($this->daily(), new CzechWorkingDayCalendar(), '2026-10-19', '2026-10-23', self::utc('2026-10-20 15:00:00'));
        $this->assertSame(['2026-10-19', '2026-10-20'], $dates);

        // 23:30 UTC 19. 10. je v Praze už 20. 10. 01:30 — dnešek typu je 20. 10., před vyhlášením.
        $dates = DownloadSchedule::expectedDates($this->daily(), new CzechWorkingDayCalendar(), '2026-10-19', '2026-10-23', self::utc('2026-10-19 23:30:00'));
        $this->assertSame(['2026-10-19'], $dates);
    }

    public function testWeekendTodayExpectsNothingNew(): void
    {
        // Sobota 17. 10. 2026 odpoledne: očekává se pátek, sobota ne.
        $dates = DownloadSchedule::expectedDates($this->daily(), new CzechWorkingDayCalendar(), '2026-10-16', '2026-10-17', self::utc('2026-10-17 15:00:00'));
        $this->assertSame(['2026-10-16'], $dates);
    }

    public function testMonthlyExpectsFirstDayOfEachMonthFromTheMonthOfWindowStart(): void
    {
        $dates = DownloadSchedule::expectedDates($this->monthly(), null, '2026-07-15', '2026-10-20', self::utc('2026-10-20 01:00:00'));
        $this->assertSame(['2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01'], $dates, 'začátek uprostřed měsíce se normalizuje na jeho 1. den');

        $this->assertSame(['2026-10-01'], DownloadSchedule::expectedDates($this->monthly(), null, '2026-10-01', '2026-10-01', self::utc('2026-10-01 00:00:01')), 'očekává se od 1. dne');
    }

    public function testEmptyCases(): void
    {
        $this->assertSame([], DownloadSchedule::expectedDates($this->daily(), new CzechWorkingDayCalendar(), '2026-10-20', '2026-10-19', self::utc('2026-10-20 15:00:00')), 'from > to');
        $this->assertSame([], DownloadSchedule::expectedDates($this->daily(), null, '2026-10-01', '2026-10-20', self::utc('2026-10-20 15:00:00')), 'bez kalendáře');
    }
}
