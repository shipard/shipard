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
	 * Pokud server vrátil structured validation errors, vypíše je v kompaktní
	 * formě "field [code]: message; ..." pro lepší ladění.
	 *
	 * Podporuje dva shapes:
	 *
	 *   1. Generic CRUD validator (422 VALIDATION_ERROR):
	 *      error.details = [{field, code, message}, ...]
	 *
	 *   2. Exchange applier (400/422 schema_invalid / validation_failed):
	 *      error.details.canonical._resolve.issues = [{severity, path, code, message}, ...]
	 *
	 * Jinak prázdno.
	 */
	private function formatDetails(): string
	{
		$details = $this->responseBody['error']['details'] ?? null;
		if (!is_array($details) || $details === [])
			return '';

		// Exchange shape — issues v canonical._resolve.issues.
		$issues = $details['canonical']['_resolve']['issues'] ?? null;
		if (is_array($issues) && $issues !== [])
			return $this->formatIssues($issues);

		// Generic CRUD shape — flat array of {field, code, message}.
		return $this->formatIssues($details);
	}

	/**
	 * @param array<int, array<string, mixed>> $issues
	 */
	private function formatIssues(array $issues): string
	{
		$parts = [];
		foreach ($issues as $d)
		{
			if (!is_array($d))
				continue;
			$path     = $d['path']     ?? $d['field']    ?? '?';
			$code     = $d['code']     ?? '?';
			$message  = $d['message']  ?? '';
			$severity = $d['severity'] ?? null;
			$prefix   = is_string($severity) ? "[{$severity} {$code}]" : "[{$code}]";
			$parts[]  = "{$path} {$prefix}: {$message}";
		}
		return $parts === [] ? '' : ' | details: ' . implode('; ', $parts);
	}
}
