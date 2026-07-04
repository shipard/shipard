<?php

namespace imports\newShipard\libs;

/**
 * Úrovňový logger importu: konzole (echo) + `.log` soubor + lazy `.err` soubor.
 *
 * Úrovně: debug | info | ok | warn | err | summary. Prefixy (`✓`, `!`, `✗`,
 * `[debug]`) renderuje výhradně Logger — call sites předávají holý text.
 *
 * Politika výstupu:
 *   - konzole `normal`  = vše kromě debug
 *   - konzole `quiet`   = jen warn/err/summary (+ progress())
 *   - konzole `verbose` = vše
 *   - `.log` soubor     = vše kromě debug; s verbose i debug
 *   - `.err` soubor     = jen warn+err; otevírá se lazy při prvním záznamu
 *                         (čistý běh žádný `.err` nezaloží)
 *   - block() (payload dumpy) = konzole vždy + `.log`; do `.err` nejde,
 *                               aby zůstal skenovatelný
 *
 * Warn/err řádky se navíc sbírají do paměti (cap RECAP_CAP + čítače nad cap)
 * pro závěrečný printRecap().
 *
 * Log soubory jsou best-effort — pokud je nelze otevřít, běh pokračuje jen
 * s konzolovým výstupem (nikdy neblokuje import).
 */
final class Logger
{
	public const MODE_NORMAL  = 'normal';
	public const MODE_QUIET   = 'quiet';
	public const MODE_VERBOSE = 'verbose';

	private const PREFIX = [
		'debug'   => '[debug] ',
		'info'    => '',
		'ok'      => '✓ ',
		'warn'    => '! ',
		'err'     => '✗ ',
		'summary' => '',
	];

	private const RECAP_CAP   = 100;
	private const RECAP_PRINT = 20;

	private $handle = null;      // resource|null — .log
	private $errHandle = null;   // resource|null — .err (lazy)
	private bool $errOpenFailed = false;

	/** @var string[] warn/err řádky (s prefixem) pro recap, max RECAP_CAP */
	private array $recap = [];
	private int $warnCount = 0;
	private int $errCount = 0;

	public function __construct(
		private readonly ?string $filePath,
		private readonly string $consoleMode = self::MODE_NORMAL,
	)
	{
		if ($filePath !== null)
		{
			@mkdir(dirname($filePath), 0700, true);
			$this->handle = @fopen($filePath, 'ab');
			if ($this->handle === false)
				$this->handle = null;   // log soubor je best-effort, nikdy neblokuje běh
		}
	}

	// ── Úrovňové API ─────────────────────────────────────────────

	public function debug(string $text): void   { $this->log('debug', $text); }
	public function info(string $text): void    { $this->log('info', $text); }
	public function ok(string $text): void      { $this->log('ok', $text); }
	public function warn(string $text): void    { $this->log('warn', $text); }
	public function err(string $text): void     { $this->log('err', $text); }
	public function summary(string $text): void { $this->log('summary', $text); }

	/** BC alias pro `info` (původní jediné API). */
	public function line(string $text): void { $this->log('info', $text); }

	/**
	 * Progress tick — jen konzole a jen v quiet režimu. Do souborů nejde,
	 * aby `.log` zůstal shodný s během bez --quiet.
	 */
	public function progress(string $text): void
	{
		if ($this->consoleMode === self::MODE_QUIET)
			echo $text . "\n";
	}

	/** Víceřádkový blok (dump payloadů) — konzole vždy, `.log` bez per-řádek značky. */
	public function block(string $text): void
	{
		echo $text . "\n";
		if ($this->handle !== null)
			fwrite($this->handle, $text . "\n");
	}

	// ── Jádro ────────────────────────────────────────────────────

	private function log(string $level, string $text): void
	{
		$line = self::PREFIX[$level] . $text;

		$toConsole = match ($this->consoleMode)
		{
			self::MODE_VERBOSE => true,
			self::MODE_QUIET   => in_array($level, ['warn', 'err', 'summary'], true),
			default            => $level !== 'debug',
		};
		if ($toConsole)
			echo $line . "\n";

		$stamped = '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n";
		if ($this->handle !== null && ($level !== 'debug' || $this->consoleMode === self::MODE_VERBOSE))
			fwrite($this->handle, $stamped);

		if ($level === 'warn' || $level === 'err')
		{
			if ($level === 'err')
				$this->errCount++;
			else
				$this->warnCount++;

			if (count($this->recap) < self::RECAP_CAP)
				$this->recap[] = $line;

			$eh = $this->errHandle();
			if ($eh !== null)
				fwrite($eh, $stamped);
		}
	}

	/** Lazy otevření `.err` — jen jednou; best-effort jako `.log`. */
	private function errHandle()
	{
		if ($this->errHandle !== null || $this->errOpenFailed)
			return $this->errHandle;

		$path = $this->errPath();
		if ($path === null)
		{
			$this->errOpenFailed = true;
			return null;
		}

		$h = @fopen($path, 'ab');
		if ($h === false)
		{
			$this->errOpenFailed = true;
			return null;
		}
		$this->errHandle = $h;
		return $this->errHandle;
	}

	// ── Recap ────────────────────────────────────────────────────

	/**
	 * Souhrn warn/err na konci běhu: čítače + prvních RECAP_PRINT řádků +
	 * odkaz na `.err`. Bez posbíraných řádků nevypíše nic. Jde přes úroveň
	 * `summary` (viditelné i v quiet, zapíše se do `.log`, nezacyklí se
	 * do recap sběru).
	 */
	public function printRecap(): void
	{
		if ($this->warnCount + $this->errCount === 0)
			return;

		$this->summary('');
		$this->summary("⚠ {$this->errCount} errors, {$this->warnCount} warnings");

		foreach (array_slice($this->recap, 0, self::RECAP_PRINT) as $l)
			$this->summary('  ' . $l);

		$more = $this->warnCount + $this->errCount - min(count($this->recap), self::RECAP_PRINT);
		if ($more > 0)
			$this->summary("  … and {$more} more");

		if ($this->errHandle !== null)
			$this->summary('full list: ' . $this->errPath());
	}

	// ── Gettery / lifecycle ──────────────────────────────────────

	public function isQuiet(): bool { return $this->consoleMode === self::MODE_QUIET; }

	public function errorCount(): int   { return $this->errCount; }
	public function warningCount(): int { return $this->warnCount; }

	public function path(): ?string { return $this->filePath; }

	/** Cesta `.err` souboru (stejný basename jako `.log`). */
	public function errPath(): ?string
	{
		if ($this->filePath === null)
			return null;
		return (str_ends_with($this->filePath, '.log') ? substr($this->filePath, 0, -4) : $this->filePath) . '.err';
	}

	public function close(): void
	{
		if ($this->handle !== null)
		{
			fclose($this->handle);
			$this->handle = null;
		}
		if ($this->errHandle !== null)
		{
			fclose($this->errHandle);
			$this->errHandle = null;
		}
	}
}
