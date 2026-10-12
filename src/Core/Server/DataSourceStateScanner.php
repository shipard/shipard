<?php
declare(strict_types=1);

namespace Shipard\Core\Server;

use Shipard\Core\Config\DataSourceState;

/**
 * Projde `data-sources/*` a přečte stav každého DS (`config/state.json`
 * přes DataSourceState::load — fail-closed soubory se hlásí jako corrupted).
 *
 * Server-level, ne alert check (#56 D8, R6): alert checky běží uvnitř DS
 * a v maintenance jsou cronem vypnuté — „zapomenutou" maintenance by nikdo
 * nenahlásil. Dvě ústí: `shpd-server doctor` (sekce Data source states)
 * a daily job `shpd-server ds-state-check` (warning do logu).
 */
final class DataSourceStateScanner
{
	/** Maintenance déle než tolik dní = warning (D8). Konstanta, do server.json až bude důvod. */
	public const int MAINTENANCE_WARN_DAYS = 7;

	public function __construct(
		private readonly string $dataSourcesDir,
	) {}

	/**
	 * @return list<DataSourceStateEntry> seřazené dle dsId; jen adresáře s config/main.json
	 */
	public function scan(?\DateTimeImmutable $now = null): array
	{
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

		if (!is_dir($this->dataSourcesDir)) {
			return [];
		}
		$dirs = glob($this->dataSourcesDir . '/*', GLOB_ONLYDIR) ?: [];
		sort($dirs);

		$entries = [];
		foreach ($dirs as $dir) {
			if (!is_file($dir . '/config/main.json')) {
				continue;
			}
			$state = DataSourceState::load($dir);
			$entries[] = new DataSourceStateEntry(
				basename($dir),
				$state,
				self::maintenanceDays($state, $now),
			);
		}
		return $entries;
	}

	/**
	 * @param list<DataSourceStateEntry> $entries
	 * @return list<DataSourceStateEntry> DS v maintenance déle než práh
	 */
	public static function overdueMaintenance(array $entries, int $thresholdDays = self::MAINTENANCE_WARN_DAYS): array
	{
		return array_values(array_filter(
			$entries,
			static fn(DataSourceStateEntry $e): bool => $e->isMaintenanceOverdue($thresholdDays),
		));
	}

	/**
	 * Počty per efektivní stav + `maintenance` (overlay je započtený
	 * v `suspended`, samostatně se počítá znovu — doctor obojí ukáže)
	 * + `cronOff` (přepínač periodických úloh, #115 D11; nezávislý na stavu).
	 *
	 * @param list<DataSourceStateEntry> $entries
	 * @return array<string, int>
	 */
	public static function countByState(array $entries): array
	{
		$counts = array_fill_keys(DataSourceState::STATES, 0) + ['maintenance' => 0, 'corrupted' => 0, 'cronOff' => 0];
		foreach ($entries as $e) {
			$counts[$e->effectiveState()]++;
			if ($e->state->isMaintenanceActive()) {
				$counts['maintenance']++;
			}
			if ($e->isCorrupted()) {
				$counts['corrupted']++;
			}
			if ($e->isCronOff()) {
				$counts['cronOff']++;
			}
		}
		return $counts;
	}

	private static function maintenanceDays(DataSourceState $state, \DateTimeImmutable $now): ?int
	{
		if (!$state->isMaintenanceActive()) {
			return null;
		}
		$since = $state->getMaintenanceSince();
		if ($since === null) {
			return null;
		}
		try {
			$sinceAt = new \DateTimeImmutable($since);
		} catch (\Exception) {
			return null;
		}
		$seconds = $now->getTimestamp() - $sinceAt->getTimestamp();
		return $seconds < 0 ? 0 : intdiv($seconds, 86400);
	}
}
