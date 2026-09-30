<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation\Method;

use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Assets\Depreciation\Months;

/**
 * Okruh bez odpisů: metoda `none`, neodepisovaný majetek nebo nastavení,
 * ze kterého výpočet nejde sestavit. Plán tvoří jen vývoj hodnoty.
 *
 * @internal
 */
final class NoDepreciationMethod implements CircuitMethod
{
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
        return Months::of($state->startDate);
    }

    public function coveredUntil(int $month): int
    {
        return $month;
    }

    public function exhausted(int $month): bool
    {
        return true;
    }

    public function compute(CircuitState $state, int $from, int $to, bool $halfYear): ?Computed
    {
        return null;
    }

    public function applied(CircuitState $state, int $from, int $to, float $amount, ?Computed $computed): void
    {
    }
}
