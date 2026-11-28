<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Middleware;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Middleware\RateLimitMiddleware;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Api\Route;
use Shipard\Core\Database\DataSourceConnection;

class TestableRateLimitMiddleware extends RateLimitMiddleware
{
	/** @var array<string, int>  key => count in current window */
	private array $store = [];

	/** Override window start to a fixed value for deterministic tests. */
	public int $fixedWindowStart;

	public function __construct()
	{
		$this->fixedWindowStart = (int) (floor(time() / 60) * 60);
	}

	protected function getCount(string $key, int $windowStart, DataSourceConnection $db): int
	{
		return $this->store[$key] ?? 0;
	}

	protected function incrementCount(string $key, string $type, int $windowStart, int $current, DataSourceConnection $db): void
	{
		$this->store[$key] = ($this->store[$key] ?? 0) + 1;
	}

	/** Preset the counter for a specific key. */
	public function preset(Request $request, AuthContext $auth, Route $route, int $count): void
	{
		// We need to derive the key the same way handle() does.
		// Use reflection to call the private method.
		$ref    = new \ReflectionClass(RateLimitMiddleware::class);
		$method = $ref->getMethod('resolveKey');
		[$key]  = $method->invoke($this, $request, $auth, $route);
		$this->store[$key] = $count;
	}

	/** Preset the IP ceiling of device calls (the second bucket). */
	public function presetCeiling(Request $request, int $count): void
	{
		$method = (new \ReflectionClass(RateLimitMiddleware::class))->getMethod('resolveCeiling');
		[$key]  = $method->invoke($this, $request, 'device');
		$this->store[$key] = $count;
	}

	/** @return array<string, int> */
	public function counters(): array
	{
		return $this->store;
	}
}

class RateLimitMiddlewareTest extends TestCase
{
	private DataSourceConnection $db;

	protected function setUp(): void
	{
		$ref      = new \ReflectionClass(DataSourceConnection::class);
		$this->db = $ref->newInstanceWithoutConstructor();
	}

	private function req(string $method = 'GET', string $path = '/api/v1/items', ?string $ip = null): Request
	{
		$server = $ip !== null ? ['REMOTE_ADDR' => $ip] : [];
		return Request::fromArray($method, $path, [], '', $server);
	}

	private function route(string $ctrl = 'crud', string $action = 'list'): Route
	{
		return new Route($ctrl, $action);
	}

	private function getStatus(Response $response): int
	{
		$ref  = new \ReflectionClass($response);
		$prop = $ref->getProperty('status');
		return $prop->getValue($response);
	}

	// ── Within limits ─────────────────────────────────────────────────────────

	public function testRequestBelowLimitReturnsNull(): void
	{
		$mw     = new TestableRateLimitMiddleware();
		$result = $mw->handle($this->req(), AuthContext::anonymous(), $this->route(), $this->db);

		$this->assertNull($result);
	}

	public function testAfterSuccessfulRequestHeadersArePopulated(): void
	{
		$mw = new TestableRateLimitMiddleware();
		$mw->handle($this->req(), AuthContext::anonymous(), $this->route(), $this->db);

		$headers = $mw->getHeaders();
		$this->assertArrayHasKey('X-RateLimit-Limit',     $headers);
		$this->assertArrayHasKey('X-RateLimit-Remaining', $headers);
		$this->assertArrayHasKey('X-RateLimit-Reset',     $headers);
	}

	public function testAuthenticatedSessionTokenUsesSessionLimit(): void
	{
		$mw   = new TestableRateLimitMiddleware();
		$auth = new AuthContext(true, 1, 'session', 'shpd_st_sometoken');
		$mw->handle($this->req(), $auth, $this->route(), $this->db);

		$this->assertSame('300', $mw->getHeaders()['X-RateLimit-Limit']);
	}

	public function testAuthenticatedApiKeyUsesApiKeyLimit(): void
	{
		$mw   = new TestableRateLimitMiddleware();
		$auth = new AuthContext(true, 1, 'api_key', 'shpd_ak_somekey');
		$mw->handle($this->req(), $auth, $this->route(), $this->db);

		$this->assertSame('1000', $mw->getHeaders()['X-RateLimit-Limit']);
	}

	public function testLoginEndpointUsesLoginLimit(): void
	{
		$mw   = new TestableRateLimitMiddleware();
		$auth = AuthContext::anonymous();
		$req  = $this->req('POST', '/api/v1/_auth/login', '127.0.0.1');
		$mw->handle($req, $auth, $this->route('auth', 'login'), $this->db);

		$this->assertSame('10', $mw->getHeaders()['X-RateLimit-Limit']);
	}

	// ── Over limit ────────────────────────────────────────────────────────────

	public function testRequestOverLimitReturns429(): void
	{
		$mw   = new TestableRateLimitMiddleware();
		$auth = AuthContext::anonymous();
		$req  = $this->req(ip: '192.168.1.1');
		$rt   = $this->route();

		// Preset counter to the limit (60 for anonymous)
		$mw->preset($req, $auth, $rt, 60);

		$result = $mw->handle($req, $auth, $rt, $this->db);

		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame(429, $this->getStatus($result));
	}

	public function test429ResponseHasRateLimitCode(): void
	{
		$mw   = new TestableRateLimitMiddleware();
		$auth = AuthContext::anonymous();
		$req  = $this->req(ip: '10.0.0.1');
		$rt   = $this->route();

		$mw->preset($req, $auth, $rt, 60);

		$result  = $mw->handle($req, $auth, $rt, $this->db);
		$payload = $result->getPayload();

		$this->assertSame('RATE_LIMITED', $payload['error']['code']);
	}

	public function test429ResponseHasRateLimitHeaders(): void
	{
		$mw   = new TestableRateLimitMiddleware();
		$auth = AuthContext::anonymous();
		$req  = $this->req(ip: '10.0.0.2');
		$rt   = $this->route();

		$mw->preset($req, $auth, $rt, 60);
		$result = $mw->handle($req, $auth, $rt, $this->db);

		$this->assertNotNull($result);
		$headers = $result->getHeaders();
		$this->assertArrayHasKey('X-RateLimit-Limit',     $headers);
		$this->assertArrayHasKey('X-RateLimit-Remaining', $headers);
		$this->assertArrayHasKey('X-RateLimit-Reset',     $headers);
		$this->assertSame('0', $headers['X-RateLimit-Remaining']);
	}

	public function testLoginEndpointOverLimitReturns429(): void
	{
		$mw   = new TestableRateLimitMiddleware();
		$auth = AuthContext::anonymous();
		$req  = $this->req('POST', '/api/v1/_auth/login', '1.2.3.4');
		$rt   = $this->route('auth', 'login');

		// Login limit is 10
		$mw->preset($req, $auth, $rt, 10);

		$result = $mw->handle($req, $auth, $rt, $this->db);

		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame(429, $this->getStatus($result));
	}

	// ── AI gateway bucket (D5) ────────────────────────────────────────────────

	public function testAiGatewayUsesOwnBucketKeyedByToken(): void
	{
		$ref    = new \ReflectionClass(RateLimitMiddleware::class);
		$method = $ref->getMethod('resolveKey');
		$mw     = new TestableRateLimitMiddleware();
		$auth   = AuthContext::anonymous();
		$rt     = new Route('hostingAiGateway', 'messages');

		$reqA = Request::fromArray('POST', '/api/v1/_hosting/ai-gw/v1/messages', [], '', [
			'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_API_KEY' => 'shpd_gw_tokenA',
		]);
		$reqB = Request::fromArray('POST', '/api/v1/_hosting/ai-gw/v1/messages', [], '', [
			'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_API_KEY' => 'shpd_gw_tokenB',
		]);

		[$keyA, $typeA, $limitA] = $method->invoke($mw, $reqA, $auth, $rt);
		[$keyB] = $method->invoke($mw, $reqB, $auth, $rt);

		$this->assertSame('ai_gw', $typeA);
		$this->assertSame(300, $limitA);
		// Izolace per token — dva DS na jedné egress IP nesdílí bucket.
		$this->assertNotSame($keyA, $keyB);

		// Bez x-api-key spadne na IP, ale zůstává v ai_gw bucketu (ne anon).
		$reqNoKey = Request::fromArray('POST', '/api/v1/_hosting/ai-gw/v1/messages', [], '', [
			'REMOTE_ADDR' => '10.0.0.5',
		]);
		[, $typeNoKey] = $method->invoke($mw, $reqNoKey, $auth, $rt);
		$this->assertSame('ai_gw', $typeNoKey);
	}

	// ── Zařízení nativní aplikace (#177) ──────────────────────────────────────

	private const DEVICE_A = '0b9f6c1e-52a4-4f0e-9a43-6f2d1c7e8a10';
	private const DEVICE_B = '5d0e7c34-8b1a-4c59-a2f7-3e9b6d4c1f02';

	private function signedReq(?string $deviceId, string $ip = '10.0.0.5', string $path = 'self'): Request
	{
		$server = ['REMOTE_ADDR' => $ip];
		if ($deviceId !== null) {
			$server['HTTP_X_SHIPARD_DEVICE'] = $deviceId;
		}
		return Request::fromArray('GET', '/api/v1/_devices/' . $path, [], '', $server);
	}

	private function challengeReq(mixed $deviceId, string $ip = '10.0.0.5'): Request
	{
		return Request::fromArray(
			'POST',
			'/api/v1/_devices/challenge',
			[],
			(string) json_encode(['deviceId' => $deviceId, 'purpose' => 'self']),
			['REMOTE_ADDR' => $ip, 'CONTENT_TYPE' => 'application/json'],
		);
	}

	/** @return array{0: string, 1: string, 2: int} */
	private function bucket(Request $request, Route $route, ?AuthContext $auth = null): array
	{
		$method = (new \ReflectionClass(RateLimitMiddleware::class))->getMethod('resolveKey');
		return $method->invoke(new TestableRateLimitMiddleware(), $request, $auth ?? AuthContext::anonymous(), $route);
	}

	public function testDeviceCallsUseOwnBucketKeyedByDeviceId(): void
	{
		foreach (['self', 'peripherals', 'printerProfiles'] as $action) {
			[$keyA, $type, $limit] = $this->bucket($this->signedReq(self::DEVICE_A), new Route('devices', $action));
			[$keyB] = $this->bucket($this->signedReq(self::DEVICE_B), new Route('devices', $action));

			$this->assertSame('device', $type, $action);
			$this->assertSame(120, $limit, $action);
			// Dvě zařízení jedné provozovny (jedna IP adresa) nesdílí bucket.
			$this->assertNotSame($keyA, $keyB, $action);
		}

		// Výzva nese deviceId v těle — a patří do téhož bucketu jako podepsané volání.
		[$challengeKey, $challengeType] = $this->bucket($this->challengeReq(self::DEVICE_A), new Route('devices', 'challenge'));
		[$selfKey] = $this->bucket($this->signedReq(self::DEVICE_A), new Route('devices', 'self'));
		$this->assertSame('device', $challengeType);
		$this->assertSame($selfKey, $challengeKey);
		// Bucket zařízení nezávisí na IP adrese (mobilní síť, Wi-Fi).
		[$otherIp] = $this->bucket($this->signedReq(self::DEVICE_A, '10.0.0.99'), new Route('devices', 'self'));
		$this->assertSame($selfKey, $otherIp);
	}

	public function testDeviceCallWithoutAValidIdStaysInTheAnonymousBucket(): void
	{
		[$anonKey] = $this->bucket($this->req(ip: '10.0.0.5'), $this->route());

		foreach ([
			[$this->signedReq(null), 'self'],
			[$this->signedReq('neni-uuid'), 'self'],
			[$this->challengeReq('neni-uuid'), 'challenge'],
			[$this->challengeReq(['x']), 'challenge'],
			[$this->challengeReq(null), 'challenge'],
			// Hlavička u žádosti o výzvu nerozhoduje, tělo u podepsaného volání také ne.
			[$this->signedReq(self::DEVICE_A, path: 'challenge'), 'challenge'],
			// Registrace běží se session administrátora — anonym vlastní bucket nedostane.
			[$this->signedReq(self::DEVICE_A, path: 'register'), 'register'],
		] as [$request, $action]) {
			[$key, $type, $limit] = $this->bucket($request, new Route('devices', $action));
			$this->assertSame('default', $type, $action);
			$this->assertSame(60, $limit, $action);
			$this->assertSame($anonKey, $key, $action);
		}
	}

	public function testRegisterChallengeWithASessionUsesTheSessionBucket(): void
	{
		$auth = new AuthContext(true, 3, 'session', 'shpd_st_abc', true);

		[, $type, $limit] = $this->bucket($this->challengeReq(self::DEVICE_A), new Route('devices', 'challenge'), $auth);

		$this->assertSame('session', $type);
		$this->assertSame(300, $limit);
	}

	public function testDeviceCallsDoNotShareTheAnonymousLimit(): void
	{
		$mw    = new TestableRateLimitMiddleware();
		$anon  = AuthContext::anonymous();
		$route = new Route('devices', 'self');

		// Anonymní limit IP adresy je vyčerpaný (přihlašovací obrazovky, jiní klienti) …
		$mw->preset($this->req(ip: '10.0.0.5'), $anon, $this->route(), 60);
		$this->assertInstanceOf(Response::class, $mw->handle($this->req(ip: '10.0.0.5'), $anon, $this->route(), $this->db));

		// … zařízení ze stejné adresy přesto projde, a anonymní limit nespotřebovává.
		$this->assertNull($mw->handle($this->signedReq(self::DEVICE_A), $anon, $route, $this->db));
		$this->assertSame('120', $mw->getHeaders()['X-RateLimit-Limit']);
		$this->assertSame('119', $mw->getHeaders()['X-RateLimit-Remaining']);
		[$anonKey] = $this->bucket($this->req(ip: '10.0.0.5'), $this->route());
		$this->assertSame(60, $mw->counters()[$anonKey]);
	}

	public function testOneDeviceOverItsLimitDoesNotBlockAnotherOnTheSameAddress(): void
	{
		$mw    = new TestableRateLimitMiddleware();
		$anon  = AuthContext::anonymous();
		$route = new Route('devices', 'self');

		$mw->preset($this->signedReq(self::DEVICE_A), $anon, $route, 120);

		$blocked = $mw->handle($this->signedReq(self::DEVICE_A), $anon, $route, $this->db);
		$this->assertInstanceOf(Response::class, $blocked);
		$this->assertSame(429, $this->getStatus($blocked));
		$this->assertSame('120', $mw->getHeaders()['X-RateLimit-Limit']);

		$this->assertNull($mw->handle($this->signedReq(self::DEVICE_B), $anon, $route, $this->db));
	}

	public function testInventedDeviceIdsCannotBypassTheAddressCeiling(): void
	{
		$mw    = new TestableRateLimitMiddleware();
		$anon  = AuthContext::anonymous();
		$route = new Route('devices', 'challenge');

		// Každé vymyšlené ID má vlastní prázdný bucket — zastaví je strop IP adresy.
		$mw->presetCeiling($this->challengeReq(self::DEVICE_A), 1200);
		$before = $mw->counters();

		foreach ([self::DEVICE_A, self::DEVICE_B, 'ffffffff-ffff-ffff-ffff-ffffffffffff'] as $deviceId) {
			$blocked = $mw->handle($this->challengeReq($deviceId), $anon, $route, $this->db);
			$this->assertInstanceOf(Response::class, $blocked, $deviceId);
			$this->assertSame(429, $this->getStatus($blocked), $deviceId);
			$this->assertSame('1200', $mw->getHeaders()['X-RateLimit-Limit']);
		}
		// Odmítnutý požadavek nezakládá ani nezvyšuje žádný čítač.
		$this->assertSame($before, $mw->counters());

		// Jiná adresa má vlastní strop.
		$this->assertNull($mw->handle($this->challengeReq(self::DEVICE_A, '10.0.0.6'), $anon, $route, $this->db));
	}

	public function testDeviceCallCountsInBothItsBucketAndTheAddressCeiling(): void
	{
		$mw    = new TestableRateLimitMiddleware();
		$anon  = AuthContext::anonymous();
		$route = new Route('devices', 'self');

		$mw->handle($this->signedReq(self::DEVICE_A), $anon, $route, $this->db);
		$mw->handle($this->signedReq(self::DEVICE_A), $anon, $route, $this->db);
		$mw->handle($this->signedReq(self::DEVICE_B), $anon, $route, $this->db);

		$counters = $mw->counters();
		sort($counters);
		// Zařízení B 1, zařízení A 2, strop adresy 3.
		$this->assertSame([1, 2, 3], $counters);
		// Hlavičky popisují bucket, kterému zbývá nejméně — vlastní bucket zařízení.
		$this->assertSame('120', $mw->getHeaders()['X-RateLimit-Limit']);
		$this->assertSame('119', $mw->getHeaders()['X-RateLimit-Remaining']);
	}

	public function testOtherRequestsHaveASingleBucket(): void
	{
		$mw = new TestableRateLimitMiddleware();

		$mw->handle($this->req(ip: '10.0.0.5'), AuthContext::anonymous(), $this->route(), $this->db);
		$mw->handle($this->req(), new AuthContext(true, 1, 'session', 'shpd_st_abc'), $this->route(), $this->db);

		$this->assertSame([1, 1], array_values($mw->counters()));
	}

	// ── Remaining count ───────────────────────────────────────────────────────

	public function testRemainingDecrementsWithEachRequest(): void
	{
		$mw   = new TestableRateLimitMiddleware();
		$auth = AuthContext::anonymous();
		$req  = $this->req(ip: '10.0.0.5');
		$rt   = $this->route();

		$mw->handle($req, $auth, $rt, $this->db);
		$remaining1 = (int) $mw->getHeaders()['X-RateLimit-Remaining'];

		$mw->handle($req, $auth, $rt, $this->db);
		$remaining2 = (int) $mw->getHeaders()['X-RateLimit-Remaining'];

		$this->assertGreaterThan($remaining2, $remaining1);
	}
}
