<?php

namespace imports\newShipard\libs;

class ImportApp
{
	/** @var \Shipard\CLI\Application */
	private $app;

	private ?ImportConfig $config = null;
	private ?HttpClient $httpClient = null;
	private ?LocalIdMap $idMap = null;
	private ?Logger $logger = null;
	private ?ImportStats $stats = null;

	public function __construct(\Shipard\CLI\Application $app)
	{
		$this->app = $app;
	}

	public function run(): bool
	{
		// Verify we're running inside a Shipard data source directory.
		if (!is_file(__APP_DIR__ . '/config/_server_channelInfo.json'))
		{
			echo "ERROR: Not a Shipard data source directory (missing 'config/_server_channelInfo.json').\n";
			echo "Run this command from the DS root, e.g.:\n";
			echo "  cd /var/lib/shipard/data-sources/<dsid>\n";
			echo "  shpd-app cli-action --action=imports.newShipard/import <subcommand>\n";
			return false;
		}

		$subcommand = $this->app->command(1);
		if ($subcommand === '' || $subcommand === false)
			return $this->printUsage();

		try
		{
			$this->config = ImportConfig::load(__APP_DIR__);
		}
		catch (ImportException $e)
		{
			echo "ERROR: " . $e->getMessage() . "\n";
			return false;
		}

		$verbose = $this->config->verbose()
			|| (bool) $this->app->arg('verbose')
			|| (bool) $this->app->arg('v');

		// --no-throttle CLI flag přepíše config throttleMs na 0 (pro testing).
		$throttleMs = (bool) $this->app->arg('no-throttle')
			? 0
			: $this->config->throttleMs();

		$this->httpClient = new HttpClient(
			baseUrl:      $this->config->targetBaseUrl(),
			apiKey:       $this->config->targetApiKey(),
			timeout:      $this->config->timeout(),
			verbose:      $verbose,
			throttleMs:   $throttleMs,
			maxRetries:   $this->config->maxRetries(),
			retryDelayMs: $this->config->retryDelayMs(),
		);

		$sqlitePath = __APP_DIR__ . '/import-newShipard.sqlite';

		// reset: subkomand 'reset' smaže mapu a skončí; flag --reset smaže a
		// pokračuje zvoleným subkomandem (čistá mapa pro daný běh).
		$wantsReset = ($subcommand === 'reset') || (bool) $this->app->arg('reset');
		if ($wantsReset)
		{
			// WAL/SHM je nutné smazat taky (SQLite v WAL módu), jinak zůstanou zbytky.
			foreach ([$sqlitePath, $sqlitePath . '-wal', $sqlitePath . '-shm'] as $f)
				if (is_file($f)) @unlink($f);
			echo "! Local id map reset (deleted {$sqlitePath}).\n";
			echo "! POZOR: idempotence je pryč — re-import do neprázdného cílového DS\n";
			echo "!        může vytvořit duplikáty (business-key match nemusí vše zachytit).\n";

			if ($subcommand === 'reset')
				return true;   // jen reset, nic dál (Logger se nevytváří)
		}

		$this->idMap = new LocalIdMap($sqlitePath);   // čerstvá mapa
		$this->logger = new Logger(__APP_DIR__ . '/log/import-' . date('Ymd-His') . '.log');
		$this->stats = new ImportStats();

		$ok = $this->dispatch($subcommand);
		$this->logger->close();
		return $ok;
	}

	private function dispatch(string $subcommand): bool
	{
		switch ($subcommand)
		{
			case 'status':            return (new runners\StatusRunner($this->context()))->run();

			// Phase 02 — codebooks
			case 'vat-registrations': return (new runners\VatRegistrationsRunner($this->context()))->run();
			case 'fiscal-years':      return (new runners\FiscalYearsRunner($this->context()))->run();
			case 'bank-accounts':     return (new runners\BankAccountsRunner($this->context()))->run();
			case 'cost-centers':      return (new runners\CostCentersRunner($this->context()))->run();
			case 'warehouses':        return (new runners\WarehousesRunner($this->context()))->run();
			case 'cash-desks':        return (new runners\CashDesksRunner($this->context()))->run();
			case 'number-series':     return (new runners\NumberSeriesRunner($this->context()))->run();
			case 'item-kinds':        return (new runners\ItemKindsRunner($this->context()))->run();
			case 'units':             return (new runners\UnitsRunner($this->context()))->run();
			case 'all-codebooks':     return (new runners\AllCodebooksRunner($this->context()))->run();

			// Phase 03 — persons
			case 'persons':           return (new runners\PersonsRunner($this->context()))->run();

			// Phase 04 — items
			case 'items':             return (new runners\ItemsRunner($this->context()))->run();

			// Phase 05 — docs
			case 'docs':              return (new runners\DocsRunner($this->context()))->run();

			// Phase 06 — orchestrator ('reset' se odbaví v run() před LocalIdMap,
			// sem se nedostane).
			case 'all':               return (new runners\AllRunner($this->context()))->run();
		}

		echo "Unknown subcommand: '{$subcommand}'\n\n";
		return $this->printUsage();
	}

	private function context(): ImportContext
	{
		return new ImportContext(
			$this->app,
			$this->config,
			$this->httpClient,
			$this->idMap,
			$this->logger,
			$this->stats,
		);
	}

	private function printUsage(): bool
	{
		echo "Usage: shpd-app cli-action --action=imports.newShipard/import <subcommand> [options]\n";
		echo "\n";
		echo "Subcommands:\n";
		echo "  status              Sanity check — connection, config, local map.\n";
		echo "\n";
		echo "  Phase 02 — codebooks:\n";
		echo "    vat-registrations VAT registrations (taxRegs WHERE taxType='vat').\n";
		echo "    fiscal-years      Fiscal years + embedded fiscal months.\n";
		echo "    bank-accounts     Own bank accounts.\n";
		echo "    cost-centers      Cost centers (centres).\n";
		echo "    warehouses        Warehouses.\n";
		echo "    cash-desks        Cash desks (cashboxes).\n";
		echo "    number-series     Document number series (docnumbers).\n";
		echo "    item-kinds        Item kinds (itemtypes).\n";
		echo "    units             Units of measure (witems units).\n";
		echo "    all-codebooks     All of the above in dependency order.\n";
		echo "\n";
		echo "  Phase 03 — persons:\n";
		echo "    persons           Persons (people + companies) via exchange flow.\n";
		echo "\n";
		echo "  Phase 04 — items:\n";
		echo "    items             Items (goods, services) via exchange flow.\n";
		echo "\n";
		echo "  Phase 05 — docs:\n";
		echo "    docs              Documents (invoices invni/invno) via exchange flow.\n";
		echo "                      Requires a flagged own company (is_own=1) in target.\n";
		echo "\n";
		echo "Common options:\n";
		echo "  --verbose, -v        More verbose output (HTTP + per-row debug).\n";
		echo "  --dry-run            Do not perform writes against the target.\n";
		echo "  --continue-on-error  Skip failed rows instead of aborting the runner.\n";
		echo "  --limit=N            Process only the first N source rows (exchange runners only).\n";
		echo "  --no-throttle        Disable client-side throttling between requests (for testing).\n";
		echo "  --dump-payload       Print the canonical JSON sent to the exchange apply\n";
		echo "                       (exchange runners: persons/items/docs). Failed rows dump\n";
		echo "                       payload + response body automatically.\n";
		echo "  --from=YYYY-MM-DD    Filter docs by accounting date (>=). 'docs' only.\n";
		echo "  --to=YYYY-MM-DD      Filter docs by accounting date (<=). 'docs' only.\n";
		echo "  --target-state=10    Import docs as draft (10) instead of confirmed (20). 'docs' only.\n";
		echo "\n";
		return true;
	}
}
