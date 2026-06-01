<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\ImportRunner;

final class AllCodebooksRunner extends ImportRunner
{
	/**
	 * Pořadí v praxi nezáleží — žádný runner Fáze 02 nečte LocalIdMap jiné
	 * Fáze 02 entity. Fixujeme ho jen kvůli stabilním logům a předvídatelnosti.
	 */
	private const SEQUENCE = [
		VatRegistrationsRunner::class,
		FiscalYearsRunner::class,
		BankAccountsRunner::class,
		CostCentersRunner::class,
		WarehousesRunner::class,
		CashDesksRunner::class,
		NumberSeriesRunner::class,
		ItemKindsRunner::class,
		UnitsRunner::class,
	];

	public function run(): bool
	{
		$continueOnError = (bool) $this->app()->arg('continue-on-error');
		$allOk = true;

		foreach (self::SEQUENCE as $runnerClass)
		{
			$shortName = $this->shortRunnerName($runnerClass);
			$this->info("");
			$this->info("=== {$shortName} ===");

			$runner = new $runnerClass($this->context);
			$ok = $runner->run();

			if (!$ok)
			{
				$allOk = false;
				if (!$continueOnError)
				{
					$this->err("Aborting all-codebooks due to failure in {$shortName} (use --continue-on-error to keep going).");
					return false;
				}
			}
		}

		$this->info("");
		if ($allOk)
			$this->ok("All codebooks imported.");
		else
			$this->warn("All codebooks finished with errors — see log above.");

		return $allOk;
	}

	private function shortRunnerName(string $fqcn): string
	{
		$pos = strrpos($fqcn, '\\');
		return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
	}
}
