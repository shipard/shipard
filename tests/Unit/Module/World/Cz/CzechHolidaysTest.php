<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\World\Cz;

use PHPUnit\Framework\TestCase;
use Shipard\Module\World\Cz\Calendar\CzechHolidays;

/** Kalendář svátků ČR (#120 D17): Velikonoce, Velký pátek od 2016, pevné svátky, pracovní den. */
final class CzechHolidaysTest extends TestCase
{
    public function testEasterSunday(): void
    {
        foreach ([2000 => '2000-04-23', 2011 => '2011-04-24', 2016 => '2016-03-27', 2024 => '2024-03-31', 2025 => '2025-04-20', 2026 => '2026-04-05', 2027 => '2027-03-28', 2038 => '2038-04-25'] as $year => $date) {
            $this->assertSame($date, CzechHolidays::easterSunday($year)->format('Y-m-d'), (string) $year);
        }
    }

    public function testHolidaysOfYearIncludeMovableFeasts(): void
    {
        $days = CzechHolidays::holidaysOfYear(2026);

        $this->assertContains('2026-04-03', $days, 'Velký pátek');
        $this->assertContains('2026-04-06', $days, 'Velikonoční pondělí');
        $this->assertContains('2026-01-01', $days);
        $this->assertContains('2026-12-26', $days);
        $this->assertCount(13, $days);
        $this->assertSame($days, array_values(array_unique($days)));
        $sorted = $days;
        sort($sorted);
        $this->assertSame($sorted, $days);
    }

    public function testGoodFridayIsHolidayOnlySince2016(): void
    {
        $this->assertFalse(CzechHolidays::isHoliday(new \DateTimeImmutable('2015-04-03')), 'Velký pátek 2015 ještě ne');
        $this->assertTrue(CzechHolidays::isHoliday(new \DateTimeImmutable('2015-04-06')), 'Velikonoční pondělí 2015 ano');
        $this->assertTrue(CzechHolidays::isHoliday(new \DateTimeImmutable('2016-03-25')), 'Velký pátek 2016');
        $this->assertCount(12, CzechHolidays::holidaysOfYear(2015));
    }

    public function testWorkingDayExcludesWeekendsAndHolidays(): void
    {
        $this->assertTrue(CzechHolidays::isWorkingDay(new \DateTimeImmutable('2026-10-16')), 'pátek');
        $this->assertFalse(CzechHolidays::isWorkingDay(new \DateTimeImmutable('2026-10-17')), 'sobota');
        $this->assertFalse(CzechHolidays::isWorkingDay(new \DateTimeImmutable('2026-10-18')), 'neděle');
        $this->assertFalse(CzechHolidays::isWorkingDay(new \DateTimeImmutable('2026-10-28')), 'státní svátek ve středu');
    }
}
