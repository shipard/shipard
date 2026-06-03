<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\ImportRunner;

/**
 * Orchestrátor celého importu — spustí fáze v závislostně správném pořadí
 * (číselníky → osoby → položky → doklady → pošta) jedním příkazem a na konci
 * vypíše souhrn z context->stats.
 *
 * Závislosti: doklady potřebují osoby (partneři), položky (řádky) i číselníky
 * (number_series / vat / bank účty); pošta navazuje na doklady (vazba
 * zpráva↔doklad přes ENTITY_DOC), proto jde úplně poslední.
 *
 * --continue-on-error: pokračuje i po selhání fáze (s vyšší chybovostí
 * navazujících fází); jinak po prvním selhání abortuje a vypíše souhrn.
 *
 * --from/--to: žádná speciální logika — argumenty jsou globální. Čte je
 * DocsRunner::sourceQuery() (dateAccounting) i MailRunner (dateIncoming).
 * `all --from=… --to=…` tedy naimportuje všechny číselníky/osoby/položky, ale
 * jen doklady a poštu daného období.
 */
final class AllRunner extends ImportRunner
{
	public function run(): bool
	{
		$continueOnError = (bool) $this->app()->arg('continue-on-error');
		$allOk = true;

		$phases = [
			['All codebooks', fn() => (new AllCodebooksRunner($this->context))->run()],
			['Persons',       fn() => (new PersonsRunner($this->context))->run()],
			['Items',         fn() => (new ItemsRunner($this->context))->run()],
			['Documents',     fn() => (new DocsRunner($this->context))->run()],
			['Mail',          fn() => (new MailRunner($this->context))->run()],
		];

		foreach ($phases as [$label, $fn])
		{
			$this->info("");
			$this->info("######## {$label} ########");
			$ok = $fn();
			if (!$ok)
			{
				$allOk = false;
				if (!$continueOnError)
				{
					$this->err("Aborting `all` at phase '{$label}' (use --continue-on-error to keep going).");
					$this->printSummary();
					return false;
				}
			}
		}

		$this->printSummary();
		if ($allOk)
			$this->ok("Full import finished.");
		else
			$this->warn("Full import finished with errors — see log above.");
		return $allOk;
	}

	private function printSummary(): void
	{
		$this->info("");
		$this->info("==== Souhrn ====");
		foreach ($this->context->stats->byEntity() as $entity => $s)
			$this->info(sprintf("  %-16s created=%d updated=%d skipped=%d failed=%d",
				$entity, $s['created'], $s['updated'], $s['skipped'], $s['failed']));
	}
}
