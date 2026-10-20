<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates\Download;

/**
 * Začátek okna stahování = začátek předchozího fiskálního roku (#121 D3,
 * task B §4.1):
 *
 *   1. rok, který končí den před začátkem roku obsahujícího dnešek → jeho začátek;
 *   2. jinak začátek roku obsahujícího dnešek minus 1 rok;
 *   3. bez fiskálních let (nebo bez roku s dneškem) 1. 1. předchozího
 *      kalendářního roku.
 *
 * Data jako `Y-m-d` — žádná časová zóna.
 */
final class DownloadWindow
{
    /** @param list<array{begin: string, end: string}> $fiscalYears */
    public static function start(string $today, array $fiscalYears): string
    {
        $current = null;
        foreach ($fiscalYears as $year) {
            if ($year['begin'] <= $today && $year['end'] >= $today) {
                $current = $year;
                break;
            }
        }
        if ($current === null) {
            return ((int) substr($today, 0, 4) - 1) . '-01-01';
        }
        $begin = new \DateTimeImmutable($current['begin']);
        $previousEnd = $begin->modify('-1 day')->format('Y-m-d');
        foreach ($fiscalYears as $year) {
            if ($year['end'] === $previousEnd) {
                return $year['begin'];
            }
        }
        return $begin->modify('-1 year')->format('Y-m-d');
    }
}
