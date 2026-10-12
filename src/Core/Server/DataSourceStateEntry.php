<?php
declare(strict_types=1);

namespace Shipard\Core\Server;

use Shipard\Core\Config\DataSourceState;

/**
 * Jeden řádek výstupu DataSourceStateScanner — stav jednoho DS
 * s odvozenými údaji pro doctor a daily warning (#56 D8).
 */
final readonly class DataSourceStateEntry
{
	public function __construct(
		public string $dsId,
		public DataSourceState $state,
		/** Celé dny od `maintenance.since`; null bez maintenance nebo s neparsovatelným since. */
		public ?int $maintenanceDays,
	) {}

	public function effectiveState(): string
	{
		return $this->state->getEffectiveState();
	}

	public function isCorrupted(): bool
	{
		return $this->state->isCorrupted();
	}

	/** Přepínač periodických úloh vypnutý (#115) — u fail-closed souboru false (ten se hlásí jako corrupted). */
	public function isCronOff(): bool
	{
		return !$this->state->isCronEnabled();
	}

	/** Maintenance běží déle než práh (D8) — pravděpodobně zapomenutá. */
	public function isMaintenanceOverdue(int $thresholdDays): bool
	{
		return $this->state->isMaintenanceActive()
			&& $this->maintenanceDays !== null
			&& $this->maintenanceDays > $thresholdDays;
	}
}
