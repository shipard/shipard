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
	// Prefixy (✓/!/✗/[debug]) renderuje Logger podle úrovně; debug filtruje
	// Logger dle režimu konzole (verbose z configu i CLI řeší ImportApp).

	protected function logger(): Logger { return $this->context->logger; }

	protected function info(string $msg): void    { $this->logger()->info($msg); }
	protected function ok(string $msg): void      { $this->logger()->ok($msg); }
	protected function warn(string $msg): void    { $this->logger()->warn($msg); }
	protected function err(string $msg): void     { $this->logger()->err($msg); }
	protected function debug(string $msg): void   { $this->logger()->debug($msg); }
	protected function summary(string $msg): void { $this->logger()->summary($msg); }

	/**
	 * Progress tick pro quiet režim: každých 500 zpracovaných záznamů vypíše
	 * `[fáze] N zpracováno (klíč=hodnota, …)`. Mimo quiet no-op; modulo řeší
	 * tick sám — volá se na konci každé iterace hlavní smyčky runneru.
	 *
	 * @param array<string, int> $stats
	 */
	protected function tick(string $phase, int $processed, array $stats): void
	{
		if (!$this->logger()->isQuiet() || $processed === 0 || $processed % 500 !== 0)
			return;

		$parts = [];
		foreach ($stats as $k => $v)
			$parts[] = "{$k}={$v}";
		$this->logger()->progress(sprintf('[%s] %d zpracováno (%s)', $phase, $processed, implode(', ', $parts)));
	}
}
