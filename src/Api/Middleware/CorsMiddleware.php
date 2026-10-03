<?php
declare(strict_types=1);

namespace Shipard\Api\Middleware;

use Shipard\Api\Request;
use Shipard\Api\Response;

class CorsMiddleware
{
	private const array HEADERS = [
		'Access-Control-Allow-Origin'  => 'https://*.shipard.cz',
		'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
		'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept-Language',
		// Export reportu a tisk: název souboru, stav, jazyk tisku a hlášení
		// čte frontend z hlaviček odpovědi.
		'Access-Control-Expose-Headers' => 'Content-Disposition, Content-Language, X-Report-Status, X-Print-Messages',
		'Access-Control-Max-Age'       => '86400',
	];

	/**
	 * Handle a request. For OPTIONS preflight, returns a 204 response immediately.
	 * For all other methods, returns null (pipeline continues).
	 */
	public function handle(Request $request): ?Response
	{
		if ($request->getMethod() === 'OPTIONS') {
			return $this->applyTo(Response::success(null, 204));
		}
		return null;
	}

	/**
	 * Add CORS headers to any response.
	 */
	public function applyTo(Response $response): Response
	{
		foreach (self::HEADERS as $name => $value) {
			$response = $response->withHeader($name, $value);
		}
		return $response;
	}
}
