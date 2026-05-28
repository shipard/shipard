<?php

namespace imports\newShipard\libs;

final class HttpClient
{
	public const PING_PATH = '/_meta/tables';

	public function __construct(
		private readonly string $baseUrl,
		private readonly string $apiKey,
		private readonly int $timeout = 30,
		private readonly bool $verbose = false,
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

	private function request(string $method, string $path, ?array $body): array
	{
		if ($path === '' || $path[0] !== '/')
			throw new \InvalidArgumentException("HttpClient path must start with '/', got '{$path}'.");

		$url = $this->baseUrl . $path;

		$headers = [
			'Authorization: Bearer ' . $this->apiKey,
			'Accept: application/json',
			'User-Agent: shipard-importer/1.0',
		];

		$payload = null;
		if ($body !== null)
		{
			$payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$headers[] = 'Content-Type: application/json';
		}

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_TIMEOUT        => $this->timeout,
			CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
			CURLOPT_FAILONERROR    => false,
			CURLOPT_HTTPHEADER     => $headers,
		]);
		if ($payload !== null)
			curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

		if ($this->verbose)
		{
			fwrite(STDERR, sprintf(
				"[http] %s %s%s\n",
				$method, $url,
				$payload !== null ? ' (body ' . strlen($payload) . ' B)' : '',
			));
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
					? $responseBody
					: "HTTP {$statusCode}";
			throw (new HttpException($statusCode, $errorCode, $errorMessage, $parsed))->withRequest($method, $url);
		}

		return $parsed ?? [];
	}
}
