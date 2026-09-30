<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation\Method;

use Shipard\Module\Economy\Assets\Depreciation\Amounts;
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Assets\Depreciation\Months;
use Shipard\Module\Economy\Assets\Depreciation\PlanMessage;
use Shipard\Module\World\Assets\TaxAmount;
use Shipard\Module\World\Assets\TaxDepreciationRules;
use Shipard\Module\World\Assets\TaxScheduleInput;

/**
 * Měsíční metody pravidel země (časové a mimořádné odpisy) — daňový okruh
 * i účetní metoda `as_tax` nad nimi.
 *
 * Rozpis běží podle kalendáře od měsíce po zařazení (D37) a nejde
 * přerušit; součet měsíců období se zaokrouhluje jednou za období
 * a poslední období dorovná zůstatek. Technické zhodnocení spustí od
 * následujícího měsíce nový rozpis ze zvýšené zůstatkové ceny (délku určí
 * pravidla), snížení hodnoty jen zmenší základ. Změna hodnoty uprostřed
 * období ho proto dělí na úseky.
 *
 * @internal
 */
final class MonthlyRulesMethod implements CircuitMethod
{
    /** První měsíc rozpisu. */
    private int $start = 0;
    /** Měsíce rozpisu uplynulé před `$start` (počáteční stav). */
    private int $offset = 0;
    private float $base = 0.0;
    private bool $increased = false;
    private int $remainingAtIncrease = 0;

    /**
     * Změny hodnoty, které ještě nevstoupily do rozpisu (platí od měsíce
     * po události).
     *
     * @var list<array{month: int, delta: float, restart: bool}>
     */
    private array $pending = [];

    public function __construct(
        private readonly TaxDepreciationRules $rules,
        private readonly string $method,
        private readonly string $rule,
    ) {
    }

    public function problems(CircuitState $state): array
    {
        return RuleCheck::problems($this->rules, $this->method, $this->rule, $state->acquiredDate);
    }

    public function start(CircuitState $state, AssetEvent $event): void
    {
        $this->base = $state->entryPrice;

        if ($event->kind !== AssetEvent::KIND_OPENING) {
            $this->start = Months::of($event->date) + 1;
            return;
        }

        $this->start = Months::of($event->date);
        $done = (int) ($event->unitsDone ?? 0);

        if ($event->priceIncreased && $this->rules->allowsImprovement($this->method)) {
            // Historii před zhodnocením neznáme: pokračuje rozpis po
            // zhodnocení ze zůstatkové ceny na zbytek původní doby.
            $remaining = max($this->total($state, $this->schedule()) - $done, 0);
            $this->base = $state->residual;
            $this->increased = true;
            $this->remainingAtIncrease = $remaining;
            return;
        }
        $this->offset = $done;
    }

    public function checkOpening(CircuitState $state, AssetEvent $event): array
    {
        if ($event->priceIncreased) {
            return [];
        }
        $done = (int) ($event->unitsDone ?? 0);
        $expected = $done > 0
            ? $this->rules->scheduleAmount($this->input($state, $this->schedule(0), $state->entryPrice, 0, $done))->exact
            : 0.0;

        // Historie se zaokrouhlovala nahoru jednou za rok.
        $tolerance = intdiv($done, 12) + 1;
        if (abs($expected - $state->accumulated) <= $tolerance) {
            return [];
        }
        return [PlanMessage::warning(
            PlanMessage::OPENING_MISMATCH,
            ['accumulated' => $state->accumulated, 'expected' => $expected, 'units' => $done],
        )];
    }

    public function valueChange(CircuitState $state, AssetEvent $event): array
    {
        $improvement = $event->kind === AssetEvent::KIND_IMPROVEMENT;
        if ($improvement && !$this->rules->allowsImprovement($this->method)) {
            return [PlanMessage::error(
                PlanMessage::IMPROVEMENT_ON_SCHEDULE,
            )];
        }
        $this->pending[] = [
            'month' => Months::of($event->date),
            'delta' => $improvement ? $event->amount : -$event->amount,
            'restart' => $improvement,
        ];
        return [];
    }

    public function firstMonth(CircuitState $state): int
    {
        return $this->start;
    }

    public function coveredUntil(int $month): int
    {
        return $month;
    }

    public function exhausted(int $month): bool
    {
        return false;
    }

    public function compute(CircuitState $state, int $from, int $to, bool $halfYear): ?Computed
    {
        $schedule = $this->schedule();
        $pending = $this->pending;
        usort($pending, static fn(array $a, array $b): int => $a['month'] <=> $b['month']);

        // Zůstatek před změnami, které do rozpisu ještě nevstoupily.
        $running = $state->residual - array_sum(array_column($pending, 'delta'));
        $first = max($from, $schedule['start']);

        $exact = 0.0;
        $parts = [];
        $later = [];
        foreach ($pending as $change) {
            if ($change['month'] >= $to) {
                $later[] = $change;
                continue;
            }
            if ($change['month'] >= $first) {
                $segment = $this->segment($state, $schedule, $running, $first, $change['month']);
                $exact += $segment->exact;
                $running -= $segment->exact;
                if ($segment->formula !== '') {
                    $parts[] = $segment->formula;
                }
                $first = $change['month'] + 1;
            }
            [$schedule, $running] = $this->change($state, $schedule, $running, $change);
        }

        $first = max($first, $schedule['start']);
        if ($first > $to) {
            return null;
        }
        $segment = $this->segment($state, $schedule, $running, $first, $to);
        $exact += $segment->exact;
        if ($segment->formula !== '') {
            $parts[] = $segment->formula;
        }

        // Zhodnocení platné až po tomto období se v něm neodpisuje; snížení
        // už zůstatek zmenšilo.
        $available = min($state->residual, $state->residual - array_sum(array_column($later, 'delta')));
        $finishing = $this->position($schedule, $to) + 1 >= $this->total($state, $schedule);
        $amount = $finishing ? $available : min($this->rules->round($exact), $available);
        if ($amount < Amounts::EPSILON) {
            return null;
        }

        return new Computed(
            $amount,
            $parts === [] ? Amounts::money($amount) : implode(' + ', $parts),
            ['schedule' => $schedule, 'pending' => $later],
        );
    }

    public function applied(CircuitState $state, int $from, int $to, float $amount, ?Computed $computed): void
    {
        if ($computed === null) {
            return;
        }
        [
            'start' => $this->start,
            'offset' => $this->offset,
            'base' => $this->base,
            'increased' => $this->increased,
            'remaining' => $this->remainingAtIncrease,
        ] = $computed->commit['schedule'];
        $this->pending = $computed->commit['pending'];
    }

    /** @return array{start: int, offset: int, base: float, increased: bool, remaining: int} */
    private function schedule(?int $offset = null): array
    {
        return [
            'start' => $this->start,
            'offset' => $offset ?? $this->offset,
            'base' => $this->base,
            'increased' => $this->increased,
            'remaining' => $this->remainingAtIncrease,
        ];
    }

    /**
     * Změna hodnoty vstupuje do rozpisu od měsíce po události.
     *
     * @param array{start: int, offset: int, base: float, increased: bool, remaining: int} $schedule
     * @param array{month: int, delta: float, restart: bool} $change
     * @return array{array{start: int, offset: int, base: float, increased: bool, remaining: int}, float}
     */
    private function change(CircuitState $state, array $schedule, float $running, array $change): array
    {
        $running += $change['delta'];
        $effective = $change['month'] + 1;

        if ($change['restart'] && $effective > $schedule['start']) {
            $remaining = max($this->total($state, $schedule) - $this->position($schedule, $effective), 0);
            return [[
                'start' => $effective,
                'offset' => 0,
                'base' => $running,
                'increased' => true,
                'remaining' => $remaining,
            ], $running];
        }

        // Snížení hodnoty, nebo zhodnocení dřív, než rozpis začal (součást ceny).
        $schedule['base'] += $change['delta'];
        return [$schedule, $running];
    }

    /** @param array{start: int, offset: int, base: float, increased: bool, remaining: int} $schedule */
    private function segment(CircuitState $state, array $schedule, float $running, int $from, int $to): TaxAmount
    {
        return $this->rules->scheduleAmount($this->input(
            $state,
            $schedule,
            max($running, 0.0),
            $this->position($schedule, $from),
            $to - $from + 1,
        ));
    }

    /** Počet měsíců rozpisu uplynulých před měsícem `$month`. */
    private function position(array $schedule, int $month): int
    {
        return max($schedule['offset'] + $month - $schedule['start'], 0);
    }

    /** @param array{start: int, offset: int, base: float, increased: bool, remaining: int} $schedule */
    private function total(CircuitState $state, array $schedule): int
    {
        return $this->rules->scheduleMonths($this->input($state, $schedule, 0.0, 0, 0));
    }

    /** @param array{start: int, offset: int, base: float, increased: bool, remaining: int} $schedule */
    private function input(CircuitState $state, array $schedule, float $residual, int $done, int $months): TaxScheduleInput
    {
        return new TaxScheduleInput(
            $this->method,
            $this->rule,
            $state->acquiredDate,
            $schedule['base'],
            $residual,
            $done,
            $months,
            $schedule['increased'],
            $schedule['remaining'],
        );
    }
}
