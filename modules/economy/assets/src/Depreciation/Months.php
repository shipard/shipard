<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation;

/**
 * Měsíc jako celé číslo (`rok × 12 + měsíc − 1`) — engine počítá období
 * odpisů po celých měsících a potřebuje je sčítat a porovnávat.
 */
final class Months
{
    public static function of(string $date): int
    {
        return (int) substr($date, 0, 4) * 12 + (int) substr($date, 5, 2) - 1;
    }

    public static function firstDay(int $month): string
    {
        return sprintf('%04d-%02d-01', intdiv($month, 12), $month % 12 + 1);
    }

    public static function lastDay(int $month): string
    {
        return (new \DateTimeImmutable(self::firstDay($month)))->format('Y-m-t');
    }
}
