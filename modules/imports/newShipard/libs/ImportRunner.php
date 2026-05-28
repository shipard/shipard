<?php

namespace imports\newShipard\libs;

abstract class ImportRunner
{
	protected ImportContext $context;

	public function __construct(ImportContext $context)
	{
		$this->context = $context;
	}

	abstract public function run(): bool;

	// ── Shortcuts ────────────────────────────────────────────────

	protected function app(): \Shipard\CLI\Application { return $this->context->app; }
	protected function db(): \Dibi\Connection          { return $this->app()->db(); }
	protected function config(): ImportConfig          { return $this->context->config; }
	protected function http(): HttpClient              { return $this->context->httpClient; }
	protected function idMap(): LocalIdMap             { return $this->context->idMap; }

	// ── CLI flags shared across runners ──────────────────────────

	protected function isDryRun(): bool
	{
		return (bool) $this->app()->arg('dry-run') || $this->config()->dryRun();
	}

	protected function isVerbose(): bool
	{
		return (bool) $this->app()->arg('verbose')
			|| (bool) $this->app()->arg('v')
			|| $this->config()->verbose();
	}

	// ── Output helpers ───────────────────────────────────────────

	protected function info(string $msg): void { echo $msg . "\n"; }
	protected function ok(string $msg): void   { echo "✓ " . $msg . "\n"; }
	protected function warn(string $msg): void { echo "! " . $msg . "\n"; }
	protected function err(string $msg): void  { echo "✗ " . $msg . "\n"; }
	protected function debug(string $msg): void
	{
		if ($this->isVerbose())
			echo "[debug] " . $msg . "\n";
	}
}
