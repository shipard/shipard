<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation;

use Shipard\Module\Economy\Assets\Depreciation\Method\AccTimeMethod;
use Shipard\Module\Economy\Assets\Depreciation\Method\AnnualTaxMethod;
use Shipard\Module\Economy\Assets\Depreciation\Method\AsTaxAnnualMethod;
use Shipard\Module\Economy\Assets\Depreciation\Method\CircuitMethod;
use Shipard\Module\Economy\Assets\Depreciation\Method\FromAccountingMethod;
use Shipard\Module\Economy\Assets\Depreciation\Method\MonthlyRulesMethod;
use Shipard\Module\Economy\Assets\Depreciation\Method\NoDepreciationMethod;
use Shipard\Module\World\Assets\TaxDepreciationRules;

/**
 * Odpisový engine (docs/assets.md D6, D32): z nastavení karty, událostí,
 * pravidel země a účetních období spočítá plán daňových a účetních odpisů.
 *
 * Čistá funkce — žádná databáze, žádné UI, žádný stav mezi voláními.
 * O státu ví jen přes `TaxDepreciationRules`; účetní okruh je obecný.
 * Nepotvrzené události (koncepty) se do plánu nepočítají.
 */
final class DepreciationPlanner
{
    public const CIRCUIT_TAX = 'tax';
    public const CIRCUIT_ACC = 'acc';

    /**
     * @param list<AssetEvent> $events události karty v libovolném pořadí
     * @param PeriodCalendar $tax zdaňovací období = účetní roky
     * @param PeriodCalendar $acc účetní roky nebo měsíce podle četnosti (D12)
     * @param string $asOf datum, ke kterému se počítá „letošní odpis“ souhrnu
     * @return array{tax: Plan, acc: Plan}
     */
    public function plan(
        DepreciationSettings $settings,
        array $events,
        TaxDepreciationRules $rules,
        PeriodCalendar $tax,
        PeriodCalendar $acc,
        string $asOf,
    ): array {
        $taxKind = $settings->taxMethod === null
            ? TaxDepreciationRules::KIND_NONE
            : $rules->methodKind($settings->taxMethod);

        // Účetní okruh první — daňová metoda „podle účetních odpisů“ z něj čte.
        [$accMethod, $accMessages] = $this->accMethod($settings, $rules, $taxKind, $acc);
        $accPlan = (new CircuitWalker(
            self::CIRCUIT_ACC,
            $accMethod,
            $acc,
            Amounts::isWhole(...),
            $accMessages,
            $accMessages !== [],
        ))->walk($events, $asOf);

        [$taxMethod, $taxMessages] = $this->taxMethod($settings, $rules, $taxKind, $tax, $accPlan);
        $taxPlan = (new CircuitWalker(
            self::CIRCUIT_TAX,
            $taxMethod,
            $tax,
            static fn(float $amount): bool => abs($rules->round($amount) - $amount) < Amounts::EPSILON,
            $taxMessages,
            $taxMessages !== [],
        ))->walk($events, $asOf);

        return [self::CIRCUIT_TAX => $taxPlan, self::CIRCUIT_ACC => $accPlan];
    }

    /** @return array{CircuitMethod, list<PlanMessage>} */
    private function accMethod(
        DepreciationSettings $settings,
        TaxDepreciationRules $rules,
        ?string $taxKind,
        PeriodCalendar $calendar,
    ): array {
        if ($settings->accMethod === null) {
            return [new NoDepreciationMethod(), []];
        }
        if ($settings->accMethod === DepreciationSettings::ACC_TIME) {
            return [new AccTimeMethod((int) $settings->accMonths), []];
        }

        // `as_tax`: vzorec daňové metody nad účetním zůstatkem.
        $method = (string) $settings->taxMethod;
        $rule = (string) $settings->taxRule;

        return match ($taxKind) {
            TaxDepreciationRules::KIND_ANNUAL => [new AsTaxAnnualMethod($rules, $method, $rule, $calendar), []],
            TaxDepreciationRules::KIND_MONTHLY => [new MonthlyRulesMethod($rules, $method, $rule), []],
            // Daňová metoda bez vlastního vzorce — není co kopírovat.
            default => [new NoDepreciationMethod(), [$this->invalid('asTaxWithoutFormula')]],
        };
    }

    /** @return array{CircuitMethod, list<PlanMessage>} */
    private function taxMethod(
        DepreciationSettings $settings,
        TaxDepreciationRules $rules,
        ?string $taxKind,
        PeriodCalendar $calendar,
        Plan $accPlan,
    ): array {
        $method = (string) $settings->taxMethod;
        $rule = (string) $settings->taxRule;

        return match ($taxKind) {
            TaxDepreciationRules::KIND_ANNUAL => [new AnnualTaxMethod($rules, $method, $rule, $calendar), []],
            TaxDepreciationRules::KIND_MONTHLY => [new MonthlyRulesMethod($rules, $method, $rule), []],
            TaxDepreciationRules::KIND_ACCOUNTING => $settings->accMethod === DepreciationSettings::ACC_TIME
                ? [new FromAccountingMethod($accPlan, $calendar), []]
                : [new NoDepreciationMethod(), [$this->invalid('accountingWithoutAccMethod')]],
            TaxDepreciationRules::KIND_NONE => [new NoDepreciationMethod(), []],
            default => [new NoDepreciationMethod(), [$this->invalid('unknownTaxMethod')]],
        };
    }

    private function invalid(string $reason): PlanMessage
    {
        return PlanMessage::error(PlanMessage::SETTINGS_INVALID, ['reason' => $reason]);
    }
}
