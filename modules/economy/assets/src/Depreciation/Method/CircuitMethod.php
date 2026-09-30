<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation\Method;

use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Assets\Depreciation\PlanMessage;

/**
 * Způsob výpočtu odpisů jednoho okruhu. Instance žije po dobu jednoho
 * průchodu událostmi a drží si počitadla metody; průchod (`CircuitWalker`)
 * se stará o pořadí událostí, zůstatky a kalendář.
 *
 * Měsíce jsou celá čísla dle `Months`.
 *
 * @internal
 */
interface CircuitMethod
{
    /**
     * Důvody, proč okruh nejde počítat (neplatné pravidlo…). Volá se po
     * `start()`, kdy je známé datum zařazení.
     *
     * @return list<PlanMessage>
     */
    public function problems(CircuitState $state): array;

    /** Zařazení nebo počáteční stav — nastaví počitadla. */
    public function start(CircuitState $state, AssetEvent $event): void;

    /**
     * Kontrola oprávek počátečního stavu proti pravidlům (D16).
     *
     * @return list<PlanMessage>
     */
    public function checkOpening(CircuitState $state, AssetEvent $event): array;

    /**
     * Technické zhodnocení nebo snížení hodnoty. Vstupní a zůstatkovou
     * cenu ve stavu už průchod upravil.
     *
     * @return list<PlanMessage>
     */
    public function valueChange(CircuitState $state, AssetEvent $event): array;

    /** První měsíc, za který se odpisuje. */
    public function firstMonth(CircuitState $state): int;

    /** Poslední měsíc, který odpis končící v `$month` pokrývá (roční metody: konec roku). */
    public function coveredUntil(int $month): int;

    /** Od měsíce `$month` už metoda žádný odpis nedá, i když zůstatek zbývá. */
    public function exhausted(int $month): bool;

    /** Odpis za měsíce `$from`–`$to`; `null` = v rozsahu se neodpisuje. */
    public function compute(CircuitState $state, int $from, int $to, bool $halfYear): ?Computed;

    /** Odpis (potvrzený i plánovaný) byl započten; zůstatky už průchod upravil. */
    public function applied(CircuitState $state, int $from, int $to, float $amount, ?Computed $computed): void;
}
