<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\ImportRunner;
use imports\newShipard\libs\HttpException;

final class StatusRunner extends ImportRunner
{
	public function run(): bool
	{
		$this->info("Import to new Shipard — status check");
		$this->info("");

		// 1. Config
		$this->info("Configuration:");
		$this->info("  Config file     : " . $this->config()->filePath());
		$this->info("  Target base URL : " . $this->config()->targetBaseUrl());
		$this->info("  Timeout         : " . $this->config()->timeout() . " s");
		$this->info("  Batch size      : " . $this->config()->batchSize());
		$this->info("  Dry-run mode    : " . ($this->isDryRun()  ? 'yes' : 'no'));
		$this->info("  Verbose mode    : " . ($this->isVerbose() ? 'yes' : 'no'));
		$this->info("");

		// 2. HTTP connectivity
		$this->info("API connection:");
		$ok = false;
		try
		{
			$ping = $this->http()->ping();
			if ($ping['ok'])
			{
				$this->ok("HTTP " . $ping['statusCode'] . " — " . $ping['message']);
				$ok = true;
			}
			else
			{
				$this->err("HTTP " . $ping['statusCode'] . " — " . $ping['message']);
			}
		}
		catch (HttpException $e)
		{
			$this->err("Network/HTTP error: " . $e->getMessage());
		}
		$this->info("");

		// 3. Local ID map
		$this->info("Local ID map:");
		$this->info("  File: " . $this->idMap()->path());
		$stats = $this->idMap()->stats();
		if ($stats === [])
		{
			$this->info("  (empty — no entities imported yet)");
		}
		else
		{
			foreach ($stats as $type => $count)
				$this->info(sprintf("  %-20s  %d", $type, $count));
		}
		$this->info("");

		if (!$ok)
		{
			$this->err("Status FAILED.");
			return false;
		}

		$this->ok("Status OK.");
		return true;
	}
}
