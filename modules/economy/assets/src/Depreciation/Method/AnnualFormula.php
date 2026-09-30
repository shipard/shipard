<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation\Method;

use Shipard\Module\Economy\Assets\Depreciation\Amounts;
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Assets\Depreciation\PlanMessage;
use Shipard\Module\World\Assets\TaxAmount;
use Shipard\Module\World\Assets\TaxDepreciationRules;
use Shipard\Module\World\Assets\TaxYearInput;

/**
 * Společná část ročních vzorců pravidel země: počitadlo let s uplatněným
 * odpisem, příznak zvýšené ceny a roky odpisované ze zvýšené ceny.
 * Používá ji daňový okruh i účetní metoda `as_tax`.
 *
 * @internal
 */
trait AnnualFormula
{
    private readonly TaxDepreciationRules $rules;
    private readonly string $method;
    private readonly string $rule;

    private int $years = 0;
    private bool $increased = false;
    private int $yearsSinceIncrease = 0;

    /**
     * @param bool $shortPeriod účetní rok kratší než 12 měsíců (D46); co
     *     to s odpisem udělá, rozhodují pravidla země
     */
    private function annual(
        CircuitState $state,
        float $residual,
        int $years,
        int $yearsSinceIncrease,
        bool $halfYear = false,
        bool $shortPeriod = false,
    ): TaxAmount {
        return $this->rules->annualAmount(new TaxYearInput(
            $this->method,
            $this->rule,
            $state->acquiredDate,
            $state->entryPrice,
            $residual,
            $years,
            $this->increased,
            $yearsSinceIncrease,
            $halfYear,
            $shortPeriod,
        ));
    }

    /**
     * Počáteční stav: `unitsDone` jsou roky s uplatněným odpisem; se
     * zvýšenou cenou roky odpisované ze zvýšené zůstatkové ceny.
     */
    private function startCounters(AssetEvent $event): void
    {
        if ($event->kind !== AssetEvent::KIND_OPENING) {
            return;
        }
        $this->years = (int) ($event->unitsDone ?? 0);
        $this->increased = $event->priceIncreased;
        $this->yearsSinceIncrease = $event->priceIncreased ? $this->years : 0;
    }

    /**
     * Technické zhodnocení přepíná na sazbu / koeficient pro zvýšenou cenu.
     * Před prvním uplatněným odpisem se bere jako součást pořizovací ceny
     * (majetek pořízený najednou) — dál platí sazba 1. roku.
     *
     * @return list<PlanMessage>
     */
    private function countImprovement(AssetEvent $event): array
    {
        if ($event->kind !== AssetEvent::KIND_IMPROVEMENT) {
            return [];
        }
        if (!$this->rules->allowsImprovement($this->method)) {
            return [PlanMessage::error(PlanMessage::IMPROVEMENT_ON_SCHEDULE)];
        }
        if ($this->years > 0) {
            $this->increased = true;
            $this->yearsSinceIncrease = 0;
        }
        return [];
    }

    /**
     * Oprávky počátečního stavu musí odpovídat pravidlům a počtu let (D16).
     * Se zvýšenou cenou se nekontroluje — průběh před zhodnocením neznáme.
     * Stejně tak neznáme délky minulých období: krátké zdaňovací období
     * v historii (D46) se tu projeví jako varování o nesouladu.
     *
     * @return list<PlanMessage>
     */
    private function openingCheck(CircuitState $state, AssetEvent $event): array
    {
        if ($event->priceIncreased) {
            return [];
        }
        $residual = $state->entryPrice;
        for ($year = 0; $year < $this->years && $residual > 0; $year++) {
            $residual -= $this->annual($state, $residual, $year, 0)->amount;
        }
        $expected = $state->entryPrice - $residual;

        if (abs($expected - $state->accumulated) < Amounts::EPSILON) {
            return [];
        }
        return [PlanMessage::warning(PlanMessage::OPENING_MISMATCH, [
            'accumulated' => $state->accumulated,
            'expected' => $expected,
            'units' => $this->years,
        ])];
    }

    /** @return list<PlanMessage> */
    private function ruleProblems(CircuitState $state): array
    {
        return RuleCheck::problems($this->rules, $this->method, $this->rule, $state->acquiredDate);
    }
}
