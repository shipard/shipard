<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation\Method;

use Shipard\Module\Economy\Assets\Depreciation\Amounts;
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Assets\Depreciation\Months;
use Shipard\Module\Economy\Assets\Depreciation\PlanMessage;

/**
 * Účetní metoda `time`: rovnoměrně po dobu zadanou na kartě, od měsíce po
 * zařazení (D37).
 *
 * Každý měsíc připadá zůstatková cena / zbývající měsíce původní doby —
 * technické zhodnocení se tak od následujícího měsíce rozpustí do zbytku
 * doby (D44), snížení hodnoty obdobně. Součet měsíců období se
 * zaokrouhluje nahoru na koruny jednou za období (D36). Po uplynutí doby
 * jde případný zbytek do nejbližšího období.
 *
 * @internal
 */
final class AccTimeMethod implements CircuitMethod
{
    private int $start = 0;
    /** Poslední měsíc původní doby odpisování. */
    private int $end = 0;

    /**
     * Změny hodnoty platné od měsíce po události.
     *
     * @var list<array{month: int, delta: float}>
     */
    private array $pending = [];

    public function __construct(private readonly int $months)
    {
    }

    public function problems(CircuitState $state): array
    {
        if ($this->months > 0) {
            return [];
        }
        return [PlanMessage::error(PlanMessage::SETTINGS_INVALID, ['reason' => 'accMonthsMissing'])];
    }

    public function start(CircuitState $state, AssetEvent $event): void
    {
        if ($event->kind === AssetEvent::KIND_OPENING) {
            // `unitsDone` = měsíce, které už z doby odpisování uplynuly.
            $this->start = Months::of($event->date);
            $this->end = $this->start + max($this->months - (int) ($event->unitsDone ?? 0), 1) - 1;
            return;
        }
        $this->start = Months::of($event->date) + 1;
        $this->end = $this->start + $this->months - 1;
    }

    public function checkOpening(CircuitState $state, AssetEvent $event): array
    {
        if ($event->priceIncreased) {
            return [];
        }
        $done = min((int) ($event->unitsDone ?? 0), $this->months);
        $expected = $state->entryPrice * $done / $this->months;

        // Historie se zaokrouhlovala nahoru nejméně jednou za rok.
        $tolerance = intdiv($done, 12) + 1;
        if (abs($expected - $state->accumulated) <= $tolerance) {
            return [];
        }
        return [PlanMessage::warning(PlanMessage::OPENING_MISMATCH, [
            'accumulated' => $state->accumulated,
            'expected' => $expected,
            'units' => $done,
        ])];
    }

    public function valueChange(CircuitState $state, AssetEvent $event): array
    {
        $this->pending[] = [
            'month' => Months::of($event->date),
            'delta' => $event->kind === AssetEvent::KIND_IMPROVEMENT ? $event->amount : -$event->amount,
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
        $first = max($from, $this->start);
        if ($first > $to) {
            return null;
        }

        $pending = $this->pending;
        usort($pending, static fn(array $a, array $b): int => $a['month'] <=> $b['month']);

        // Zůstatek před změnami, které se do odpisů ještě nepromítly.
        $running = $state->residual - array_sum(array_column($pending, 'delta'));
        $exact = 0.0;
        $parts = [];
        $later = [];
        foreach ($pending as $change) {
            if ($change['month'] >= $to) {
                $later[] = $change;
                continue;
            }
            if ($change['month'] >= $first) {
                $exact += $this->segment($running, $first, $change['month'], $parts);
                $first = $change['month'] + 1;
            }
            $running += $change['delta'];
        }
        $exact += $this->segment($running, $first, $to, $parts);

        // Zhodnocení platné až po tomto období se v něm neodpisuje; snížení
        // už zůstatek zmenšilo.
        $available = min($state->residual, $state->residual - array_sum(array_column($later, 'delta')));
        $amount = min(Amounts::ceil($exact), $available);
        if ($amount < Amounts::EPSILON) {
            return null;
        }

        return new Computed($amount, implode(' + ', $parts), $later);
    }

    public function applied(CircuitState $state, int $from, int $to, float $amount, ?Computed $computed): void
    {
        $this->pending = $computed !== null
            ? $computed->commit
            : array_values(array_filter($this->pending, static fn(array $c): bool => $c['month'] >= $to));
    }

    /**
     * Odpis úseku měsíců: zůstatek / zbývající měsíce × měsíce úseku.
     * Zmenší `$running` o spočtený odpis.
     *
     * @param list<string> $parts
     */
    private function segment(float &$running, int $from, int $to, array &$parts): float
    {
        $remaining = max($this->end - $from + 1, 1);
        $months = min($to - $from + 1, $remaining);
        if ($months <= 0 || $running <= 0) {
            return 0.0;
        }
        $amount = $running * $months / $remaining;
        $parts[] = Amounts::money($running) . " / {$remaining} × {$months}";
        $running -= $amount;

        return $amount;
    }
}
