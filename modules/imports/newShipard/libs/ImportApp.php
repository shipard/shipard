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

	/**
	 * CLI vrstva (shpd-app cliAction) návratovou hodnotu zahazuje a proces
	 * končí vždy 0 — exit code proto nastavujeme sami přes exit():
	 *   0 = čistý běh, 1 = tvrdý fail/abort, 2 = doběhlo, ale padly err úrovně
	 *   (zdroj pravdy = Logger::errorCount()).
	 */
	public function run(): bool
	{
		// Verify we're running inside a Shipard data source directory.
		if (!is_file(__APP_DIR__ . '/config/_server_channelInfo.json'))
		{
			echo "ERROR: Not a Shipard data source directory (missing 'config/_server_channelInfo.json').\n";
			echo "Run this command from the DS root, e.g.:\n";
			echo "  cd /var/lib/shipard/data-sources/<dsid>\n";
			echo "  shpd-app cli-action --action=imports.newShipard/import <subcommand>\n";
			exit(1);
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
			exit(1);
		}

		$verbose = $this->config->verbose()
			|| (bool) $this->app->arg('verbose')
			|| (bool) $this->app->arg('v');

		// --quiet filtruje jen konzoli (warn/err/summary + progress ticky);
		// soubory zůstávají plné. CLI --quiet přebíjí config verbose; konflikt
		// hlásíme jen mezi CLI flagy.
		$quiet = (bool) $this->app->arg('quiet');
		if ($quiet && ((bool) $this->app->arg('verbose') || (bool) $this->app->arg('v')))
		{
			echo "ERROR: --quiet and --verbose are mutually exclusive.\n";
			exit(1);
		}
		if ($quiet)
			$verbose = false;   // CLI --quiet přebíjí i config verbose (vč. HTTP trace)

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

			// Úklid logů minulých běhů — jen soubory tohoto modulu (log/ sdílí
			// i dibi profiler, nikdy nemazat celý adresář). Běží před konstrukcí
			// Loggeru, takže log právě spouštěného běhu ještě neexistuje.
			// Bezpodmínečně i při --dry-run — konzistentně s mazáním mapy výše.
			$deletedLogs = 0;
			foreach (array_merge(
				glob(__APP_DIR__ . '/log/import-*.log') ?: [],
				glob(__APP_DIR__ . '/log/import-*.err') ?: [],
			) as $f)
				if (@unlink($f)) $deletedLogs++;
			if ($deletedLogs > 0)
				echo "! Deleted {$deletedLogs} old import log file(s) (log/import-*.log|.err).\n";

			if ($subcommand === 'reset')
				return true;   // jen reset, nic dál (Logger se nevytváří)
		}

		$this->idMap = new LocalIdMap($sqlitePath);   // čerstvá mapa
		$consoleMode = $quiet ? Logger::MODE_QUIET : ($verbose ? Logger::MODE_VERBOSE : Logger::MODE_NORMAL);
		$this->logger = new Logger(__APP_DIR__ . '/log/import-' . date('Ymd-His') . '.log', $consoleMode);
		$this->stats = new ImportStats();

		$ok = $this->dispatch($subcommand);
		$this->logger->printRecap();
		$this->logger->close();

		if (!$ok)
			exit(1);   // tvrdý fail / abort
		if ($this->logger->errorCount() > 0)
			exit(2);   // doběhlo, ale padly err úrovně (typicky --continue-on-error)
		return true;   // exit 0
	}

	private function dispatch(string $subcommand): bool
	{
		switch ($subcommand)
		{
			case 'status':            return (new runners\StatusRunner($this->context()))->run();

			// Phase 02 — codebooks
			case 'vat-registrations': return (new runners\VatRegistrationsRunner($this->context()))->run();
			case 'vat-periods':       return (new runners\VatPeriodsRunner($this->context()))->run();
			case 'fiscal-years':      return (new runners\FiscalYearsRunner($this->context()))->run();
			case 'bank-accounts':     return (new runners\BankAccountsRunner($this->context()))->run();
			case 'cost-centers':      return (new runners\CostCentersRunner($this->context()))->run();
			case 'warehouses':        return (new runners\WarehousesRunner($this->context()))->run();
			case 'cash-desks':        return (new runners\CashDesksRunner($this->context()))->run();
			case 'number-series':     return (new runners\NumberSeriesRunner($this->context()))->run();
			case 'item-kinds':        return (new runners\ItemKindsRunner($this->context()))->run();
			case 'units':             return (new runners\UnitsRunner($this->context()))->run();
			case 'accounts':          return (new runners\AccountsRunner($this->context()))->run();
			case 'all-codebooks':     return (new runners\AllCodebooksRunner($this->context()))->run();

			// Phase 03 — persons
			case 'persons':           return (new runners\PersonsRunner($this->context()))->run();

			// Phase 04 — items
			case 'items':             return (new runners\ItemsRunner($this->context()))->run();

			// Phase 05 — docs
			case 'docs':              return (new runners\DocsRunner($this->context()))->run();

			// Phase 11 — bank statements
			case 'bank-statements':   return (new runners\BankStatementsRunner($this->context()))->run();

			// Phase 07 — mail
			case 'mail':              return (new runners\MailRunner($this->context()))->run();

			// Phase 18 — registry (Spisovna)
			case 'registry':          return (new runners\RegistryRunner($this->context()))->run();

			// Phase 12 — accbal settings: samostatně --dump/--import; jako fáze je
			// součást `all` (AllRunner volá runImport() přímo, viz Fáze 15).
			case 'accbal-settings':   return (new runners\AccbalSettingsRunner($this->context()))->run();

			// Phase 06 — orchestrator ('reset' se odbaví v run() před LocalIdMap,
			// sem se nedostane).
			case 'all':               return (new runners\AllRunner($this->context()))->run();

			// Phase 10 — maintenance: zapomenout mapování jedné entity (cílený
			// re-import bez smazání celé mapy, na rozdíl od 'reset').
			case 'forget':            return $this->forgetEntity();
		}

		echo "Unknown subcommand: '{$subcommand}'\n\n";
		return $this->printUsage();
	}

	/**
	 * forget --entity=<doc|person|item|…>: smaže LocalIdMap mapování dané entity,
	 * ostatní (codebooks/persons/items) zachová. Pro čistý re-import dokladů po
	 * opravě importu (Fáze 10) bez nukování celé mapy.
	 */
	private function forgetEntity(): bool
	{
		$raw = strtolower(trim((string) ($this->app->arg('entity') ?? '')));
		$aliases = [
			'doc'            => LocalIdMap::ENTITY_DOC,            'docs'      => LocalIdMap::ENTITY_DOC,
			'person'         => LocalIdMap::ENTITY_PERSON,         'persons'   => LocalIdMap::ENTITY_PERSON,
			'item'           => LocalIdMap::ENTITY_ITEM,           'items'     => LocalIdMap::ENTITY_ITEM,
			'mail'           => LocalIdMap::ENTITY_MESSAGE,         'message'  => LocalIdMap::ENTITY_MESSAGE,
			'bank-statement' => LocalIdMap::ENTITY_BANK_STATEMENT, 'statement' => LocalIdMap::ENTITY_BANK_STATEMENT,
			'registry'       => LocalIdMap::ENTITY_REGISTRY_DOC,   'document' => LocalIdMap::ENTITY_REGISTRY_DOC,
			'binder'         => LocalIdMap::ENTITY_BINDER,
		];
		$entity = $aliases[$raw] ?? null;
		if ($entity === null)
		{
			echo "forget: missing or unknown --entity (use doc|person|item|message|bank-statement|registry|binder).\n";
			return false;
		}

		$before = $this->idMap->stats()[$entity] ?? 0;
		$this->idMap->forgetAll($entity);
		echo "! Forgot {$before} '{$entity}' mapping(s) from local id map.\n";
		echo "!        Re-import of this entity will re-create records (business-key\n";
		echo "!        match may not catch everything → watch for duplicates).\n";
		return true;
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
		echo "    vat-periods       VAT periods (taxperiods, periodType=0). Needs vat-registrations FIRST.\n";
		echo "    fiscal-years      Fiscal years + embedded fiscal months.\n";
		echo "    bank-accounts     Own bank accounts.\n";
		echo "    cost-centers      Cost centers (centres).\n";
		echo "    warehouses        Warehouses.\n";
		echo "    cash-desks        Cash desks (cashboxes).\n";
		echo "    number-series     Document number series (docnumbers).\n";
		echo "    item-kinds        Item kinds (itemtypes).\n";
		echo "    units             Units of measure (witems units).\n";
		echo "    accounts          Chart of accounts (účtový rozvrh).\n";
		echo "    all-codebooks     All of the above in dependency order.\n";
		echo "\n";
		echo "  Phase 03 — persons:\n";
		echo "    persons           Persons (people + companies) via exchange flow.\n";
		echo "\n";
		echo "  Phase 04 — items:\n";
		echo "    items             Items (goods, services) via exchange flow.\n";
		echo "\n";
		echo "  Phase 05 — docs:\n";
		echo "    docs              Documents (invni/invno/cmnbkp) via exchange flow.\n";
		echo "                      Own company (is_own=1) required; flagged automatically by 'persons'.\n";
		echo "\n";
		echo "  Phase 11 — bank statements:\n";
		echo "    bank-statements   Bank statements (e10doc 'bank' docs) via exchange flow.\n";
		echo "                      Import bank-accounts FIRST (own account lookup). Storno\n";
		echo "                      (4100) skipped; PDF attachments migrated unless --no-attachments.\n";
		echo "\n";
		echo "  Phase 07 — mail:\n";
		echo "    mail              Incoming mail (wkf issues, issueType=1) via /_mail/import.\n";
		echo "                      Import docs FIRST (doc links). Best-effort linking.\n";
		echo "\n";
		echo "  Phase 18 — registry (Spisovna):\n";
		echo "    registry          Documents (wkf.docs) → base.registry via /_registry/import.\n";
		echo "                      Binders = live folder-tree roots (generic CRUD), docs deduped by\n";
		echo "                      legacy.ndx. Attachments uploaded to tableId 428 unless\n";
		echo "                      --no-attachments. Post-import on TARGET: shpd-ds registry-extract-texts.\n";
		echo "\n";
		echo "  Phase 06 — orchestrator:\n";
		echo "    all               Run codebooks → accbal-settings → persons → items → docs →\n";
		echo "                      bank-statements → mail → registry → match (remote accbal matching).\n";
		echo "    reset             Delete the local id map (import-newShipard.sqlite) and old\n";
		echo "                      import logs (log/import-*.log|.err), then exit.\n";
		echo "\n";
		echo "  Phase 12 — accbal settings (součást 'all'; samostatně pro --dump / vlastní --file):\n";
		echo "    accbal-settings   Settings saldokont. Vyžaduje --dump nebo --import.\n";
		echo "                      Import idempotentní per kód skupiny (v 'all' běží za číselníky).\n";
		echo "                      --dump:   stará DB → JSON (seed tvar, bez dobropisů).\n";
		echo "                      --import: JSON → economy_accbal_balances/_balance_accounts.\n";
		echo "                      --file=PATH override (default data/accbalSettings.json).\n";
		echo "\n";
		echo "  Phase 10 — maintenance:\n";
		echo "    forget --entity=X Forget local id map for one entity (doc|person|item|message|\n";
		echo "                      bank-statement|registry|binder), keeping the rest. For targeted\n";
		echo "                      clean re-import (e.g. docs).\n";
		echo "\n";
		echo "Common options:\n";
		echo "  --verbose, -v        More verbose output (HTTP + per-row debug).\n";
		echo "  --quiet              Console shows only warnings/errors, 'Done' summaries and a\n";
		echo "                       progress tick every 500 records; .log stays complete.\n";
		echo "                       Mutually exclusive with --verbose.\n";
		echo "  --dry-run            Do not perform writes against the target.\n";
		echo "  --continue-on-error  Skip failed rows instead of aborting the runner.\n";
		echo "  --skip-accbal-settings  Skip the accbal-settings phase ('all' only).\n";
		echo "  --skip-match         Skip the final remote matching phase ('all' only).\n";
		echo "  --limit=N            Process only the first N source rows (exchange runners only).\n";
		echo "  --no-throttle        Disable client-side throttling between requests (for testing).\n";
		echo "  --dump-payload       Print the canonical JSON sent to the exchange apply\n";
		echo "                       (exchange runners: persons/items/docs). Failed rows dump\n";
		echo "                       payload + response body automatically.\n";
		echo "  --from=YYYY-MM-DD    docs: accounting date; mail: dateIncoming; bank-statements:\n";
		echo "                       datePeriodEnd; registry: dateCreate (>=). 'docs'/'mail'/\n";
		echo "                       'bank-statements'/'registry'/'all'.\n";
		echo "  --to=YYYY-MM-DD      docs: accounting date; mail: dateIncoming; bank-statements:\n";
		echo "                       datePeriodEnd; registry: dateCreate (<=). 'docs'/'mail'/\n";
		echo "                       'bank-statements'/'registry'/'all'.\n";
		echo "  --target-state=10    Cap all docs to draft (10), overriding the old→new state map\n";
		echo "                       (1000→10,1200→20,4000/8000→40,4100→30). Test runs. 'docs' only.\n";
		echo "  --chunk-months=N     Document import chunk size in months (default 1). 'docs'/'all'.\n";
		echo "  --batch=N            Source read batch size (keyset). Default 500.\n";
		echo "                       Exchange runners (persons/items/docs/bank-statements) + 'mail'.\n";
		echo "  --require-linked-doc Import only mail messages with a resolvable linked doc. 'mail' only.\n";
		echo "  --no-attachments     Skip PDF attachment upload. 'mail'/'bank-statements'/'registry'.\n";
		echo "  --reset              Delete the local id map and old import logs before\n";
		echo "                       running (clean re-import).\n";
		echo "\n";
		echo "Exit codes:\n";
		echo "  0  clean run\n";
		echo "  1  hard fail / abort\n";
		echo "  2  finished, but some rows failed (typically with --continue-on-error)\n";
		echo "\n";
		return true;
	}
}
