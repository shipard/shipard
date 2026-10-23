<?php
declare(strict_types=1);

namespace Shipard\Api;

class Request
{
	private function __construct(
		private string $method,
		private string $path,
		private array $queryParams,
		private ?array $body,
		private array $headers,
		private string $host,
		private ?string $clientIp,
		private string $scheme = 'http',
	) {}

	public static function fromGlobals(): self
	{
		return self::fromArray(
			$_SERVER['REQUEST_METHOD'] ?? 'GET',
			$_SERVER['REQUEST_URI'] ?? '/',
			$_GET,
			(string) file_get_contents('php://input'),
			$_SERVER,
		);
	}

	public static function fromArray(string $method, string $uri, array $queryParams, string $rawBody, array $server): self
	{
		$method = strtoupper($method);

		$path = parse_url($uri, PHP_URL_PATH) ?? $uri;
		if ($path === false || $path === null) {
			$path = $uri;
		}

		$body = null;
		if ($rawBody !== '') {
			$decoded = json_decode($rawBody, true);
			if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
				$body = $decoded;
			}
		}

		$headers = [];
		foreach ($server as $key => $value) {
			if (str_starts_with($key, 'HTTP_')) {
				$name = substr($key, 5);
				$name = strtolower(str_replace('_', '-', $name));
				$headers[$name] = $value;
			} elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
				$name = strtolower(str_replace('_', '-', $key));
				$headers[$name] = $value;
			}
		}

		$host = $server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '';
		$clientIp = $server['REMOTE_ADDR'] ?? null;

		return new self($method, $path, $queryParams, $body, $headers, $host, $clientIp, self::schemeFrom($server));
	}

	/**
	 * `http` / `https`: první hodnota `X-Forwarded-Proto` (proxy před PHP),
	 * jinak `HTTPS` (cokoli kromě prázdné a `off`), jinak `REQUEST_SCHEME`;
	 * výchozí `http`.
	 *
	 * @param array<string, mixed> $server
	 */
	private static function schemeFrom(array $server): string
	{
		$forwarded = $server['HTTP_X_FORWARDED_PROTO'] ?? null;
		if (is_string($forwarded) && $forwarded !== '') {
			$first = strtolower(trim(explode(',', $forwarded)[0]));
			if ($first === 'https' || $first === 'http') {
				return $first;
			}
		}
		$https = $server['HTTPS'] ?? null;
		if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
			return 'https';
		}
		$scheme = $server['REQUEST_SCHEME'] ?? null;
		if (is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true)) {
			return strtolower($scheme);
		}
		return 'http';
	}

	public function getMethod(): string
	{
		return $this->method;
	}

	public function getPath(): string
	{
		return $this->path;
	}

	public function getQueryParams(): array
	{
		return $this->queryParams;
	}

	public function getBody(): ?array
	{
		return $this->body;
	}

	public function getHeader(string $name): ?string
	{
		$key = strtolower($name);
		return $this->headers[$key] ?? null;
	}

	public function getHost(): string
	{
		return $this->host;
	}

	public function getScheme(): string
	{
		return $this->scheme;
	}

	/**
	 * Origin požadavku, jak ho vidí prohlížeč: hlavička `Origin`, je-li to
	 * platný http(s) origin bez cesty ({@see isOrigin()}), jinak schéma + host.
	 * Pro úlohy, které si otevírají aplikaci stejnou adresou jako uživatel
	 * (`dataset-e2e --base-url`, #103 F7a). Prázdný host dává `http://` —
	 * volající ověří přes {@see isOrigin()}.
	 */
	public function getOrigin(): string
	{
		$origin = $this->getHeader('origin');
		if (is_string($origin) && self::isOrigin($origin)) {
			return $origin;
		}
		return $this->scheme . '://' . $this->host;
	}

	/** `scheme://host[:port]` bez cesty, dotazu a přihlašovacích údajů. */
	public static function isOrigin(string $value): bool
	{
		return preg_match('#^https?://(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.\-]+)(:\d{1,5})?$#', $value) === 1;
	}

	public function getClientIp(): ?string
	{
		return $this->clientIp;
	}
}
