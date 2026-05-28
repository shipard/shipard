<?php

namespace imports\newShipard\libs;

class HttpException extends \RuntimeException
{
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
}
