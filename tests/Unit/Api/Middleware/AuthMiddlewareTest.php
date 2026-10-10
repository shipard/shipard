<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Middleware;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Middleware\AuthMiddleware;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Api\Route;
use Shipard\Core\Database\DataSourceConnection;

class TestableAuthMiddleware extends AuthMiddleware
{
	private ?array $mockApiKeyRow = null;
	private ?array $mockSessionRow = null;
	public bool $lastUsedUpdated = false;

	public function setMockApiKeyRow(?array $row): void
	{
		$this->mockApiKeyRow = $row;
	}

	public function setMockSessionRow(?array $row): void
	{
		$this->mockSessionRow = $row;
	}

	protected function lookupApiKey(string $keyHash, string $keyPrefix, DataSourceConnection $db): ?array
	{
		return $this->mockApiKeyRow;
	}

	protected function lookupSession(string $token, DataSourceConnection $db): ?array
	{
		return $this->mockSessionRow;
	}

	protected function updateApiKeyLastUsed(int $id, DataSourceConnection $db): void
	{
		$this->lastUsedUpdated = true;
	}
}

class AuthMiddlewareTest extends TestCase
{
	private DataSourceConnection $db;
	private TestableAuthMiddleware $middleware;

	protected function setUp(): void
	{
		$ref = new \ReflectionClass(DataSourceConnection::class);
		$this->db = $ref->newInstanceWithoutConstructor();
		$this->middleware = new TestableAuthMiddleware();
	}

	private function req(string $method = 'GET', string $path = '/api/v1/some_table', array $server = []): Request
	{
		return Request::fromArray($method, $path, [], '', $server);
	}

	private function route(string $controller = 'crud', string $action = 'list'): Route
	{
		return new Route($controller, $action);
	}

	private function futureTimestamp(): string
	{
		return date('Y-m-d H:i:s', time() + 3600);
	}

	private function pastTimestamp(): string
	{
		return date('Y-m-d H:i:s', time() - 3600);
	}

	// --- Missing auth header ---

	public function testMissingAuthHeaderReturnsAnonymousContext(): void
	{
		$result = $this->middleware->handle($this->req(), $this->route(), $this->db);

		$this->assertInstanceOf(AuthContext::class, $result);
		$this->assertFalse($result->isAuthenticated);
	}

	// --- Empty token ---

	public function testEmptyBearerTokenReturnsAnonymousContext(): void
	{
		$req = $this->req(server: ['HTTP_AUTHORIZATION' => 'Bearer ']);
		$result = $this->middleware->handle($req, $this->route(), $this->db);

		$this->assertInstanceOf(AuthContext::class, $result);
		$this->assertFalse($result->isAuthenticated);
	}

	public function testWhitespaceBearerTokenReturnsAnonymousContext(): void
	{
		$req = $this->req(server: ['HTTP_AUTHORIZATION' => 'Bearer   ']);
		$result = $this->middleware->handle($req, $this->route(), $this->db);

		$this->assertInstanceOf(AuthContext::class, $result);
		$this->assertFalse($result->isAuthenticated);
	}

	// --- Exempt endpoints ---

	public function testLoginEndpointIsExemptFromAuth(): void
	{
		$route = new Route('auth', 'login');
		$result = $this->middleware->handle($this->req(), $route, $this->db);

		$this->assertInstanceOf(AuthContext::class, $result);
		$this->assertFalse($result->isAuthenticated);
	}

	public function testOpenApiExemptWhenPublic(): void
	{
		$route = new Route('openapi', 'spec');
		$result = $this->middleware->handle($this->req(), $route, $this->db, openApiPublic: true);

		$this->assertInstanceOf(AuthContext::class, $result);
		$this->assertFalse($result->isAuthenticated);
	}

	public function testAppInfoIsExempt(): void
	{
		// Exempt route propustí i request s vadnou hlavičkou — login obrazovka
		// a favicon musí fungovat bez tokenu.
		$route = new Route('app', 'info');
		$req = $this->req(server: ['HTTP_AUTHORIZATION' => 'Token malformed']);
		$result = $this->middleware->handle($req, $route, $this->db);

		$this->assertInstanceOf(AuthContext::class, $result);
		$this->assertFalse($result->isAuthenticated);
	}

	public function testAppManifestIsExempt(): void
	{
		// Prohlížeč fetchuje <link rel="manifest"> bez Authorization hlavičky.
		$route = new Route('app', 'manifest');
		$req = $this->req(server: ['HTTP_AUTHORIZATION' => 'Token malformed']);
		$result = $this->middleware->handle($req, $route, $this->db);

		$this->assertInstanceOf(AuthContext::class, $result);
		$this->assertFalse($result->isAuthenticated);
	}

	public function testAppBrandingGetIsExempt(): void
	{
		$route = new Route('app', 'brandingGet', 'icon');
		$req = $this->req(server: ['HTTP_AUTHORIZATION' => 'Token malformed']);
		$result = $this->middleware->handle($req, $route, $this->db);

		$this->assertInstanceOf(AuthContext::class, $result);
		$this->assertFalse($result->isAuthenticated);
	}

	public function testAppBrandingUploadIsNotExempt(): void
	{
		$route = new Route('app', 'brandingUpload', 'icon');
		$req = $this->req('POST', '/api/v1/_app/branding/icon', ['HTTP_AUTHORIZATION' => 'Token malformed']);
		$result = $this->middleware->handle($req, $route, $this->db);

		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame(401, $this->getStatus($result));
	}

	public function testAppBrandingDeleteIsNotExempt(): void
	{
		$route = new Route('app', 'brandingDelete', 'icon');
		$req = $this->req('DELETE', '/api/v1/_app/branding/icon', ['HTTP_AUTHORIZATION' => 'Token malformed']);
		$result = $this->middleware->handle($req, $route, $this->db);

		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame(401, $this->getStatus($result));
	}

	public function testHostingServerEndpointsAreExempt(): void
	{
		// Auth klíčem shpd_hk_ si dělá HostingServerController sám — exempt
		// route propustí i request s vadnou hlavičkou.
		foreach (['reconcile', 'queue', 'confirm'] as $action) {
			$route = new Route('hostingServer', $action);
			$req = $this->req(server: ['HTTP_AUTHORIZATION' => 'Token malformed']);
			$result = $this->middleware->handle($req, $route, $this->db);

			$this->assertInstanceOf(AuthContext::class, $result);
			$this->assertFalse($result->isAuthenticated);
		}
	}

	public function testHostingMailLookupIsExempt(): void
	{
		// Auth klíčem shpd_hk_ routeru si dělá HostingMailController sám.
		$route = new Route('hostingMail', 'lookup');
		$req = $this->req(server: ['HTTP_AUTHORIZATION' => 'Token malformed']);
		$result = $this->middleware->handle($req, $route, $this->db);

		$this->assertInstanceOf(AuthContext::class, $result);
		$this->assertFalse($result->isAuthenticated);
	}

	public function testRemovedHostingAiAnalyzerLookupIsNotExempt(): void
	{
		// Registr AI analyzerů zanikl (#85 D22) — bývalá výjimka pro jeho
		// lookup je pryč, routa s vadnou hlavičkou dostane 401 jako každá jiná.
		$route = new Route('hostingAiAnalyzer', 'lookup');
		$req = $this->req(server: ['HTTP_AUTHORIZATION' => 'Token malformed']);
		$result = $this->middleware->handle($req, $route, $this->db);

		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame(401, $this->getStatus($result));
	}

	public function testHostingAiGatewayMessagesIsExempt(): void
	{
		// Auth gateway tokenem shpd_gw_ si dělá HostingAiGatewayController
		// sám — i klient posílající navíc Authorization header musí projít.
		$route = new Route('hostingAiGateway', 'messages');
		$req = $this->req(server: ['HTTP_AUTHORIZATION' => 'Bearer whatever']);
		$result = $this->middleware->handle($req, $route, $this->db);

		$this->assertInstanceOf(AuthContext::class, $result);
		$this->assertFalse($result->isAuthenticated);
	}

	public function testOpenApiRequiresAuthWhenNotPublic(): void
	{
		// Even without a token, openapi is not exempt → anonymous (but controllers must check)
		$route = new Route('openapi', 'spec');
		$result = $this->middleware->handle($this->req(), $route, $this->db, openApiPublic: false);

		$this->assertInstanceOf(AuthContext::class, $result);
		$this->assertFalse($result->isAuthenticated);
	}

	// --- Valid API key ---

	public function testValidApiKeyReturnsAuthenticatedContext(): void
	{
		$this->middleware->setMockApiKeyRow([
			'id'          => 7,
			'user_id'     => 42,
			'is_active'   => 1,
			'expires_at'  => null,
			'allowed_ips' => null,
		]);

		$req = $this->req(server: ['HTTP_AUTHORIZATION' => 'Bearer shpd_ak_abc123def456ghi']);
		$result = $this->middleware->handle($req, $this->route(), $this->db);

		$this->assertInstanceOf(AuthContext::class, $result);
		$this->assertTrue($result->isAuthenticated);
		$this->assertSame(42, $result->userId);
		$this->assertSame('api_key', $result->tokenType);
		$this->assertFalse($result->isAdmin);
		$this->assertTrue($this->middleware->lastUsedUpdated);
	}

	public function testApiKeyWithFutureExpiryIsValid(): void
	{
		$this->middleware->setMockApiKeyRow([
			'id'          => 1,
			'user_id'     => 5,
			'is_active'   => 1,
			'expires_at'  => $this->futureTimestamp(),
			'allowed_ips' => null,
		]);

		$req = $this->req(server: ['HTTP_AUTHORIZATION' => 'Bearer shpd_ak_abc123def456ghi']);
		$result = $this->middleware->handle($req, $this->route(), $this->db);

		$this->assertInstanceOf(AuthContext::class, $result);
		$this->assertTrue($result->isAuthenticated);
	}

	public function testExpiredApiKeyReturns401(): void
	{
		$this->middleware->setMockApiKeyRow([
			'id'          => 1,
			'user_id'     => 5,
			'is_active'   => 1,
			'expires_at'  => $this->pastTimestamp(),
			'allowed_ips' => null,
		]);

		$req = $this->req(server: ['HTTP_AUTHORIZATION' => 'Bearer shpd_ak_abc123def456ghi']);
		$result = $this->middleware->handle($req, $this->route(), $this->db);

		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame(401, $this->getStatus($result));
	}

	public function testInactiveApiKeyReturns401(): void
	{
		$this->middleware->setMockApiKeyRow([
			'id'          => 1,
			'user_id'     => 5,
			'is_active'   => 0,
			'expires_at'  => null,
			'allowed_ips' => null,
		]);

		$req = $this->req(server: ['HTTP_AUTHORIZATION' => 'Bearer shpd_ak_abc123def456ghi']);
		$result = $this->middleware->handle($req, $this->route(), $this->db);

		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame(401, $this->getStatus($result));
	}

	public function testUnknownApiKeyReturns401(): void
	{
		$this->middleware->setMockApiKeyRow(null);

		$req = $this->req(server: ['HTTP_AUTHORIZATION' => 'Bearer shpd_ak_abc123def456ghi']);
		$result = $this->middleware->handle($req, $this->route(), $this->db);

		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame(401, $this->getStatus($result));
		$this->assertSame('UNAUTHORIZED', $result->getPayload()['error']['code']);
	}

	public function testApiKeyIpRestrictionAllowsMatchingIp(): void
	{
		$this->middleware->setMockApiKeyRow([
			'id'          => 1,
			'user_id'     => 5,
			'is_active'   => 1,
			'expires_at'  => null,
			'allowed_ips' => json_encode(['10.0.0.1', '192.168.1.1']),
		]);

		$req = $this->req(server: [
			'HTTP_AUTHORIZATION' => 'Bearer shpd_ak_abc123def456ghi',
			'REMOTE_ADDR'        => '10.0.0.1',
		]);
		$result = $this->middleware->handle($req, $this->route(), $this->db);

		$this->assertInstanceOf(AuthContext::class, $result);
		$this->assertTrue($result->isAuthenticated);
	}

	public function testApiKeyIpRestrictionBlocksOtherIp(): void
	{
		$this->middleware->setMockApiKeyRow([
			'id'          => 1,
			'user_id'     => 5,
			'is_active'   => 1,
			'expires_at'  => null,
			'allowed_ips' => json_encode(['10.0.0.1']),
		]);

		$req = $this->req(server: [
			'HTTP_AUTHORIZATION' => 'Bearer shpd_ak_abc123def456ghi',
			'REMOTE_ADDR'        => '192.168.1.99',
		]);
		$result = $this->middleware->handle($req, $this->route(), $this->db);

		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame(401, $this->getStatus($result));
	}

	// --- Valid session token ---

	public function testValidSessionTokenReturnsAuthenticatedContext(): void
	{
		$this->middleware->setMockSessionRow([
			'id'             => 3,
			'user_id'        => 99,
			'token'          => 'shpd_st_sometoken',
			'expires'        => $this->futureTimestamp(),
			'user_is_admin'  => 0,
			'user_is_active' => 1,
		]);

		$req = $this->req(server: ['HTTP_AUTHORIZATION' => 'Bearer shpd_st_sometoken']);
		$result = $this->middleware->handle($req, $this->route(), $this->db);

		$this->assertInstanceOf(AuthContext::class, $result);
		$this->assertTrue($result->isAuthenticated);
		$this->assertSame(99, $result->userId);
		$this->assertSame('session', $result->tokenType);
		$this->assertSame('shpd_st_sometoken', $result->token);
		$this->assertFalse($result->isAdmin);
	}

	public function testAdminSessionSetsIsAdmin(): void
	{
		$this->middleware->setMockSessionRow([
			'id'             => 3,
			'user_id'        => 99,
			'token'          => 'shpd_st_sometoken',
			'expires'        => $this->futureTimestamp(),
			'user_is_admin'  => 1,
			'user_is_active' => 1,
		]);

		$req = $this->req(server: ['HTTP_AUTHORIZATION' => 'Bearer shpd_st_sometoken']);
		$result = $this->middleware->handle($req, $this->route(), $this->db);

		$this->assertInstanceOf(AuthContext::class, $result);
		$this->assertTrue($result->isAdmin);
	}

	public function testInactiveUserSessionReturns401(): void
	{
		// Platná session, ale účet mezitím deaktivovaný — musí být odmítnuta.
		$this->middleware->setMockSessionRow([
			'id'             => 3,
			'user_id'        => 99,
			'token'          => 'shpd_st_sometoken',
			'expires'        => $this->futureTimestamp(),
			'user_is_admin'  => 1,
			'user_is_active' => 0,
		]);

		$req = $this->req(server: ['HTTP_AUTHORIZATION' => 'Bearer shpd_st_sometoken']);
		$result = $this->middleware->handle($req, $this->route(), $this->db);

		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame(401, $this->getStatus($result));
	}

	public function testExpiredSessionTokenReturns401(): void
	{
		$this->middleware->setMockSessionRow([
			'id'             => 3,
			'user_id'        => 99,
			'token'          => 'shpd_st_sometoken',
			'expires'        => $this->pastTimestamp(),
			'user_is_admin'  => 0,
			'user_is_active' => 1,
		]);

		$req = $this->req(server: ['HTTP_AUTHORIZATION' => 'Bearer shpd_st_sometoken']);
		$result = $this->middleware->handle($req, $this->route(), $this->db);

		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame(401, $this->getStatus($result));
	}

	public function testUnknownSessionTokenReturns401(): void
	{
		$this->middleware->setMockSessionRow(null);

		$req = $this->req(server: ['HTTP_AUTHORIZATION' => 'Bearer shpd_st_unknowntoken']);
		$result = $this->middleware->handle($req, $this->route(), $this->db);

		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame(401, $this->getStatus($result));
	}

	// --- Unknown/invalid token prefix ---

	public function testUnknownTokenPrefixReturns401(): void
	{
		$req = $this->req(server: ['HTTP_AUTHORIZATION' => 'Bearer shpd_xx_unknown']);
		$result = $this->middleware->handle($req, $this->route(), $this->db);

		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame(401, $this->getStatus($result));
	}

	public function testMalformedAuthorizationHeaderReturns401(): void
	{
		$req = $this->req(server: ['HTTP_AUTHORIZATION' => 'Token abc123']);
		$result = $this->middleware->handle($req, $this->route(), $this->db);

		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame(401, $this->getStatus($result));
	}

	private function getStatus(Response $response): int
	{
		$ref = new \ReflectionClass($response);
		$prop = $ref->getProperty('status');
		return $prop->getValue($response);
	}
}
