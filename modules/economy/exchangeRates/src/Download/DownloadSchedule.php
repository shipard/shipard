<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates\Download;

/**
 * Která data lístků typ v okně očekává (#121 task B §4.2, §6) — sdílí
 * stahovač i upozornění na chybějící lístek, aby se nerozešly v tom, co
 * je pracovní den:
 *
 *   - `workingDays`: pracovní dny kalendáře od `from` po dnešek typu
 *     (datum v jeho zóně), nejvýš po `to`; dnešek jen po `publishAfter`;
 *   - `monthly`: 1. den každého měsíce od měsíce `from` po měsíc `to`
 *     (začátek uprostřed měsíce se normalizuje na jeho 1. den).
 */
final class DownloadSchedule
{
    /** @return list<string> data Y-m-d vzestupně */
    public static function expectedDates(ListDownloadConfig $config, ?WorkingDayCalendar $calendar, string $from, string $to, \DateTimeImmutable $now): array
    {
        if ($from > $to) {
            return [];
        }
        if ($config->isMonthly()) {
            $dates = [];
            $month = new \DateTimeImmutable(substr($from, 0, 7) . '-01');
            $last = substr($to, 0, 7) . '-01';
            while ($month->format('Y-m-d') <= $last) {
                $dates[] = $month->format('Y-m-d');
                $month = $month->modify('first day of next month');
            }
            return $dates;
        }
        if ($calendar === null) {
            return [];
        }
        $today = $config->today($now);
        $last = min($to, $today);
        if ($last === $today && !$config->isPublished($now)) {
            $last = (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        }
        $dates = [];
        $day = new \DateTimeImmutable($from);
        while ($day->format('Y-m-d') <= $last) {
            if ($calendar->isWorkingDay($day)) {
                $dates[] = $day->format('Y-m-d');
            }
            $day = $day->modify('+1 day');
        }
        return $dates;
    }
}
