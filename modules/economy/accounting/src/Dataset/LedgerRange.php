<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting\Dataset;

/**
 * Nejmenší granularita hlavní knihy, která pokryje použité měsíce
 * (`ReportParamValidator::checkGranularity`: jeden měsíc, zarovnané
 * čtvrtletí, zarovnané pololetí, celý rok). Pozice = pořadí mezi řádnými
 * měsíci fiskálního roku (1..n).
 */
final class LedgerRange
{
    /** @return array{from: int, to: int, granularity: string} */
    public static function pick(int $min, int $max, int $count): array
    {
        if ($min < 1 || $max < $min || $count < $max) {
            throw new \InvalidArgumentException("Neplatný rozsah měsíců {$min}–{$max} z {$count}.");
        }
        if ($min === $max) {
            return ['from' => $min, 'to' => $min, 'granularity' => 'month'];
        }
        $quarter = intdiv($min - 1, 3) * 3 + 1;
        if ($max <= $quarter + 2 && $quarter + 2 <= $count) {
            return ['from' => $quarter, 'to' => $quarter + 2, 'granularity' => 'quarter'];
        }
        $half = intdiv($min - 1, 6) * 6 + 1;
        if ($max <= $half + 5 && $half + 5 <= $count) {
            return ['from' => $half, 'to' => $half + 5, 'granularity' => 'halfYear'];
        }
        return ['from' => 1, 'to' => $count, 'granularity' => 'year'];
    }
}
