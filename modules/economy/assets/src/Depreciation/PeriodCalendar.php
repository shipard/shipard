<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation;

/**
 * Kalendář období jednoho okruhu odpisů: účetní roky, volitelně členěné
 * na měsíce (četnost účetních odpisů, D12).
 *
 * Mimo založené roky se období dopočítávají po 12 měsících od nejbližšího
 * založeného roku — dopředu pro plán (D32), dozadu pro historii před
 * prvním rokem v DS. Bez jediného založeného roku platí kalendářní roky.
 * Období se předpokládají zarovnaná na celé měsíce.
 */
final class PeriodCalendar
{
    /** @var list<Period> */
    private array $years = [];

    /** @var array<string, int> první den měsíce → id účetního měsíce */
    private array $monthIds = [];

    /**
     * @param list<Period|array<string, mixed>> $years
     * @param list<Period|array<string, mixed>> $months
     */
    private function __construct(array $years, private readonly bool $monthly, array $months)
    {
        foreach ($years as $year) {
            $this->years[] = $year instanceof Period ? $year : Period::fromArray($year);
        }
        usort($this->years, static fn(Period $a, Period $b): int => $a->begin <=> $b->begin);

        foreach ($months as $month) {
            $month = $month instanceof Period ? $month : Period::fromArray($month);
            if ($month->id !== null) {
                $this->monthIds[$month->begin] = $month->id;
            }
        }
    }

    /**
     * Období = účetní roky (daňový okruh, účetní při roční četnosti).
     *
     * @param list<Period|array<string, mixed>> $years
     */
    public static function yearly(array $years): self
    {
        return new self($years, false, []);
    }

    /**
     * Období = měsíce (účetní okruh při měsíční četnosti). `$months` jsou
     * založené účetní měsíce — jen kvůli `id` v plánu.
     *
     * @param list<Period|array<string, mixed>> $years
     * @param list<Period|array<string, mixed>> $months
     */
    public static function monthly(array $years, array $months = []): self
    {
        return new self($years, true, $months);
    }

    public function isMonthly(): bool
    {
        return $this->monthly;
    }

    /** Účetní rok, do kterého datum patří. */
    public function yearOf(string $date): Period
    {
        if ($this->years === []) {
            $year = substr($date, 0, 4);
            return new Period(null, "{$year}-01-01", "{$year}-12-31");
        }

        $anchor = null;
        foreach ($this->years as $year) {
            if ($year->contains($date)) {
                return $year;
            }
            if ($year->begin <= $date) {
                $anchor = $year;
            }
        }

        if ($anchor === null) {
            $period = $this->years[0];
            while ($date < $period->begin) {
                $period = self::previousYear($period);
            }
            return $period;
        }

        $period = $anchor;
        while ($date > $period->end) {
            $period = self::nextYear($period);
        }
        return $period;
    }

    /** Období kalendáře (rok nebo měsíc), do kterého datum patří. */
    public function periodOf(string $date): Period
    {
        if (!$this->monthly) {
            return $this->yearOf($date);
        }
        $month = Months::of($date);
        $begin = Months::firstDay($month);

        return new Period($this->monthIds[$begin] ?? null, $begin, Months::lastDay($month));
    }

    public function yearOfMonth(int $month): Period
    {
        return $this->yearOf(Months::firstDay($month));
    }

    public function periodOfMonth(int $month): Period
    {
        return $this->periodOf(Months::firstDay($month));
    }

    private static function nextYear(Period $period): Period
    {
        $begin = (new \DateTimeImmutable($period->end))->modify('+1 day');
        $end = $begin->modify('+1 year')->modify('-1 day');

        return new Period(null, $begin->format('Y-m-d'), $end->format('Y-m-d'));
    }

    private static function previousYear(Period $period): Period
    {
        $begin = (new \DateTimeImmutable($period->begin))->modify('-1 year');
        $end = (new \DateTimeImmutable($period->begin))->modify('-1 day');

        return new Period(null, $begin->format('Y-m-d'), $end->format('Y-m-d'));
    }
}
