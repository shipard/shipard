<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation\Method;

use Shipard\Module\Economy\Assets\Depreciation\Amounts;
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Assets\Depreciation\PeriodCalendar;
use Shipard\Module\World\Assets\TaxDepreciationRules;

/**
 * Daňový okruh s roční metodou pravidel země (rovnoměrný, zrychlený).
 *
 * Odpis je za celé zdaňovací období bez ohledu na měsíc zařazení (D37)
 * a nekrátí se ani u účetního roku kratšího než 12 měsíců. Přerušený rok
 * se do počtu let nezapočítá (D34). V roce vyřazení se odpis neuplatní,
 * leda polovina u majetku evidovaného na začátku roku (D35).
 *
 * @internal
 */
final class AnnualTaxMethod implements CircuitMethod
{
    use AnnualFormula;

    public function __construct(
        TaxDepreciationRules $rules,
        string $method,
        string $rule,
        private readonly PeriodCalendar $calendar,
    ) {
        $this->rules = $rules;
        $this->method = $method;
        $this->rule = $rule;
    }

    public function problems(CircuitState $state): array
    {
        return $this->ruleProblems($state);
    }

    public function start(CircuitState $state, AssetEvent $event): void
    {
        $this->startCounters($event);
    }

    public function checkOpening(CircuitState $state, AssetEvent $event): array
    {
        return $this->openingCheck($state, $event);
    }

    public function valueChange(CircuitState $state, AssetEvent $event): array
    {
        return $this->countImprovement($event);
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
        return false;
    }

    public function compute(CircuitState $state, int $from, int $to, bool $halfYear): ?Computed
    {
        if ($state->residual < Amounts::EPSILON) {
            return null;
        }

        $year = $this->calendar->yearOfMonth($from);
        $disposedThisYear = $state->disposalMonth !== null
            && $state->disposalMonth >= $year->beginMonth()
            && $state->disposalMonth <= $year->endMonth();

        if ($disposedThisYear) {
            $evidencedAtYearStart = $state->startDate < $year->begin
                || ($state->opening && $state->startDate === $year->begin);
            if (!$halfYear || !$evidencedAtYearStart || !$this->rules->allowsHalfYearOnDisposal($this->method)) {
                return null;
            }
        }

        $amount = $this->annual($state, $state->residual, $this->years, $this->yearsSinceIncrease, $halfYear);

        return $amount->amount > 0 ? new Computed($amount->amount, $amount->formula) : null;
    }

    public function applied(CircuitState $state, int $from, int $to, float $amount, ?Computed $computed): void
    {
        $this->years++;
        if ($this->increased) {
            $this->yearsSinceIncrease++;
        }
    }
}
