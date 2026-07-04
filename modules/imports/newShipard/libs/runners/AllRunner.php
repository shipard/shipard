<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\ImportRunner;
use imports\newShipard\libs\CrudClient;
use imports\newShipard\libs\HttpException;

/**
 * Orchestrátor celého importu — spustí fáze v závislostně správném pořadí
 * (číselníky → osoby → položky → doklady → bankovní výpisy → pošta) jedním
 * příkazem a na konci vypíše souhrn z context->stats.
 *
 * Závislosti: doklady potřebují osoby (partneři), položky (řádky) i číselníky
 * (number_series / vat / bank účty); bankovní výpisy potřebují bank účty
 * (ENTITY_BANK_ACCOUNT z číselníků) a jdou za doklady; pošta navazuje na doklady
 * (vazba zpráva↔doklad přes ENTITY_DOC), proto jde úplně poslední.
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
		if (!$this->assertClearingInfrastructure())
			return false;   // tvrdý abort: chyba setupu cíle, ne datového řádku

		$continueOnError = (bool) $this->app()->arg('continue-on-error');
		$allOk = true;

		$phases = [
			['All codebooks',   fn() => (new AllCodebooksRunner($this->context))->run()],
			['Persons',         fn() => (new PersonsRunner($this->context))->run()],
			['Items',           fn() => (new ItemsRunner($this->context))->run()],
			['Documents',       fn() => (new DocsRunner($this->context))->run()],
			['Bank statements', fn() => (new BankStatementsRunner($this->context))->run()],
			['Mail',            fn() => (new MailRunner($this->context))->run()],
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
			$this->summary("✓ Full import finished.");
		else
			$this->summary("! Full import finished with errors — see log above.");
		return $allOk;
	}

	/**
	 * Pre-flight: cílový DS musí mít clearing infrastrukturu (účty 261200/261300
	 * + saldo skupina unmatched_payments), kterou zakládá ds-upgrade na nové
	 * straně (ClearingInfrastructureProvisioner). Bez ní bankovní engine sype
	 * accounting_state=2 a matcher najde nula kandidátů → clearing tiše nefunguje.
	 * Guard to promění v hlasitou chybu PŘED importem prvního dokladu/transakce.
	 *
	 * Tvrdě abortuje bez ohledu na --continue-on-error: chybějící infra je chyba
	 * setupu cíle, ne chyba jednoho datového řádku. Čistý read-only GET → běží
	 * i v dry-run (nácvik má chybějící infru odhalit taky).
	 */
	private function assertClearingInfrastructure(): bool
	{
		$crud = new CrudClient($this->http());
		$missing = [];
		try
		{
			if ($crud->findOneBy('economy_accounting_accounts', 'number', '261200') === null)
				$missing[] = 'účet 261200';
			if ($crud->findOneBy('economy_accounting_accounts', 'number', '261300') === null)
				$missing[] = 'účet 261300';
			if ($crud->findOneBy('economy_accbal_balances', 'code', 'unmatched_payments') === null)
				$missing[] = 'saldo skupina unmatched_payments';
		}
		catch (HttpException $e)
		{
			// 400 = list endpoint nepodporuje filter[number]/filter[code]. To je
			// prerekvizita na NOVÉ straně (filter whitelist), check NEobcházet.
			$this->err("Clearing pre-flight selhal: generic CRUD filter vrátil HTTP {$e->statusCode}.");
			$this->err("List endpoint asi nepodporuje filter[number] / filter[code] — doplň whitelist");
			$this->err("na nové straně (prerekvizita) a import spusť znovu.");
			return false;
		}

		if ($missing !== [])
		{
			$this->err("Cíl nemá clearing infrastrukturu (chybí: " . implode(', ', $missing) . ").");
			$this->err("Spusť na CÍLOVÉM DS `bin/shpd-ds ds-upgrade` s aktuálním buildem nového");
			$this->err("Shipardu (ClearingInfrastructureProvisioner) a import spusť znovu.");
			return false;
		}

		$this->debug("Clearing infrastruktura OK (261200/261300 + unmatched_payments).");
		return true;
	}

	private function printSummary(): void
	{
		$this->summary("");
		$this->summary("==== Souhrn ====");
		foreach ($this->context->stats->byEntity() as $entity => $s)
			$this->summary(sprintf("  %-16s created=%d updated=%d skipped=%d failed=%d",
				$entity, $s['created'], $s['updated'], $s['skipped'], $s['failed']));
	}
}
