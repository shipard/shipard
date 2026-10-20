<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates\Download;

use Shipard\Module\World\Cz\Calendar\CzechHolidays;

/**
 * Mapa kalendářů pro `download.calendar` (#121 task B §2.1) — dnes jediný
 * záznam `cz`. Modul economy.exchangeRates na world.cz nezávisí, proto
 * pojistka `class_exists`: bez třídy kalendář chybí a typ se nestahuje
 * s varováním (ne pád úlohy).
 */
final class WorkingDayCalendars
{
    /** @param array<string, WorkingDayCalendar> $calendars */
    public function __construct(private readonly array $calendars)
    {
    }

    public static function defaults(): self
    {
        $calendars = [];
        if (class_exists(CzechHolidays::class)) {
            $calendars['cz'] = new CzechWorkingDayCalendar();
        }
        return new self($calendars);
    }

    public function get(string $id): ?WorkingDayCalendar
    {
        return $this->calendars[$id] ?? null;
    }
}
