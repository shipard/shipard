<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\ImportRunner;
use imports\newShipard\libs\CrudClient;
use imports\newShipard\libs\HttpException;

/**
 * Orchestrátor celého importu — spustí fáze v závislostně správném pořadí
 * (parametry vrstvy C → číselníky → nastavení saldokont → osoby → položky →
 * doklady → bankovní výpisy → pošta → spisovna → párování) jedním příkazem
 * a na konci vypíše souhrn z context->stats + agregát párování.
 *
 * Závislosti: parametry vrstvy C (settings) jdou úplně první — mají na cíli
 * existovat od začátku, aby se runtime čtenáři homeCurrency/vatAgenda chovali
 * konzistentně už během importu a parametrizace přežila i přerušený běh;
 * nastavení saldokont jde hned za číselníky (účty už existují —
 * importuje je AllCodebooksRunner); doklady potřebují osoby (partneři), položky
 * (řádky) i číselníky (number_series / vat / bank účty); bankovní výpisy
 * potřebují bank účty (ENTITY_BANK_ACCOUNT z číselníků) a jdou za doklady; pošta
 * navazuje na doklady (vazba zpráva↔doklad přes ENTITY_DOC), proto jde za nimi;
 * spisovna (registry) je nezávislá a jde jako poslední datová fáze. Úplně
 * nakonec běží „na dálku" matcher (fáze Match, POST
 * /_accbal/match) — spáruje naimportované úhrady na cílovém DS.
 *
 * --continue-on-error: pokračuje i po selhání fáze (s vyšší chybovostí
 * navazujících fází); jinak po prvním selhání abortuje a vypíše souhrn.
 *
 * --skip-accbal-settings / --skip-match: vynechají příslušnou fázi (info hláška).
 * Fáze Match navíc nikdy nemění návratovou hodnotu `all` — její selhání je jen
 * warn s ruční fallback instrukcí (importovaná data jsou v pořádku).
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
			['Layer C settings', fn() => (new SettingsRunner($this->context))->run()],
			['All codebooks',   fn() => (new AllCodebooksRunner($this->context))->run()],
			['Accbal settings', fn() => $this->runAccbalSettingsPhase()],
			['Persons',         fn() => (new PersonsRunner($this->context))->run()],
			['Items',           fn() => (new ItemsRunner($this->context))->run()],
			['Documents',       fn() => (new DocsRunner($this->context))->run()],
			['Bank statements', fn() => (new BankStatementsRunner($this->context))->run()],
			['Mail',            fn() => (new MailRunner($this->context))->run()],
			['Registry',        fn() => (new RegistryRunner($this->context))->run()],
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

		// Závěrečná fáze párování — běží i po chybových řádcích předchozích fází
		// (spáruje, co se naimportovalo); po tvrdém abortu už sem tok nedojde.
		$this->runMatchPhase();

		$this->printSummary();
		// $allOk = žádná fáze neabortovala; chyby řádků (failed s
		// --continue-on-error) fáze neshodí — vidí je jen Logger.
		if ($allOk && $this->logger()->errorCount() === 0)
			$this->summary("✓ Full import finished.");
		else
			$this->summary("! Full import finished with errors — see log above.");
		return $allOk;
	}

	/**
	 * Fáze accbal-settings v `all`: nastavení saldokont (skupiny + účty). Vstup
	 * přes veřejné runImport() (bez simulace --import flagu); idempotenci per kód
	 * skupiny řeší runner sám. Opt-out --skip-accbal-settings.
	 */
	private function runAccbalSettingsPhase(): bool
	{
		if ((bool) $this->app()->arg('skip-accbal-settings'))
		{
			$this->info("Fáze Accbal settings přeskočena (--skip-accbal-settings).");
			return true;
		}
		return (new AccbalSettingsRunner($this->context))->runImport();
	}

	/**
	 * Závěrečná fáze Match — vzdálené spuštění matcheru přes POST /_accbal/match
	 * (jedno HTTP volání, ne runner). Spáruje naimportované úhrady na cílovém DS.
	 *
	 * Dry-run: posílá dryRun:true → endpoint vrátí read-only plán (nácvik odhalí
	 * problémy, konzistentní s filozofií clearing guardu). Per-call timeout 600 s
	 * (matcher běží ověřeně v nízkých desítkách sekund → velká rezerva).
	 *
	 * Selhání endpointu = warn + fallback instrukce (ruční `shpd-ds accbal-match
	 * --all` na cíli), NIKDY neshodí `all` — importovaná data jsou v pořádku.
	 * Přeskočí se jen s --skip-match.
	 */
	private function runMatchPhase(): void
	{
		$this->info("");
		$this->info("######## Match ########");

		if ((bool) $this->app()->arg('skip-match'))
		{
			$this->info("Fáze Match přeskočena (--skip-match).");
			return;
		}

		$dryRun = $this->isDryRun();
		try
		{
			$resp = $this->http()->post('/_accbal/match', ['scope' => 'all', 'dryRun' => $dryRun], 600);
		}
		catch (HttpException $e)
		{
			$this->warn("Vzdálené párování (POST /_accbal/match) selhalo: {$e->getMessage()}");
			$this->warn("Importovaná data jsou v pořádku — spusť párování ručně na CÍLOVÉM serveru:");
			$this->warn("  shpd-ds accbal-match --all");
			return;   // fáze Match nikdy nemění návratovou hodnotu `all`
		}

		$this->printMatchSummary($dryRun, is_array($resp['data'] ?? null) ? $resp['data'] : []);
	}

	/**
	 * Souhrn párování z agregátu endpointu (žádné per-result řádky). V dry-run
	 * je plán ve `planned` (allocated=0), v ostrém běhu ve `allocated`.
	 *
	 * @param array<string, mixed> $d  data z odpovědi /_accbal/match
	 */
	private function printMatchSummary(bool $dryRun, array $d): void
	{
		$candidates = (int) ($d['candidates'] ?? 0);
		$matched    = (int) ($dryRun ? ($d['planned'] ?? 0) : ($d['allocated'] ?? 0));
		$routed     = (int) ($d['routedUnallocated'] ?? 0);
		$amount     = (float) ($d['matchedAmount'] ?? 0);
		$skipped    = is_array($d['skipped'] ?? null) ? $d['skipped'] : [];

		$this->summary("");
		$this->summary(sprintf(
			"Match%s: kandidátů=%d, %s=%d, směrováno bez alokace=%d, Σ částka=%.2f",
			$dryRun ? " (dry-run plán)" : '',
			$candidates,
			$dryRun ? 'k spárování' : 'spárováno',
			$matched, $routed, $amount,
		));

		if ($skipped !== [])
		{
			$parts = [];
			foreach ($skipped as $reason => $n)
				$parts[] = "{$reason}={$n}";
			$this->summary("  skipped: " . implode(', ', $parts));
		}
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
