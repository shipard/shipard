<?php

namespace imports\newShipard\libs;

final class ImportConfig
{
	private function __construct(
		private readonly array $rawData,
		private readonly string $filePath,
	) {}

	public static function load(string $dsRootDir): self
	{
		$path = $dsRootDir . '/config/import-newShipard.json';

		if (!is_file($path))
		{
			$msg = "Config file '{$path}' not found.\n\n"
				. "Create the file with at minimum:\n"
				. "{\n"
				. "    \"target\": {\n"
				. "        \"baseUrl\": \"https://<new-shipard-host>/api/v1\",\n"
				. "        \"apiKey\": \"shpd_ak_...\"\n"
				. "    }\n"
				. "}\n\n"
				. "Optional rate-limiting tuning (defaults shown):\n"
				. "    \"target\": {\n"
				. "        \"throttleMs\":   80,    // pauza mezi requesty v ms\n"
				. "        \"maxRetries\":   3,     // počet retry pokusů\n"
				. "        \"retryDelayMs\": 1000   // base delay pro exp. backoff v ms\n"
				. "    }\n\n"
				. "Make sure to chmod 0600 the file (it contains an API key).";
			throw new ImportException($msg);
		}

		$perms = fileperms($path) & 0777;
		if ($perms !== 0600)
		{
			fwrite(STDERR, sprintf(
				"WARNING: config file '%s' has permissions %04o, expected 0600.\nRun: chmod 0600 %s\n",
				$path, $perms, $path,
			));
		}

		$contents = file_get_contents($path);
		$raw = json_decode($contents, true);
		if ($raw === null)
			throw new ImportException("Config file '{$path}' is not valid JSON: " . json_last_error_msg());

		if (!isset($raw['target']) || !is_array($raw['target']))
			throw new ImportException("Config '{$path}': missing 'target' section.");

		$baseUrl = $raw['target']['baseUrl'] ?? '';
		if (!is_string($baseUrl) || $baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL))
			throw new ImportException("Config '{$path}': 'target.baseUrl' missing or not a valid URL.");

		$apiKey = $raw['target']['apiKey'] ?? '';
		if (!is_string($apiKey) || !str_starts_with($apiKey, 'shpd_ak_'))
			throw new ImportException("Config '{$path}': 'target.apiKey' missing or does not start with 'shpd_ak_'.");

		if (array_key_exists('timeout', $raw['target']))
		{
			$timeout = $raw['target']['timeout'];
			if (!is_int($timeout) || $timeout < 1 || $timeout > 300)
				throw new ImportException("Config '{$path}': 'target.timeout' must be an integer between 1 and 300.");
		}

		// Rate limiting (Phase 03a).
		if (array_key_exists('throttleMs', $raw['target']))
		{
			$tm = $raw['target']['throttleMs'];
			if (!is_int($tm) || $tm < 0 || $tm > 10000)
				throw new ImportException("Config '{$path}': 'target.throttleMs' must be an integer between 0 and 10000.");
		}

		if (array_key_exists('maxRetries', $raw['target']))
		{
			$mr = $raw['target']['maxRetries'];
			if (!is_int($mr) || $mr < 0 || $mr > 10)
				throw new ImportException("Config '{$path}': 'target.maxRetries' must be an integer between 0 and 10.");
		}

		if (array_key_exists('retryDelayMs', $raw['target']))
		{
			$rd = $raw['target']['retryDelayMs'];
			if (!is_int($rd) || $rd < 100 || $rd > 60000)
				throw new ImportException("Config '{$path}': 'target.retryDelayMs' must be an integer between 100 and 60000.");
		}

		if (isset($raw['options']))
		{
			if (!is_array($raw['options']))
				throw new ImportException("Config '{$path}': 'options' must be an object.");

			if (array_key_exists('batchSize', $raw['options']))
			{
				$bs = $raw['options']['batchSize'];
				if (!is_int($bs) || $bs < 1 || $bs > 1000)
					throw new ImportException("Config '{$path}': 'options.batchSize' must be an integer between 1 and 1000.");
			}

			foreach (['verbose', 'dryRun'] as $opt)
			{
				if (array_key_exists($opt, $raw['options']) && !is_bool($raw['options'][$opt]))
					throw new ImportException("Config '{$path}': 'options.{$opt}' must be a boolean.");
			}
		}

		return new self($raw, $path);
	}

	public function targetBaseUrl(): string { return rtrim($this->rawData['target']['baseUrl'], '/'); }
	public function targetApiKey(): string  { return $this->rawData['target']['apiKey']; }
	public function timeout(): int          { return $this->rawData['target']['timeout'] ?? 30; }
	public function throttleMs(): int       { return $this->rawData['target']['throttleMs'] ?? 80; }
	public function maxRetries(): int       { return $this->rawData['target']['maxRetries'] ?? 3; }
	public function retryDelayMs(): int     { return $this->rawData['target']['retryDelayMs'] ?? 1000; }
	public function batchSize(): int        { return $this->rawData['options']['batchSize'] ?? 100; }
	public function verbose(): bool         { return $this->rawData['options']['verbose'] ?? false; }
	public function dryRun(): bool          { return $this->rawData['options']['dryRun'] ?? false; }

	public function raw(): array            { return $this->rawData; }
	public function filePath(): string      { return $this->filePath; }
}
