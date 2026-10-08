<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Invoicing;

/**
 * Kalendářní období periodické zakázky (docs/work-orders.md D5, D6, Q3):
 * čisté funkce nad daty `Y-m-d`, bez databáze.
 *
 *  - období podle periodicity (měsíc, čtvrtletí, pololetí, rok) jsou
 *    kalendářní; první = období obsahující „fakturovat od“;
 *  - den fakturace = počátek (`start`) nebo konec (`end`) období — je to
 *    datum vystavení i DUZP dokladu;
 *  - období je splatné, když jeho den fakturace ≤ datum běhu;
 *  - období začínající po datu ukončení zakázky se nevystaví, období přes
 *    datum ukončení se vystaví celé (bez krácení).
 */
final class PeriodCalendar
{
    /** Pojistka proti nekonečné smyčce (100 let měsíčních období). */
    private const MAX_PERIODS = 1200;

    public static function containing(string $date, string $periodicity): Period
    {
        $d = self::toDate($date);
        $year = (int) $d->format('Y');
        $month = (int) $d->format('n');

        [$firstMonth, $months] = match ($periodicity) {
            'month'    => [$month, 1],
            'quarter'  => [intdiv($month - 1, 3) * 3 + 1, 3],
            'halfyear' => [$month <= 6 ? 1 : 7, 6],
            'year'     => [1, 12],
            default    => throw new \InvalidArgumentException("Neznámá periodicita '{$periodicity}'"),
        };

        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $firstMonth));
        $to = $from->modify('+' . $months . ' months')->modify('-1 day');

        return new Period($from->format('Y-m-d'), $to->format('Y-m-d'));
    }

    public static function next(Period $period, string $periodicity): Period
    {
        return self::containing(self::toDate($period->to)->modify('+1 day')->format('Y-m-d'), $periodicity);
    }

    /** Den fakturace období = datum vystavení a DUZP dokladu (D6). */
    public static function billingDate(Period $period, string $timing): string
    {
        return $timing === InvoicingSettings::TIMING_END ? $period->to : $period->from;
    }

    /** Pořadí období v roce (1–12 / 1–4 / 1–2 / 1) pro popisek `{období}`. */
    public static function ordinalInYear(Period $period, string $periodicity): int
    {
        $month = (int) self::toDate($period->from)->format('n');
        return match ($periodicity) {
            'month'    => $month,
            'quarter'  => intdiv($month - 1, 3) + 1,
            'halfyear' => $month <= 6 ? 1 : 2,
            default    => 1,
        };
    }

    /**
     * Splatná období od „fakturovat od“ k datu běhu, v pořadí.
     *
     * @return list<Period>
     */
    public static function duePeriods(
        string $invoiceFrom,
        ?string $dateEnd,
        string $runDate,
        string $periodicity,
        string $timing,
    ): array {
        $out = [];
        $period = self::containing($invoiceFrom, $periodicity);
        while (count($out) < self::MAX_PERIODS) {
            if ($dateEnd !== null && $period->from > $dateEnd) {
                break;
            }
            if (self::billingDate($period, $timing) > $runDate) {
                break;
            }
            $out[] = $period;
            $period = self::next($period, $periodicity);
        }
        return $out;
    }

    /**
     * První dosud nesplatné období (pro „příští splatné období“ na záložce
     * Fakturace); null = za datem ukončení už žádné není.
     */
    public static function nextDue(
        string $invoiceFrom,
        ?string $dateEnd,
        string $runDate,
        string $periodicity,
        string $timing,
    ): ?Period {
        $period = self::containing($invoiceFrom, $periodicity);
        for ($i = 0; $i < self::MAX_PERIODS; $i++) {
            if ($dateEnd !== null && $period->from > $dateEnd) {
                return null;
            }
            if (self::billingDate($period, $timing) > $runDate) {
                return $period;
            }
            $period = self::next($period, $periodicity);
        }
        return null;
    }

    private static function toDate(string $date): \DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));
        if ($d === false) {
            throw new \InvalidArgumentException("Neplatné datum '{$date}'");
        }
        return $d;
    }
}
