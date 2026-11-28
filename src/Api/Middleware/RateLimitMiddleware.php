<?php
declare(strict_types=1);

namespace Shipard\Api\Middleware;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Api\Route;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Devices\DeviceId;
use Shipard\Module\Core\Devices\DeviceRequestAuthenticator;

class RateLimitMiddleware
{
	private const int WINDOW_SECONDS = 60;

	/** Limits: requests per window per token type */
	private const array LIMITS = [
		'api_key' => 1000,
		'session' => 300,
		'login'   => 10,   // per IP, for login endpoint
		'ai_gw'   => 300,  // AI gateway, per gateway token (D5)
		'device'    => 120,  // volání zařízení nativní aplikace, per deviceId (#177)
		'device_ip' => 1200, // strop všech volání zařízení z jedné IP
		'default' => 60,   // unauthenticated non-login requests
	];

	/** Rate limit headers populated after handle(). */
	private array $headers = [];

	/**
	 * Check the rate limit for this request.
	 * Returns null if within limits (pipeline continues), or a 429 Response.
	 */
	public function handle(
		Request $request,
		AuthContext $auth,
		Route $route,
		DataSourceConnection $db,
	): ?Response {
		$this->headers = [];

		// Většina požadavků má jeden bucket; volání zařízení dva — vlastní
		// a strop pro IP adresu. Požadavek projde, jen když se vejde do všech.
		$buckets = [$this->resolveKey($request, $auth, $route)];
		$ceiling = $this->resolveCeiling($request, $buckets[0][1]);
		if ($ceiling !== null) {
			$buckets[] = $ceiling;
		}

		$windowStart = $this->currentWindowStart();
		$resetAt     = $windowStart + self::WINDOW_SECONDS;

		$counts = [];
		foreach ($buckets as $i => [$key, , $limit]) {
			$counts[$i] = $this->getCount($key, $windowStart, $db);

			if ($counts[$i] >= $limit) {
				$retryAfter = max(0, $resetAt - time());

				$this->headers = [
					'X-RateLimit-Limit'     => (string) $limit,
					'X-RateLimit-Remaining' => '0',
					'X-RateLimit-Reset'     => (string) $resetAt,
				];

				$resp = Response::error(
					'RATE_LIMITED',
					"Too many requests. Retry after {$retryAfter} seconds.",
					429,
					[['field' => '_retry_after', 'code' => 'SECONDS', 'message' => (string) $retryAfter]],
				);

				foreach ($this->headers as $name => $value) {
					$resp = $resp->withHeader($name, $value);
				}

				return $resp;
			}
		}

		// Hlavičky popisují bucket, kterému zbývá nejméně.
		$tightest = null;
		foreach ($buckets as $i => [$key, $type, $limit]) {
			$this->incrementCount($key, $type, $windowStart, $counts[$i], $db);

			$remaining = max(0, $limit - $counts[$i] - 1);
			if ($tightest === null || $remaining < $tightest[1]) {
				$tightest = [$limit, $remaining];
			}
		}

		$this->headers = [
			'X-RateLimit-Limit'     => (string) $tightest[0],
			'X-RateLimit-Remaining' => (string) $tightest[1],
			'X-RateLimit-Reset'     => (string) $resetAt,
		];

		return null;
	}

	public function getHeaders(): array
	{
		return $this->headers;
	}

	// -------------------------------------------------------------------------
	// Protected DB methods (overridable for testing)
	// -------------------------------------------------------------------------

	protected function getCount(string $key, int $windowStart, DataSourceConnection $db): int
	{
		$row = $db->fetchRow(
			'SELECT count FROM core_system_rate_limits WHERE rate_key = %s AND window_start = %i',
			$key,
			$windowStart,
		);
		return $row !== null ? (int) $row['count'] : 0;
	}

	protected function incrementCount(string $key, string $type, int $windowStart, int $current, DataSourceConnection $db): void
	{
		if ($current === 0) {
			$db->insertRow('core_system_rate_limits', [
				'rate_key'     => $key,
				'rate_type'    => $type,
				'window_start' => $windowStart,
				'count'        => 1,
			]);
		} else {
			$db->execute(
				'UPDATE core_system_rate_limits SET count = count + 1 WHERE rate_key = %s AND window_start = %i',
				$key,
				$windowStart,
			);
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/** @return array{0:string, 1:string, 2:int} [key, type, limit] */
	private function resolveKey(Request $request, AuthContext $auth, Route $route): array
	{
		// Login-class endpointy (lokální login, OIDC start/exchange, forgot/
		// reset hesla, OP authorize/approve/token): limit per IP.
		// oidcCallback zůstává v defaultu — chrání ho single-use state.
		// OP discovery/jwks zůstávají v defaultu — RP je cachuje.
		if (($route->controller === 'auth'
				&& in_array($route->action, ['login', 'oidcStart', 'oidcExchange'], true))
			|| ($route->controller === 'password'
				&& in_array($route->action, ['forgot', 'reset'], true))
			|| ($route->controller === 'hostingOidc'
				&& in_array($route->action, ['authorize', 'approve', 'token'], true))) {
			$ip   = $request->getClientIp() ?? 'unknown';
			$key  = hash('sha256', 'login:' . $ip);
			return [$key, 'login', self::LIMITS['login']];
		}

		// AI gateway (D5): requesty jsou exempt (anonymní pro AuthContext) —
		// bez vlastního bucketu by všechny DS klienti jednoho serveru (jedna
		// egress IP) sdíleli anon default 60/min. Klíč per (nevalidovaný)
		// gateway token = izolace per DS; spray bez klíče degraduje na
		// per-value buckety, řádky jsou okenní.
		if ($route->controller === 'hostingAiGateway') {
			$id  = $request->getHeader('x-api-key')
				?? ('ip:' . ($request->getClientIp() ?? 'unknown'));
			$key = hash('sha256', 'aigw:' . $id);
			return [$key, 'ai_gw', self::LIMITS['ai_gw']];
		}

		if ($auth->isAuthenticated && $auth->token !== null) {
			$key   = hash('sha256', $auth->token);
			$type  = $auth->tokenType === 'api_key' ? 'api_key' : 'session';
			return [$key, $type, self::LIMITS[$type]];
		}

		// Zařízení nativní aplikace (#177): podepsaná volání session nenesou,
		// takže by všechna zařízení jedné provozovny (jedna IP adresa) sdílela
		// anonymní limit 60/min — a jedna synchronizace jsou čtyři požadavky.
		// Klíč per `deviceId` zařízení od sebe oddělí; ID se tu neověřuje
		// (jen tvar), proto má bucket ještě strop pro IP adresu
		// ({@see resolveCeiling()}) — střídání vymyšlených ID limit neobejde.
		// Bez platného ID zůstává požadavek v anonymním limitu.
		if ($route->controller === 'devices') {
			$deviceId = $this->deviceIdOf($request, $route);
			if ($deviceId !== null) {
				return [hash('sha256', 'device:' . $deviceId), 'device', self::LIMITS['device']];
			}
		}

		// Unauthenticated: rate limit by IP
		$ip  = $request->getClientIp() ?? 'unknown';
		$key = hash('sha256', 'anon:' . $ip);
		return [$key, 'default', self::LIMITS['default']];
	}

	/**
	 * Druhý bucket, do kterého se požadavek musí vejít také — strop pro IP
	 * adresu u volání zařízení. Ostatní typy žádný nemají.
	 *
	 * @return array{0:string, 1:string, 2:int}|null [key, type, limit]
	 */
	private function resolveCeiling(Request $request, string $type): ?array
	{
		if ($type !== 'device') {
			return null;
		}
		$ip = $request->getClientIp() ?? 'unknown';
		return [hash('sha256', 'device-ip:' . $ip), 'device_ip', self::LIMITS['device_ip']];
	}

	/**
	 * `deviceId` anonymního volání zařízení ve tvaru UUID: u podepsaných
	 * volání hlavička `X-Shipard-Device`, u žádosti o výzvu pole těla.
	 * Registrace sem nepatří — běží se session administrátora.
	 */
	private function deviceIdOf(Request $request, Route $route): ?string
	{
		$deviceId = match ($route->action) {
			'self', 'peripherals', 'printerProfiles' => $request->getHeader(DeviceRequestAuthenticator::HEADER_DEVICE),
			'challenge'           => ($request->getBody() ?? [])['deviceId'] ?? null,
			default               => null,
		};
		return DeviceId::isValid($deviceId) ? $deviceId : null;
	}

	private function currentWindowStart(): int
	{
		return (int) (floor(time() / self::WINDOW_SECONDS) * self::WINDOW_SECONDS);
	}
}
