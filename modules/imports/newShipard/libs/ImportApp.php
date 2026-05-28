<?php

namespace imports\newShipard\libs;

class ImportApp
{
	/** @var \Shipard\CLI\Application */
	private $app;

	private ?ImportConfig $config = null;
	private ?HttpClient $httpClient = null;
	private ?LocalIdMap $idMap = null;

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

		$this->httpClient = new HttpClient(
			baseUrl: $this->config->targetBaseUrl(),
			apiKey:  $this->config->targetApiKey(),
			timeout: $this->config->timeout(),
			verbose: $verbose,
		);

		$this->idMap = new LocalIdMap(__APP_DIR__ . '/import-newShipard.sqlite');

		return $this->dispatch($subcommand);
	}

	private function dispatch(string $subcommand): bool
	{
		switch ($subcommand)
		{
			case 'status':
				return (new runners\StatusRunner($this->context()))->run();

			// Following subcommands will be wired in later phases:
			//   case 'all':            → orchestrate codebooks → persons → items → docs
			//   case 'bank-accounts':  → Phase 02
			//   case 'cost-centers':   → Phase 02
			//   case 'warehouses':     → Phase 02
			//   case 'cash-desks':     → Phase 02
			//   case 'number-series':  → Phase 02
			//   case 'fiscal-years':   → Phase 02
			//   case 'vat-registrations': → Phase 02
			//   case 'persons':        → Phase 03
			//   case 'items':          → Phase 04
			//   case 'docs':           → Phase 05
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
		);
	}

	private function printUsage(): bool
	{
		echo "Usage: shpd-app cli-action --action=imports.newShipard/import <subcommand> [options]\n";
		echo "\n";
		echo "Subcommands:\n";
		echo "  status        Sanity check — connection, config, local map.\n";
		echo "\n";
		echo "Common options:\n";
		echo "  --verbose, -v     More verbose output.\n";
		echo "  --dry-run         Do not perform writes against the target.\n";
		echo "\n";
		return true;
	}
}
