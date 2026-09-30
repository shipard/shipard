<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation\Method;

use Shipard\Module\Economy\Assets\Depreciation\Amounts;
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Assets\Depreciation\Months;
use Shipard\Module\Economy\Assets\Depreciation\PeriodCalendar;
use Shipard\Module\Economy\Assets\Depreciation\Plan;

/**
 * Daňová metoda „podle účetních odpisů“: daňový odpis zdaňovacího období
 * je součet účetních odpisů (potvrzených i plánovaných) téže karty, jejichž
 * období v něm končí.
 *
 * @internal
 */
final class FromAccountingMethod implements CircuitMethod
{
    /** @var list<array{month: int, amount: float}> */
    private array $accounting = [];

    public function __construct(Plan $accountingPlan, private readonly PeriodCalendar $calendar)
    {
        foreach ($accountingPlan->rows as $row) {
            if ($row->isDepreciation()) {
                $this->accounting[] = [
                    'month' => Months::of($row->period?->end ?? $row->date),
                    'amount' => $row->amount,
                ];
            }
        }
    }

    public function problems(CircuitState $state): array
    {
        return [];
    }

    public function start(CircuitState $state, AssetEvent $event): void
    {
    }

    public function checkOpening(CircuitState $state, AssetEvent $event): array
    {
        return [];
    }

    public function valueChange(CircuitState $state, AssetEvent $event): array
    {
        return [];
    }

    public function firstMonth(CircuitState $state): int
    {
        return $this->calendar->yearOf($state->startDate)->beginMonth();
    }

    public function coveredUntil(int $month): int
    {
        return $this->calendar->yearOfMonth($month)->endMonth();
    }

    public function exhausted(int $month): bool
    {
        foreach ($this->accounting as $entry) {
            if ($entry['month'] >= $month) {
                return false;
            }
        }
        return true;
    }

    public function compute(CircuitState $state, int $from, int $to, bool $halfYear): ?Computed
    {
        $year = $this->calendar->yearOfMonth($from);
        $sum = 0.0;
        foreach ($this->accounting as $entry) {
            if ($entry['month'] >= $year->beginMonth() && $entry['month'] <= $year->endMonth()) {
                $sum += $entry['amount'];
            }
        }
        $amount = min($sum, $state->residual);

        return $amount < Amounts::EPSILON ? null : new Computed($amount, 'Σ');
    }

    public function applied(CircuitState $state, int $from, int $to, float $amount, ?Computed $computed): void
    {
    }
}
