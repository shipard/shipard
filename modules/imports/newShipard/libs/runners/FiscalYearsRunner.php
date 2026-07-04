<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\BaseCodebookRunner;
use imports\newShipard\libs\CrudClient;
use imports\newShipard\libs\HttpException;
use imports\newShipard\libs\LocalIdMap;

final class FiscalYearsRunner extends BaseCodebookRunner
{
	/**
	 * Mapping starý fiscalmonths.fiscalType → nový economy_codebooks_fiscal_months.period_type.
	 *   old 0 (běžné)    → new 1 (Běžné období)
	 *   old 1 (otevření) → new 0 (Opening)
	 *   old 2 (uzavření) → new 2 (Closing)
	 */
	private const FISCAL_PERIOD_TYPE_MAP = [0 => 1, 1 => 0, 2 => 2];

	private const MONTHS_TABLE = 'economy_codebooks_fiscal_months';

	/** Souhrnný stat pro fiscal_months. Naplněn během afterRowImported. */
	private array $monthStats = ['created' => 0, 'skipped' => 0, 'failed' => 0];

	protected function entityType(): string  { return LocalIdMap::ENTITY_FISCAL_YEAR; }
	protected function targetTable(): string { return 'economy_codebooks_fiscal_years'; }
	protected function entityLabel(): string { return 'fiscal-year'; }

	protected function sourceQuery(): array
	{
		return [
			'SELECT [ndx], [fullName], [mark], [start], [end], [currency], [docState]'
			. ' FROM [e10doc_base_fiscalyears]'
			. ' WHERE [docState] != %i', 9800,
			' ORDER BY [start]',
		];
	}

	protected function mapRow(array $oldRow): array
	{
		$name = (string) ($oldRow['fullName'] ?? '');
		if (mb_strlen($name) > 20)
			$name = mb_substr($name, 0, 20);

		// doc_number_prefix NOT NULL, len 10 — staré `mark` má len 2, fallback na prvních
		// znaků fullName, případně 'FY' + ndx pokud i to selže.
		$mark = trim((string) ($oldRow['mark'] ?? ''));
		if ($mark === '')
		{
			$mark = mb_substr($name, 0, 2);
			if ($mark === '')
				$mark = 'FY' . (int) $oldRow['ndx'];
		}
		if (mb_strlen($mark) > 10)
			$mark = mb_substr($mark, 0, 10);

		$currency = strtolower(trim((string) ($oldRow['currency'] ?? '')));
		if ($currency === '')
			$currency = 'czk';

		return [
			'name'              => $name,
			'doc_number_prefix' => $mark,
			'date_begin'        => $this->dateToString($oldRow['start'] ?? null),
			'date_end'          => $this->dateToString($oldRow['end'] ?? null),
			'currency'          => $currency,
			'locked'            => 0,
		];
	}

	/**
	 * Po vytvoření fiskálního roku spustíme import jeho měsíců.
	 */
	protected function afterRowImported(array $oldRow, int $newId, CrudClient $crud): void
	{
		$oldYearNdx = (int) $oldRow['ndx'];
		$this->importMonths($oldYearNdx, $newId, $crud);
	}

	public function run(): bool
	{
		$ok = parent::run();

		$this->summary(sprintf(
			'  fiscal-months: created=%d, skipped=%d, failed=%d',
			$this->monthStats['created'], $this->monthStats['skipped'], $this->monthStats['failed'],
		));

		return $ok;   // chyby měsíců → exit code 2 přes Logger::errorCount()
	}

	/**
	 * Načte starou fiscalmonths pro daný rok a založí jejich obraz v novém
	 * Shipardu. Idempotence per měsíc přes LocalIdMap(fiscalMonth, oldNdx).
	 */
	private function importMonths(int $oldYearNdx, int $newYearId, CrudClient $crud): void
	{
		$rows = $this->db()->query(
			'SELECT [ndx], [fiscalType], [calendarYear], [calendarMonth], [start], [end]'
			. ' FROM [e10doc_base_fiscalmonths]'
			. ' WHERE [fiscalYear] = %i', $oldYearNdx,
			' ORDER BY [start]',
		)->fetchAll();

		foreach ($rows as $row)
		{
			$monthOld = is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : (array) $row;
			$oldMonthNdx = (int) $monthOld['ndx'];

			$existing = $this->idMap()->lookup(LocalIdMap::ENTITY_FISCAL_MONTH, $oldMonthNdx);
			if ($existing !== null)
			{
				$this->monthStats['skipped']++;
				$this->debug("  [fiscal-month] {$oldMonthNdx} skipped (already-imported, new id={$existing})");
				continue;
			}

			$periodType = self::FISCAL_PERIOD_TYPE_MAP[(int) ($monthOld['fiscalType'] ?? 0)] ?? 1;

			$payload = [
				'fiscal_year'    => $newYearId,
				'date_begin'     => $this->dateToString($monthOld['start'] ?? null),
				'date_end'       => $this->dateToString($monthOld['end'] ?? null),
				'period_type'    => $periodType,
				'calendar_year'  => (int) ($monthOld['calendarYear'] ?? 0),
				'calendar_month' => (int) ($monthOld['calendarMonth'] ?? 0),
			];
			// fiscal_months nemá docState ve schema (nov_shipard:economy_codebooks_fiscal_months.jsonc).

			if ($this->isDryRun())
			{
				$this->debug("  DRY-RUN: would POST /api/v1/" . self::MONTHS_TABLE
					. " payload=" . json_encode($payload, JSON_UNESCAPED_UNICODE));
				$this->monthStats['skipped']++;
				continue;
			}

			try
			{
				$newMonthId = $crud->create(self::MONTHS_TABLE, $payload);
				$this->idMap()->record(LocalIdMap::ENTITY_FISCAL_MONTH, $oldMonthNdx, $newMonthId);
				$this->monthStats['created']++;
				$this->debug(sprintf("  [fiscal-month] %d → %d  %d-%02d",
					$oldMonthNdx, $newMonthId,
					$payload['calendar_year'], $payload['calendar_month']));
			}
			catch (HttpException $e)
			{
				$this->monthStats['failed']++;
				$this->err("  Failed fiscal-month (old ndx={$oldMonthNdx}, parent year ndx={$oldYearNdx}): " . $e->getMessage());
				if (!$this->isContinueOnError())
					throw $e; // bubble up — base::run() ho převezme přes HttpException catch
			}
		}
	}
}
