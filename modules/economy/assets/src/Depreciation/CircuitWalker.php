<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation;

use Shipard\Module\Economy\Assets\Depreciation\Method\CircuitMethod;
use Shipard\Module\Economy\Assets\Depreciation\Method\CircuitState;
use Shipard\Module\Economy\Assets\Depreciation\Method\Computed;

/**
 * Průchod událostmi jednoho okruhu: potvrzené události chronologicky
 * a mezi ně i za ně plánované odpisy.
 *
 * - Potvrzený odpis se nepřepočítává — převezme se a vedle něj se uvede
 *   spočtená hodnota (rozdíl = `mismatch`).
 * - Před změnou hodnoty a před přerušením se doplní plánované odpisy
 *   období, která skončila dřív: odpis roku 2023 se nesmí počítat ze
 *   zhodnocení z roku 2024.
 * - Za poslední událostí plán pokračuje do nulového zůstatku, vyřazení
 *   nebo vyčerpání metody.
 *
 * Instance je na jedno použití.
 *
 * @internal
 */
final class CircuitWalker
{
    /** Pojistka proti nekonečnému plánu (100 let po měsících). */
    private const MAX_STEPS = 1200;

    private CircuitState $state;

    /** @var list<PlanRow> */
    private array $rows = [];

    /** Skutečný stav z potvrzených událostí (souhrn plánu). */
    private float $confirmedEntryPrice = 0.0;
    private float $confirmedAccumulated = 0.0;

    /**
     * @param \Closure(float): bool $isWhole je částka zaokrouhlená dle pravidel okruhu?
     * @param list<PlanMessage> $messages hlášení k nastavení okruhu
     * @param bool $blocked okruh nejde počítat (chybné nastavení)
     */
    public function __construct(
        private readonly string $circuit,
        private readonly CircuitMethod $method,
        private readonly PeriodCalendar $calendar,
        private readonly \Closure $isWhole,
        private array $messages = [],
        private bool $blocked = false,
    ) {
        $this->state = new CircuitState();
    }

    /** @param list<AssetEvent> $events */
    public function walk(array $events, string $asOf): Plan
    {
        $state = $this->state;
        $events = $this->ownEvents($events);

        $disposal = null;
        foreach ($events as $event) {
            if ($event->kind === AssetEvent::KIND_DISPOSAL) {
                $disposal ??= $event;
            }
        }
        if ($disposal !== null) {
            $state->disposalMonth = Months::of($disposal->date);
            $state->disposalDate = $disposal->date;
            $state->disposalHalfYear = $disposal->halfYear;
        }

        foreach ($events as $event) {
            if ($event->isStart()) {
                $this->start($event);
            } elseif (!$state->started) {
                continue;
            } elseif ($event->kind === AssetEvent::KIND_IMPROVEMENT || $event->kind === AssetEvent::KIND_REDUCTION) {
                $this->valueChange($event);
            } elseif ($event->kind === AssetEvent::KIND_DEPRECIATION) {
                $this->depreciation($event);
            } elseif ($event->kind === AssetEvent::KIND_INTERRUPTION) {
                $this->interruption($event);
            }
        }

        $disposed = $disposal !== null && $state->started;
        if ($state->started) {
            $this->planUntil($state->disposalMonth, true);
        }
        if ($disposed) {
            // Vyřazení odepíše zůstatkovou cenu.
            $residual = $state->residual;
            $state->residual = 0.0;
            $this->rows[] = $this->row(
                $disposal->kind, PlanRow::STATUS_CONFIRMED, $disposal->date, null, $residual,
                halfYear: $disposal->halfYear, eventId: $disposal->id,
            );
        }

        $year = $this->calendar->yearOf($asOf);
        $currentYear = 0.0;
        foreach ($this->rows as $row) {
            if ($row->isDepreciation() && $year->contains($row->period?->end ?? $row->date)) {
                $currentYear += $row->amount;
            }
        }

        return new Plan(
            $this->circuit,
            $this->rows,
            $this->confirmedEntryPrice,
            $this->confirmedAccumulated,
            $disposed ? 0.0 : $this->confirmedEntryPrice - $this->confirmedAccumulated,
            $currentYear,
            $this->messages,
        );
    }

    /**
     * Potvrzené události okruhu, chronologicky (události téhož dne v pořadí
     * zařazení → změna hodnoty → přerušení → odpis → vyřazení).
     *
     * @param list<AssetEvent> $events
     * @return list<AssetEvent>
     */
    private function ownEvents(array $events): array
    {
        $own = [];
        foreach (array_values($events) as $index => $event) {
            if ($event->confirmed && $event->inCircuit($this->circuit)) {
                $own[] = [$event, $index];
            }
        }
        usort($own, static fn(array $a, array $b): int
            => [$a[0]->date, $a[0]->sortOrder(), $a[1]] <=> [$b[0]->date, $b[0]->sortOrder(), $b[1]]);

        return array_column($own, 0);
    }

    private function start(AssetEvent $event): void
    {
        $state = $this->state;
        if ($state->started) {
            return;
        }
        $state->started = true;
        $state->opening = $event->kind === AssetEvent::KIND_OPENING;
        $state->startDate = $event->date;
        $state->acquiredDate = $event->originalDate ?? $event->date;
        $state->entryPrice = $event->amount;
        $state->accumulated = $state->opening ? (float) ($event->accumulated ?? 0.0) : 0.0;
        $state->residual = $state->entryPrice - $state->accumulated;
        $this->confirmedEntryPrice = $state->entryPrice;
        $this->confirmedAccumulated = $state->accumulated;

        $messages = [];
        if (!$this->blocked) {
            $problems = $this->method->problems($state);
            if ($problems !== []) {
                array_push($this->messages, ...$problems);
                $this->blocked = true;
            } else {
                $this->method->start($state, $event);
                if ($state->opening) {
                    $messages = $this->method->checkOpening($state, $event);
                }
            }
        }

        $this->rows[] = $this->row(
            $event->kind, PlanRow::STATUS_CONFIRMED, $event->date, null, $event->amount,
            messages: $messages, eventId: $event->id,
        );
    }

    private function valueChange(AssetEvent $event): void
    {
        $state = $this->state;
        $this->planUntil(Months::of($event->date) - 1, false);

        $delta = $event->kind === AssetEvent::KIND_IMPROVEMENT ? $event->amount : -$event->amount;
        $state->entryPrice += $delta;
        $state->residual += $delta;
        $this->confirmedEntryPrice += $delta;

        $messages = $this->blocked ? [] : $this->method->valueChange($state, $event);

        $this->rows[] = $this->row(
            $event->kind, PlanRow::STATUS_CONFIRMED, $event->date, null, $event->amount,
            messages: $messages, eventId: $event->id,
        );
    }

    private function depreciation(AssetEvent $event): void
    {
        $state = $this->state;
        [$from, $to, $period] = $this->eventRange($event);

        $computed = $this->blocked ? null : $this->method->compute($state, $from, $to, $event->halfYear);
        $messages = [];

        if (!$this->blocked) {
            $expected = $computed?->amount ?? 0.0;
            if (abs($expected - $event->amount) >= Amounts::EPSILON) {
                $messages[] = PlanMessage::warning(PlanMessage::MISMATCH, [
                    'confirmed' => $event->amount,
                    'computed' => $expected,
                ]);
            }
            if ($state->hasPlanned || $this->hasGapBefore($from)) {
                $messages[] = PlanMessage::error(PlanMessage::MISSING_PERIOD);
            }
        }
        if ($event->periodEnd !== null && (
            $this->calendar->yearOf($event->date)->begin !== $this->calendar->yearOf($event->periodEnd)->begin
            || ($event->periodBegin !== null && $event->date < $event->periodBegin)
        )) {
            $messages[] = PlanMessage::error(PlanMessage::DATE_OUTSIDE_PERIOD, [
                'date' => $event->date,
                'periodEnd' => $event->periodEnd,
            ]);
        }
        if ($event->origin !== AssetEvent::ORIGIN_IMPORT && !($this->isWhole)($event->amount)) {
            $messages[] = PlanMessage::error(PlanMessage::NOT_WHOLE_UNITS, ['amount' => $event->amount]);
        }

        $this->apply($from, $to, $event->amount, $computed);
        $this->confirmedAccumulated += $event->amount;

        $this->rows[] = $this->row(
            $event->kind, PlanRow::STATUS_CONFIRMED, $event->date, $period, $event->amount,
            $computed?->amount ?? ($this->blocked ? null : 0.0), $computed?->formula,
            $messages, $event->halfYear, $event->claimUnrecorded, $event->id,
        );
    }

    private function interruption(AssetEvent $event): void
    {
        [$from, $to, $period] = $this->eventRange($event);
        $this->planUntil($from - 1, false);

        $this->cover($to);
        $this->rows[] = $this->row(
            $event->kind, PlanRow::STATUS_CONFIRMED, $event->date, $period, 0.0, eventId: $event->id,
        );
    }

    /**
     * Doplní plánované odpisy od prvního nepokrytého měsíce.
     *
     * Průběžně (`$final` = false) jen celá období končící nejpozději
     * měsícem `$limit`; na konci plánu až do vyčerpání, s vyřazením nejdéle
     * do měsíce vyřazení včetně (poslední období se zkrátí).
     */
    private function planUntil(?int $limit, bool $final): void
    {
        $state = $this->state;
        if ($this->blocked) {
            return;
        }

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            if ($state->residual < Amounts::EPSILON) {
                return;
            }
            $from = $state->lastCoveredMonth !== null
                ? $state->lastCoveredMonth + 1
                : $this->method->firstMonth($state);
            if ($this->method->exhausted($from)) {
                return;
            }

            $period = $this->calendar->periodOfMonth($from);
            $to = $period->endMonth();
            if ($limit !== null) {
                if ($final ? $from > $limit : $to > $limit) {
                    return;
                }
                $to = min($to, $limit);
            }

            $disposedInPeriod = $final && $state->disposalMonth !== null && $state->disposalMonth <= $period->endMonth();
            $computed = $this->method->compute($state, $from, $to, $disposedInPeriod && $state->disposalHalfYear);
            if ($computed === null) {
                // V období se neodpisuje (rozpis ještě nezačal, rok vyřazení bez poloviny…).
                $this->cover($to);
                continue;
            }

            $this->apply($from, $to, $computed->amount, $computed);
            $state->hasPlanned = true;

            $whole = $from === $period->beginMonth() && $to === $period->endMonth();
            $this->rows[] = $this->row(
                AssetEvent::KIND_DEPRECIATION,
                PlanRow::STATUS_PLANNED,
                $disposedInPeriod ? (string) $state->disposalDate : Months::lastDay($to),
                $whole ? $period : new Period(null, Months::firstDay($from), Months::lastDay($to)),
                $computed->amount,
                $computed->amount,
                $computed->formula,
                halfYear: $disposedInPeriod && $state->disposalHalfYear,
            );
        }
    }

    private function apply(int $from, int $to, float $amount, ?Computed $computed): void
    {
        $state = $this->state;
        $state->residual -= $amount;
        $state->accumulated += $amount;
        if (!$this->blocked) {
            $this->method->applied($state, $from, $to, $amount, $computed);
        }
        $this->cover($to);
    }

    private function cover(int $month): void
    {
        $this->state->lastCoveredMonth = max(
            $this->state->lastCoveredMonth ?? PHP_INT_MIN,
            $this->method->coveredUntil($month),
        );
    }

    /** Zůstalo před měsícem `$from` celé období kalendáře bez odpisu? */
    private function hasGapBefore(int $from): bool
    {
        $expected = $this->state->lastCoveredMonth !== null
            ? $this->state->lastCoveredMonth + 1
            : $this->method->firstMonth($this->state);

        return $expected < $from && $this->calendar->periodOfMonth($expected)->endMonth() < $from;
    }

    /**
     * Rozsah měsíců odpisu nebo přerušení; bez období na události platí
     * období kalendáře podle data.
     *
     * @return array{int, int, Period}
     */
    private function eventRange(AssetEvent $event): array
    {
        $calendarPeriod = $this->calendar->periodOf($event->periodEnd ?? $event->date);
        $begin = $event->periodBegin ?? $calendarPeriod->begin;
        $end = $event->periodEnd ?? $calendarPeriod->end;

        $period = $begin === $calendarPeriod->begin && $end === $calendarPeriod->end
            ? $calendarPeriod
            : new Period(null, $begin, $end);

        return [Months::of($begin), Months::of($end), $period];
    }

    /** @param list<PlanMessage> $messages */
    private function row(
        string $kind,
        string $status,
        string $date,
        ?Period $period,
        float $amount,
        ?float $computed = null,
        ?string $formula = null,
        array $messages = [],
        bool $halfYear = false,
        bool $claimUnrecorded = false,
        ?int $eventId = null,
    ): PlanRow {
        return new PlanRow(
            $kind, $status, $date, $period, $amount, $computed, $formula,
            $this->state->entryPrice, $this->state->accumulated, $this->state->residual,
            $messages, $halfYear, $claimUnrecorded, $eventId,
        );
    }
}
