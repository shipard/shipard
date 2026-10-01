<?php
declare(strict_types=1);

namespace Shipard\Api;

class Response
{
	private array $headers = [];
	private string $bodyType = 'json';

	/** @var (callable():void)|null */
	private $streamProducer = null;

	private function __construct(
		private int $status,
		private mixed $payload,
	) {}

	public static function success(mixed $data, int $status = 200, ?array $meta = null): self
	{
		$payload = ['success' => true, 'data' => $data];
		if ($meta !== null) {
			$payload['meta'] = $meta;
		}
		return new self($status, $payload);
	}

	public static function raw(mixed $data, int $status = 200): self
	{
		return new self($status, $data);
	}

	public static function error(string $code, string $message, int $status, array $details = []): self
	{
		$error = ['code' => $code, 'message' => $message];
		if ($details !== []) {
			$error['details'] = $details;
		}
		return new self($status, ['success' => false, 'error' => $error]);
	}

	public static function html(string $body, int $status = 200): self
	{
		$resp = new self($status, $body);
		$resp->bodyType = 'html';
		return $resp;
	}

	/**
	 * Binární tělo mimo JSON obálku (soubor ke stažení). Další hlavičky
	 * (Content-Disposition…) přes `withHeader()`; tělo vrací `getPayload()`.
	 */
	public static function binary(string $body, string $contentType, int $status = 200): self
	{
		$resp = new self($status, $body);
		$resp->bodyType = 'binary';
		$resp->headers['Content-Type'] = $contentType;
		$resp->headers['Content-Length'] = (string) strlen($body);
		return $resp;
	}

	public static function redirect(string $location, int $status = 302): self
	{
		$resp = new self($status, '');
		$resp->bodyType = 'redirect';
		$resp->headers['Location'] = $location;
		return $resp;
	}

	public static function stream(
		callable $producer,
		int $status = 200,
		string $contentType = 'text/plain; charset=utf-8',
	): self {
		$resp = new self($status, '');
		$resp->bodyType = 'stream';
		$resp->headers['Content-Type'] = $contentType;
		$resp->headers['X-Accel-Buffering'] = 'no';
		$resp->headers['Cache-Control'] = 'no-cache';
		$resp->streamProducer = $producer;
		return $resp;
	}

	public function withHeader(string $name, string $value): static
	{
		$clone          = clone $this;
		$clone->headers[$name] = $value;
		return $clone;
	}

	public function getPayload(): mixed
	{
		return $this->payload;
	}

	public function getHeaders(): array
	{
		return $this->headers;
	}

	public function send(): void
	{
		foreach ($this->headers as $name => $value) {
			header("{$name}: {$value}");
		}

		http_response_code($this->status);

		// 204 a 304 jsou bez těla (304 = conditional GET, ETag shoda).
		if ($this->status === 204 || $this->status === 304 || $this->bodyType === 'redirect') {
			return;
		}

		if ($this->bodyType === 'stream') {
			while (ob_get_level() > 0) {
				ob_end_flush();
			}
			@ob_implicit_flush(true);

			if ($this->streamProducer !== null) {
				($this->streamProducer)();
			}
			return;
		}

		if ($this->bodyType === 'binary') {
			echo $this->payload;
			return;
		}

		if ($this->bodyType === 'html') {
			header('Content-Type: text/html; charset=utf-8');
			echo $this->payload;
			return;
		}

		// Explicitní Content-Type z withHeader() má přednost (např.
		// application/manifest+json u /_app/manifest) — hlavičky výše už
		// odešly, druhé header() by je přepsalo.
		if (!isset($this->headers['Content-Type'])) {
			header('Content-Type: application/json; charset=utf-8');
		}
		echo json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}
}
