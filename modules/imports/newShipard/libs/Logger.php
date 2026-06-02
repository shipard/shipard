<?php

namespace imports\newShipard\libs;

/**
 * Tee výstup: vše jde na konzoli (echo) a volitelně do log souboru.
 *
 * Log soubor je best-effort — pokud ho nelze otevřít, běh pokračuje jen
 * s konzolovým výstupem (nikdy neblokuje import).
 */
final class Logger
{
	private $handle = null;   // resource|null

	public function __construct(private readonly ?string $filePath)
	{
		if ($filePath !== null)
		{
			@mkdir(dirname($filePath), 0700, true);
			$this->handle = @fopen($filePath, 'ab');
			if ($this->handle === false)
				$this->handle = null;   // log soubor je best-effort, nikdy neblokuje běh
		}
	}

	/** Echo na konzoli + (volitelně) řádek do souboru s časovou značkou. */
	public function line(string $text): void
	{
		echo $text . "\n";
		if ($this->handle !== null)
			fwrite($this->handle, '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n");
	}

	/** Víceřádkový blok (dump payloadů) — bez per-řádek časové značky. */
	public function block(string $text): void
	{
		echo $text . "\n";
		if ($this->handle !== null)
			fwrite($this->handle, $text . "\n");
	}

	public function path(): ?string { return $this->filePath; }

	public function close(): void
	{
		if ($this->handle !== null)
		{
			fclose($this->handle);
			$this->handle = null;
		}
	}
}
