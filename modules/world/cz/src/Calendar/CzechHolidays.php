<?php

declare(strict_types=1);

namespace Shipard\Module\World\Cz\Calendar;

/**
 * Státní svátky a ostatní svátky České republiky (zákon č. 245/2000 Sb.)
 * pro posun termínů na pracovní den (#120 D17): pevná data + Velký pátek
 * (svátkem od roku 2016) a Velikonoční pondělí. Velikonoční neděle
 * gregoriánským algoritmem (Meeus / Jones / Butcher), bez `ext/calendar`.
 */
final class CzechHolidays
{
    /** Pevné svátky `MM-DD`. */
    private const FIXED = [
        '01-01', // Den obnovy samostatného českého státu / Nový rok
        '05-01', // Svátek práce
        '05-08', // Den vítězství
        '07-05', // Cyril a Metoděj
        '07-06', // Jan Hus
        '09-28', // Den české státnosti
        '10-28', // Den vzniku samostatného československého státu
        '11-17', // Den boje za svobodu a demokracii
        '12-24', // Štědrý den
        '12-25', // 1. svátek vánoční
        '12-26', // 2. svátek vánoční
    ];

    /** Velký pátek je svátkem od roku 2016 (zákon č. 359/2015 Sb.). */
    private const GOOD_FRIDAY_SINCE = 2016;

    public static function isHoliday(\DateTimeImmutable $day): bool
    {
        return in_array($day->format('Y-m-d'), self::holidaysOfYear((int) $day->format('Y')), true);
    }

    /** Pracovní den = ne sobota / neděle a ne svátek. */
    public static function isWorkingDay(\DateTimeImmutable $day): bool
    {
        return (int) $day->format('N') < 6 && !self::isHoliday($day);
    }

    /** @return list<string> ISO data svátků roku, vzestupně */
    public static function holidaysOfYear(int $year): array
    {
        $days = [];
        foreach (self::FIXED as $monthDay) {
            $days[] = sprintf('%04d-%s', $year, $monthDay);
        }
        $easter = self::easterSunday($year);
        if ($year >= self::GOOD_FRIDAY_SINCE) {
            $days[] = $easter->modify('-2 days')->format('Y-m-d');
        }
        $days[] = $easter->modify('+1 day')->format('Y-m-d');
        sort($days);
        return $days;
    }

    /** Velikonoční neděle (gregoriánský kalendář, Meeus / Jones / Butcher). */
    public static function easterSunday(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day   = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
