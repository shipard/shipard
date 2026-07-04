<?php

namespace imports\newShipard\libs;

/**
 * HTTP klient pro REST API nového Shipardu. Bearer auth, JSON in/out, curl pod kapotou.
 *
 * Rate limiting (Phase 03a):
 *
 *   - **Proaktivní throttling** — minimum interval `throttleMs` mezi requesty,
 *     měřeno přes microtime(true). Defaultně 0 (off) — ImportConfig nastaví 80 ms.
 *
 *   - **Retry s exp. backoff** — `maxRetries` pokusů pro:
 *       - 429 RATE_LIMITED → preferuj `_retry_after` z error.details[], fallback exp.
 *       - 5xx server errory → exp. backoff
 *       - network errory (statusCode=0) → exp. backoff
 *     4xx (kromě 429) jsou fatální — žádný retry.
 *
 *   - Retry-After **není** v HTTP header (nový Shipard ho nevrací) — jen v
 *     `error.details[{field: '_retry_after', code: 'SECONDS', message: '<int>'}]`.
 *     Viz nov_shipard:src/Api/Middleware/RateLimitMiddleware.php.
 */
final class HttpClient
{
	public const PING_PATH = '/_meta/tables';

	/** Cap proti server malice (kdyby _retry_after = 3600). */
	private const MAX_RETRY_AFTER_SECONDS = 60;

	/** Cap proti zacyklení dlouhé exp. backoff sekvence. */
	private const MAX_BACKOFF_SECONDS = 30;

	/** microtime(true) z posledního requestu — pro throttling. */
	private float $lastRequestTime = 0.0;

	public function __construct(
		private readonly string $baseUrl,
		private readonly string $apiKey,
		private readonly int $timeout = 30,
		private readonly bool $verbose = false,
		private readonly int $throttleMs = 0,
		private readonly int $maxRetries = 0,
		private readonly int $retryDelayMs = 1000,
	) {}

	public function get(string $path, array $queryParams = []): array
	{
		$url = $path;
		if ($queryParams !== [])
			$url .= (str_contains($path, '?') ? '&' : '?') . http_build_query($queryParams);
		return $this->request('GET', $url, null);
	}

	public function post(string $path, array $body): array  { return $this->request('POST',   $path, $body); }
	public function put(string $path, array $body): array   { return $this->request('PUT',    $path, $body); }
	public function patch(string $path, array $body): array { return $this->request('PATCH',  $path, $body); }
	public function delete(string $path): array             { return $this->request('DELETE', $path, null);  }

	public function ping(): array
	{
		try
		{
			$body = $this->get(self::PING_PATH);
			return [
				'ok'         => true,
				'statusCode' => 200,
				'message'    => 'Tables endpoint reachable.',
				'body'       => $body,
			];
		}
		catch (HttpException $e)
		{
			return [
				'ok'         => false,
				'statusCode' => $e->statusCode,
				'message'    => $e->errorMessage,
				'body'       => $e->responseBody,
			];
		}
	}

	// ── Upload ───────────────────────────────────────────────────────────

	/**
	 * Multipart upload jednoho souboru. Integrováno s throttling + retry
	 * (sdílí runWithRetry() + executeRequest() s request()). $fields jsou textová
	 * pole (table_id, record_id…), $filePath se přiloží jako pole `file` (resp.
	 * $fileFieldName) přes CURLFile.
	 *
	 * @param array<string,scalar> $fields
	 * @return array  Dekódovaná JSON odpověď (data + případný warning).
	 * @throws HttpException při 4xx/5xx (kromě retryovatelných) i při chybějícím souboru.
	 */
	public function uploadFile(string $path, array $fields, string $filePath, string $fileFieldName = 'file', ?string $clientFileName = null): array
	{
		if (!is_file($filePath))
			throw new HttpException(0, null, "Upload file not found: {$filePath}", null);

		$url = $this->resolveUrl($path);

		// Multipart pole musí být stringy; curl Content-Type s boundary nastaví sám.
		$textFields = [];
		foreach ($fields as $k => $v)
			$textFields[$k] = (string) $v;

		$curlOpts = [
			CURLOPT_POST       => true,
			CURLOPT_POSTFIELDS => $textFields + [
				$fileFieldName => new \CURLFile($filePath, null, $clientFileName ?? basename($filePath)),
			],
		];

		// Velká PDF mohou potřebovat vyšší timeout než default (otevřený bod 1).
		$timeout = max($this->timeout, 60);

		return $this->runWithRetry('POST', $url, $timeout, $curlOpts, $this->baseHeaders());
	}

	// ── Request flow ────────────────────────────────────────────────────

	private function request(string $method, string $path, ?array $body): array
	{
		$url     = $this->resolveUrl($path);
		$headers = $this->baseHeaders();

		$curlOpts = [CURLOPT_CUSTOMREQUEST => $method];
		if ($body !== null)
		{
			$curlOpts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$headers[] = 'Content-Type: application/json';
		}

		return $this->runWithRetry($method, $url, $this->timeout, $curlOpts, $headers);
	}

	private function resolveUrl(string $path): string
	{
		if ($path === '' || $path[0] !== '/')
			throw new \InvalidArgumentException("HttpClient path must start with '/', got '{$path}'.");
		return $this->baseUrl . $path;
	}

	/** Společné hlavičky pro JSON i multipart (bez Content-Type — ten řeší volající/curl). */
	private function baseHeaders(): array
	{
		return [
			'Authorization: Bearer ' . $this->apiKey,
			'Accept: application/json',
			'User-Agent: shipard-importer/1.0',
		];
	}

	/**
	 * Throttle + retry smyčka okolo jednoho curl pokusu. Sdílené pro JSON
	 * requesty i multipart upload — dostane hotové curl opts + headers + timeout.
	 */
	private function runWithRetry(string $method, string $url, int $timeout, array $curlOpts, array $headers): array
	{
		$attempt = 0;
		while (true)
		{
			$this->applyThrottle();

			try
			{
				return $this->executeRequest($method, $url, $timeout, $curlOpts, $headers);
			}
			catch (HttpException $e)
			{
				$attempt++;
				$retryAfter = $this->shouldRetry($e, $attempt);
				if ($retryAfter === null)
					throw $e;

				if ($this->verbose)
				{
					fwrite(STDERR, sprintf(
						"[http] retry %d/%d after %d s (HTTP %d: %s)\n",
						$attempt, $this->maxRetries, $retryAfter,
						$e->statusCode, $e->errorCode ?? '?',
					));
				}
				sleep($retryAfter);
			}
		}
	}

	/**
	 * Jeden curl pokus. Bezstavový — bez retry awareness, retry řeší volající.
	 * Bere připravené curl opts (CUSTOMREQUEST/POST, POSTFIELDS) + hlavičky.
	 */
	private function executeRequest(string $method, string $url, int $timeout, array $curlOpts, array $headers): array
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
			CURLOPT_FAILONERROR    => false,
			CURLOPT_HTTPHEADER     => $headers,
		] + $curlOpts);

		if ($this->verbose)
		{
			$pf   = $curlOpts[CURLOPT_POSTFIELDS] ?? null;
			$info = is_string($pf) ? ' (body ' . strlen($pf) . ' B)' : (is_array($pf) ? ' (multipart)' : '');
			fwrite(STDERR, sprintf("[http] %s %s%s\n", $method, $url, $info));
		}

		$responseBody = curl_exec($ch);
		$errno        = curl_errno($ch);
		$errMsg       = $errno ? curl_error($ch) : '';
		$statusCode   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($this->verbose)
		{
			fwrite(STDERR, sprintf(
				"[http] → %d (body %d B)\n",
				$statusCode, is_string($responseBody) ? strlen($responseBody) : 0,
			));
		}

		if ($errno !== 0)
			throw (new HttpException(0, null, "Network error: {$errMsg}", null))->withRequest($method, $url);

		$parsed = null;
		if (is_string($responseBody) && $responseBody !== '')
		{
			try
			{
				$decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
				$parsed  = is_array($decoded) ? $decoded : null;
			}
			catch (\JsonException $e)
			{
				if ($statusCode >= 200 && $statusCode < 300)
					throw new HttpException($statusCode, null, "Response is not valid JSON: " . $e->getMessage(), null);
				// non-2xx with non-JSON body — fall through to HTTP error below
			}
		}

		if ($statusCode < 200 || $statusCode >= 300)
		{
			$errorCode    = is_array($parsed) ? ($parsed['error']['code']    ?? null) : null;
			$errorMessage = is_array($parsed) ? ($parsed['error']['message'] ?? null) : null;
			if ($errorMessage === null)
				$errorMessage = is_string($responseBody) && $responseBody !== ''
					? self::condenseNonJsonBody($responseBody)
					: "HTTP {$statusCode}";
			throw (new HttpException($statusCode, $errorCode, $errorMessage, $parsed))->withRequest($method, $url);
		}

		return $parsed ?? [];
	}

	// ── Throttling ──────────────────────────────────────────────────────

	/**
	 * Drží minimum interval `throttleMs` mezi requesty. Měřeno od posledního
	 * requestu — pokud aplikace mezi tím něco dělala (DB query, mapování),
	 * čekání už proběhlo "samo" a applyThrottle() nic nepřidá.
	 */
	private function applyThrottle(): void
	{
		if ($this->throttleMs <= 0)
		{
			$this->lastRequestTime = microtime(true);
			return;
		}

		$now = microtime(true);
		if ($this->lastRequestTime === 0.0)
		{
			$this->lastRequestTime = $now;
			return;
		}

		$elapsedMs = ($now - $this->lastRequestTime) * 1000;
		$waitMs = $this->throttleMs - $elapsedMs;
		if ($waitMs > 0)
			usleep((int) ($waitMs * 1000));

		$this->lastRequestTime = microtime(true);
	}

	// ── Retry decision ──────────────────────────────────────────────────

	/**
	 * Vrátí počet sekund pro sleep před retry, nebo null pokud retry odmítáme.
	 *
	 * Retry-able: 429 (sleep podle _retry_after), 5xx, network error (statusCode=0).
	 * Fatal:      4xx kromě 429 (validation, schema_invalid, 404 atd.),
	 *             překročené maxRetries.
	 */
	private function shouldRetry(HttpException $e, int $attemptNumber): ?int
	{
		if ($attemptNumber > $this->maxRetries)
			return null;

		if ($e->statusCode === 429)
		{
			$retryAfter = $this->parseRetryAfterSeconds($e);
			if ($retryAfter !== null)
				return min($retryAfter, self::MAX_RETRY_AFTER_SECONDS);
			return $this->exponentialBackoffSeconds($attemptNumber);
		}

		// 5xx server error.
		if ($e->statusCode >= 500 && $e->statusCode < 600)
			return $this->exponentialBackoffSeconds($attemptNumber);

		// Network error / timeout / DNS — HttpClient mapuje na statusCode=0.
		if ($e->statusCode === 0)
			return $this->exponentialBackoffSeconds($attemptNumber);

		// 4xx (kromě 429) — fatal, runner musí opravit data.
		return null;
	}

	/**
	 * 1s, 2s, 4s, ... — base z `retryDelayMs`, doubling per attempt, cap 30s.
	 */
	private function exponentialBackoffSeconds(int $attempt): int
	{
		$base = (int) ceil($this->retryDelayMs / 1000);
		if ($base < 1)
			$base = 1;
		$wait = $base * (2 ** ($attempt - 1));
		return min($wait, self::MAX_BACKOFF_SECONDS);
	}

	/**
	 * 429 response shape (viz nov_shipard:RateLimitMiddleware):
	 *   { error: { details: [{ field: "_retry_after", code: "SECONDS", message: "6" }] } }
	 */
	private function parseRetryAfterSeconds(HttpException $e): ?int
	{
		if ($e->responseBody === null)
			return null;

		$details = $e->responseBody['error']['details'] ?? null;
		if (!is_array($details))
			return null;

		foreach ($details as $detail)
		{
			if (!is_array($detail))
				continue;
			if (($detail['field'] ?? null) === '_retry_after')
			{
				$value = (int) ($detail['message'] ?? '0');
				return $value > 0 ? $value : null;
			}
		}
		return null;
	}

	/**
	 * Non-JSON chybové tělo (typicky nginx HTML stránka — 413, 502, …) zhustí
	 * na jednořádkové shrnutí, ať se do výstupu importu nevalí celé HTML.
	 * HTML: obsah <title>, fallback otagovaný text; jiný obsah: první řádek.
	 */
	private static function condenseNonJsonBody(string $body): string
	{
		$trimmed = ltrim($body);
		if (str_starts_with($trimmed, '<') && (stripos($trimmed, '<html') !== false || stripos($trimmed, '<!doctype') === 0))
		{
			if (preg_match('~<title>\s*(.*?)\s*</title>~is', $body, $m) && $m[1] !== '')
				return 'server error page: ' . html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);

			$text = trim(preg_replace('~\s+~', ' ', strip_tags($body)) ?? '');
			return 'server error page: ' . mb_substr($text, 0, 120);
		}

		$line = strtok(trim($body), "\n");
		$line = ($line === false) ? $body : $line;
		return mb_strlen($line) > 200 ? mb_substr($line, 0, 200) . '…' : $line;
	}
}
