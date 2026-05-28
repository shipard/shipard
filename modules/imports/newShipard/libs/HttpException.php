<?php

namespace imports\newShipard\libs;

class HttpException extends \RuntimeException
{
	public ?string $requestMethod = null;
	public ?string $requestUrl = null;

	public function __construct(
		public readonly int $statusCode,
		public readonly ?string $errorCode,
		public readonly string $errorMessage,
		public readonly ?array $responseBody,
	) {
		parent::__construct(
			"HTTP {$statusCode}: " . ($errorCode ?? '?') . " — " . $errorMessage,
		);
	}

	/**
	 * Volá se z HttpClient hned po vytvoření, aby exception nesla i request detail.
	 */
	public function withRequest(string $method, string $url): self
	{
		$this->requestMethod = $method;
		$this->requestUrl    = $url;
		$this->message       = sprintf(
			'%s %s → HTTP %d: %s — %s%s',
			$method, $url,
			$this->statusCode,
			$this->errorCode ?? '?',
			$this->errorMessage,
			$this->formatDetails(),
		);
		return $this;
	}

	/**
	 * Pokud server vrátil structured validation errors (HTTP 422 obvykle),
	 * vypíše je v kompaktní formě "field=value [code: message]; ..." pro
	 * lepší ladění. Jinak prázdno.
	 */
	private function formatDetails(): string
	{
		$details = $this->responseBody['error']['details'] ?? null;
		if (!is_array($details) || $details === [])
			return '';

		$parts = [];
		foreach ($details as $d)
		{
			if (!is_array($d))
				continue;
			$field   = $d['field']   ?? '?';
			$code    = $d['code']    ?? '?';
			$message = $d['message'] ?? '';
			$parts[] = "{$field} [{$code}]: {$message}";
		}
		return $parts === [] ? '' : ' | details: ' . implode('; ', $parts);
	}
}
