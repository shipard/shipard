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

	/**
	 * Soubor z disku po částech (video pro přehrávač, tasks/testing-phase7b.md
	 * D127): tělo se nečte do paměti, `send()` ho posílá po blocích. Bez
	 * `$range` celý soubor s 200, s ním 206 a `Content-Range`; vždy
	 * `Accept-Ranges: bytes`. Rozsah se ověřuje předem přes
	 * {@see ByteRange::resolve()} — 416 skládá {@see rangeNotSatisfiable()}.
	 * `getPayload()` vrací cestu.
	 */
	public static function file(string $path, string $contentType, int $size, ?ByteRange $range = null): self
	{
		$resp = new self($range === null ? 200 : 206, $path);
		$resp->bodyType = 'file';
		$resp->headers['Content-Type'] = $contentType;
		$resp->headers['Accept-Ranges'] = 'bytes';
		$resp->headers['Content-Length'] = (string) ($range === null ? $size : $range->length());
		if ($range !== null) {
			$resp->headers['Content-Range'] = $range->contentRange();
		}
		return $resp;
	}

	/** 416 pro rozsah mimo soubor ({@see ByteRangeUnsatisfiable}). */
	public static function rangeNotSatisfiable(int $size): self
	{
		$resp = new self(416, '');
		$resp->bodyType = 'binary';
		$resp->headers['Content-Type'] = 'text/plain; charset=utf-8';
		$resp->headers['Content-Length'] = '0';
		$resp->headers['Content-Range'] = ByteRange::unsatisfiableRange($size);
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

	public function getStatus(): int
	{
		return $this->status;
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

		if ($this->bodyType === 'file') {
			$this->sendFile();
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

	/**
	 * Tělo souboru po blocích (256 KiB) od začátku rozsahu s `flush()` po
	 * každém (výstupní buffer PHP-FPM se tak nikdy nenaplní celým videem)
	 * a bez limitu běhu — velký soubor přes pomalou linku trvá.
	 */
	private function sendFile(): void
	{
		$start = 0;
		$length = (int) ($this->headers['Content-Length'] ?? 0);
		if (isset($this->headers['Content-Range']) && preg_match('/^bytes (\d+)-(\d+)\//', $this->headers['Content-Range'], $m) === 1) {
			$start = (int) $m[1];
		}
		$handle = @fopen((string) $this->payload, 'rb');
		if ($handle === false) {
			return;
		}
		@set_time_limit(0);
		if ($start > 0) {
			fseek($handle, $start);
		}
		$remaining = $length;
		while ($remaining > 0 && !feof($handle)) {
			$chunk = fread($handle, min(262_144, $remaining));
			if ($chunk === false || $chunk === '') {
				break;
			}
			echo $chunk;
			flush();
			$remaining -= strlen($chunk);
		}
		fclose($handle);
	}
}
