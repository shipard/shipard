<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Deadline;

use Shipard\Module\World\Cz\Calendar\CzechHolidays;

/**
 * ČR (§ 101 odst. 1 zákona o DPH): přiznání do 25 dnů po skončení
 * zdaňovacího období; připadne-li poslední den lhůty na sobotu, neděli
 * nebo svátek, je posledním dnem lhůty nejbližší následující pracovní den
 * (§ 33 odst. 4 daňového řádu). Vlastní konstanta — `payableDueDays`
 * v `vat-reports-cz.jsonc` je splatnost daně, jiná lhůta.
 */
final class CzFilingDeadlineRule implements FilingDeadlineRule
{
    public const DUE_DAYS = 25;

    public function returnDeadline(string $periodEnd): string
    {
        $day = (new \DateTimeImmutable($periodEnd))->modify('+' . self::DUE_DAYS . ' days');
        while (!CzechHolidays::isWorkingDay($day)) {
            $day = $day->modify('+1 day');
        }
        return $day->format('Y-m-d');
    }
}
