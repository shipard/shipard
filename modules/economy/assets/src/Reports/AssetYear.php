<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Reports;

use Shipard\Module\Economy\Assets\Depreciation\Plan;

/**
 * Dlouhodobá karta v evidenci v období: řádek karty (s připojenými názvy
 * skupiny a typu), plány obou okruhů a jejich pohled za období.
 */
final readonly class AssetYear
{
    /** @param array<string, mixed> $card */
    public function __construct(
        public array $card,
        public Plan $taxPlan,
        public Plan $accPlan,
        public CircuitYear $tax,
        public CircuitYear $acc,
    ) {
    }

    public function id(): int
    {
        return (int) $this->card['id'];
    }

    /** Datum zařazení / počátečního stavu — dřívější z obou okruhů. */
    public function startDate(): ?string
    {
        $dates = array_filter([$this->tax->startDate, $this->acc->startDate]);

        return $dates === [] ? null : min($dates);
    }

    public function disposalDate(): ?string
    {
        return $this->acc->disposalDate ?? $this->tax->disposalDate;
    }

    /** V období je odpis některého okruhu zatím jen plán. */
    public function planned(): bool
    {
        return $this->tax->planned || $this->acc->planned;
    }
}
