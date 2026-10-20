<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates\Download;

/** Kalendář pracovních dnů pro `download.calendar` typu lístku (#121 task B U2). */
interface WorkingDayCalendar
{
    public function isWorkingDay(\DateTimeImmutable $day): bool;
}
