<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates\Download;

use Shipard\Module\World\Cz\Calendar\CzechHolidays;

/** Kalendář `cz`: pracovní den = ne víkend a ne český svátek (`world.cz`). */
final class CzechWorkingDayCalendar implements WorkingDayCalendar
{
    public function isWorkingDay(\DateTimeImmutable $day): bool
    {
        return CzechHolidays::isWorkingDay($day);
    }
}
