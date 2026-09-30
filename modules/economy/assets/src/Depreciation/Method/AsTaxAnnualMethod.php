<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation\Method;

use Shipard\Module\Economy\Assets\Depreciation\Amounts;
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Assets\Depreciation\Months;
use Shipard\Module\Economy\Assets\Depreciation\PeriodCalendar;
use Shipard\Module\World\Assets\TaxDepreciationRules;

/**
 * Účetní metoda `as_tax` nad roční daňovou metodou: roční vzorec pravidel
 * země nad účetním zůstatkem a vlastním počitadlem let, bez daňového
 * přerušení.
 *
 * Roční částka se rozpouští do měsíců, kdy je majetek v užívání — od
 * měsíce po zařazení (D37), v roce vyřazení do měsíce vyřazení. Každé
 * období dostane podíl ze zbytku roční částky, takže poslední měsíc roku
 * částku dorovná (D45) a roční součet je stejný při měsíční i roční
 * četnosti. Majetek zařazený v posledním měsíci roku v něm žádný měsíc
 * užívání nemá — roční odpis jde celý do tohoto měsíce.
 *
 * @internal
 */
final class AsTaxAnnualMethod implements CircuitMethod
{
    use AnnualFormula;

    /** První měsíc v užívání. */
    private int $useFrom = 0;
    private int $firstMonth = 0;

    /** Účetní rok, ke kterému se vztahují `allocated` a `hasDepreciation`. */
    private ?int $yearBegin = null;
    private float $allocated = 0.0;
    private bool $hasDepreciation = false;

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

        if ($event->kind === AssetEvent::KIND_OPENING) {
            $this->useFrom = Months::of($event->date);
            $this->firstMonth = $this->useFrom;
            return;
        }
        $this->useFrom = Months::of($event->date) + 1;
        $this->firstMonth = min($this->useFrom, $this->calendar->yearOf($event->date)->endMonth());
    }

    public function checkOpening(CircuitState $state, AssetEvent $event): array
    {
        return $this->openingCheck($state, $event);
    }

    public function valueChange(CircuitState $state, AssetEvent $event): array
    {
        $this->enterYear($this->calendar->yearOf($event->date)->beginMonth());

        return $this->countImprovement($event);
    }

    public function firstMonth(CircuitState $state): int
    {
        return $this->firstMonth;
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
        $year = $this->calendar->yearOfMonth($to);
        $sameYear = $year->beginMonth() === $this->yearBegin;
        $carry = !$sameYear && $this->hasDepreciation ? 1 : 0;
        $allocated = $sameYear ? $this->allocated : 0.0;

        // Roční vzorec počítá ze zůstatku na začátku roku.
        $yearResidual = $state->residual + $allocated;
        if ($yearResidual < Amounts::EPSILON) {
            return null;
        }
        $annual = $this->annual(
            $state,
            $yearResidual,
            $this->years + $carry,
            $this->yearsSinceIncrease + ($this->increased ? $carry : 0),
            // Krátký účetní rok (D46) krátí i účetní odpis — jinak by se
            // `as_tax` od daňového okruhu rozešel.
            shortPeriod: $year->months() < 12,
        );

        // Měsíce v užívání: celý rok, první rok od měsíce po zařazení.
        $fullTo = $year->endMonth();
        $useFrom = max($year->beginMonth(), $this->useFrom);
        if ($useFrom > $fullTo) {
            if ($this->firstMonth < $year->beginMonth() || $this->firstMonth > $fullTo) {
                return null;
            }
            // Zařazeno v posledním měsíci roku — roční odpis jde do něj.
            $useFrom = $fullTo;
        }
        $useTo = $state->disposalMonth !== null ? min($fullTo, $state->disposalMonth) : $fullTo;
        $inUse = $useTo - $useFrom + 1;
        $inUseFull = $fullTo - $useFrom + 1;
        if ($inUse <= 0) {
            return null;
        }

        $target = $annual->amount;
        $formula = $annual->formula;
        if ($inUse < $inUseFull) {
            $target = Amounts::ceil($annual->amount * $inUse / $inUseFull);
            $formula = "({$formula}) × {$inUse} / {$inUseFull}";
        }

        $first = max($from, $useFrom);
        $months = min($to, $useTo) - $first + 1;
        $remaining = $useTo - $first + 1;
        $rest = $target - $allocated;
        if ($months <= 0 || $rest < Amounts::EPSILON) {
            return null;
        }

        if ($allocated > 0) {
            $formula .= ' − ' . Amounts::money($allocated);
        }
        $amount = $rest;
        if ($months < $remaining) {
            $amount = Amounts::ceil($rest * $months / $remaining);
            $formula = "({$formula}) × {$months} / {$remaining}";
        }
        $amount = min($amount, $state->residual);

        return $amount > 0 ? new Computed($amount, $formula) : null;
    }

    public function applied(CircuitState $state, int $from, int $to, float $amount, ?Computed $computed): void
    {
        $this->enterYear($this->calendar->yearOfMonth($to)->beginMonth());
        $this->allocated += $amount;
        $this->hasDepreciation = true;
    }

    /** Přechod do dalšího účetního roku: rok s odpisem se započte do počtu let. */
    private function enterYear(int $yearBegin): void
    {
        if ($this->yearBegin === $yearBegin) {
            return;
        }
        if ($this->hasDepreciation) {
            $this->years++;
            if ($this->increased) {
                $this->yearsSinceIncrease++;
            }
        }
        $this->yearBegin = $yearBegin;
        $this->allocated = 0.0;
        $this->hasDepreciation = false;
    }
}
