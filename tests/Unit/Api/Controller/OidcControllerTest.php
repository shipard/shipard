<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\OidcController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Auth\GithubOauthClient;
use Shipard\Core\Auth\OidcClient;
use Shipard\Core\Auth\OidcDiscovery;
use Shipard\Core\Auth\OidcException;
use Shipard\Core\Auth\OidcIdentity;
use Shipard\Core\Auth\OidcProviderConfig;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Tests\Fixtures\Core\Auth\InMemoryAuthDb;

/** OidcClient bez HTTP — vrací předpřipravenou identitu, nebo hází. */
class StubOidcClient extends OidcClient
{
	public ?OidcIdentity $identity = null;
	public ?OidcException $failWith = null;
	public array $exchangeCalls = [];

	public function __construct()
	{
		parent::__construct(new OidcDiscovery(sys_get_temp_dir() . '/shpd-oidc-test-unused'));
	}

	public function buildAuthorizeUrl(
		OidcProviderConfig $provider,
		string $state,
		string $nonce,
		string $codeChallenge,
		string $redirectUri,
	): string {
		return 'https://idp.example.com/realm/authorize?' . http_build_query([
			'client_id'      => $provider->clientId,
			'state'          => $state,
			'nonce'          => $nonce,
			'code_challenge' => $codeChallenge,
			'redirect_uri'   => $redirectUri,
		]);
	}

	public function exchangeCode(
		OidcProviderConfig $provider,
		string $code,
		string $codeVerifier,
		string $redirectUri,
	): array {
		$this->exchangeCalls[] = ['code' => $code, 'verifier' => $codeVerifier, 'redirectUri' => $redirectUri];
		if ($this->failWith !== null) {
			throw $this->failWith;
		}
		return ['id_token' => 'stub-id-token'];
	}

	public function validateIdToken(OidcProviderConfig $provider, string $jwt, string $expectedNonce): OidcIdentity
	{
		if ($this->failWith !== null) {
			throw $this->failWith;
		}
		return $this->identity ?? throw new \LogicException('StubOidcClient: no identity set');
	}
}

/** GithubOauthClient bez HTTP — vrací předpřipravenou identitu, nebo hází. */
class StubGithubClient extends GithubOauthClient
{
	public ?OidcIdentity $identity = null;
	public ?OidcException $failWith = null;
	public array $fetchCalls = [];

	public function fetchIdentity(OidcProviderConfig $provider, string $code, string $redirectUri): OidcIdentity
	{
		$this->fetchCalls[] = ['code' => $code, 'redirectUri' => $redirectUri];
		if ($this->failWith !== null) {
			throw $this->failWith;
		}
		return $this->identity ?? throw new \LogicException('StubGithubClient: no identity set');
	}
}

class OidcControllerTest extends TestCase
{
	private const DS_ID = 'abcd-efgh-ijkl-mnop';

	private string $dsDir;
	private InMemoryAuthDb $db;
	private StubOidcClient $client;
	private StubGithubClient $github;
	private OidcController $controller;

	protected function setUp(): void
	{
		$this->dsDir = sys_get_temp_dir() . '/shpd-oidc-ctrl-test-' . uniqid();
		mkdir($this->dsDir . '/config', 0755, true);
		file_put_contents($this->dsDir . '/config/main.json', json_encode([
			'id'            => self::DS_ID,
			'name'          => 'Test DS',
			'database_name' => 'test_db',
			'database_user' => 'test_user',
			'created'       => '2026-01-01 00:00:00',
			'auth'          => [
				'local'     => true,
				'providers' => [
					[
						'id'            => 'test',
						'label'         => 'Test IdP',
						'issuer'        => 'https://idp.example.com/realm',
						'clientId'      => 'cid',
						'autoLinkEmail' => true,
					],
					[
						'id'            => 'github',
						'label'         => 'GitHub',
						'kind'          => 'github',
						'clientId'      => 'gh-cid',
						'autoLinkEmail' => true,
					],
				],
			],
		]));
		// clientSecret žije v secrets/ (#126 B2) — hodnoty zjevně falešné.
		mkdir($this->dsDir . '/secrets', 0700);
		file_put_contents($this->dsDir . '/secrets/oidc-test.client-secret', 'secret');
		file_put_contents($this->dsDir . '/secrets/oidc-github.client-secret', 'gh-secret');

		$this->db = InMemoryAuthDb::create();
		$this->client = new StubOidcClient();
		$this->github = new StubGithubClient();
		$this->controller = new OidcController(
			new DataSourceConfig($this->dsDir),
			devMode: true,
			client: $this->client,
			github: $this->github,
		);
	}

	protected function tearDown(): void
	{
		@unlink($this->dsDir . '/config/main.json');
		@rmdir($this->dsDir . '/config');
		@unlink($this->dsDir . '/secrets/oidc-test.client-secret');
		@unlink($this->dsDir . '/secrets/oidc-github.client-secret');
		@rmdir($this->dsDir . '/secrets');
		@rmdir($this->dsDir);
	}

	private function getStatus(Response $response): int
	{
		$ref = new \ReflectionClass($response);
		return $ref->getProperty('status')->getValue($response);
	}

	private function req(string $method, string $path, array $query = [], array $body = []): Request
	{
		return Request::fromArray(
			$method,
			'/api/v1' . $path,
			$query,
			$body === [] ? '' : json_encode($body),
			['HTTP_HOST' => '127.0.0.1', 'REMOTE_ADDR' => '10.0.0.1'],
		);
	}

	// --- start ---

	public function testStartRedirectsToAuthorizeUrlAndStoresTransaction(): void
	{
		$response = $this->controller->start(
			$this->req('GET', '/_auth/oidc/start', ['provider' => 'test']),
			$this->db,
		);

		$this->assertSame(302, $this->getStatus($response));
		$location = $response->getHeaders()['Location'];
		$this->assertStringStartsWith('https://idp.example.com/realm/authorize?', $location);

		$this->assertCount(1, $this->db->transactions);
		$txn = array_values($this->db->transactions)[0];

		parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
		$this->assertSame($txn['state'], $params['state']);
		$this->assertSame($txn['nonce'], $params['nonce']);
		// challenge = S256(verifier)
		$expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $txn['pkce_verifier'], true)), '+/', '-_'), '=');
		$this->assertSame($expectedChallenge, $params['code_challenge']);
		// Dev mód: redirect URI nese /{ds-id} prefix a http scheme.
		$this->assertSame(
			'http://127.0.0.1/' . self::DS_ID . '/api/v1/_auth/oidc/callback',
			$params['redirect_uri'],
		);
		$this->assertGreaterThan(time(), strtotime((string) $txn['expires']));
	}

	public function testStartWithUnknownProviderReturns404(): void
	{
		$response = $this->controller->start(
			$this->req('GET', '/_auth/oidc/start', ['provider' => 'nope']),
			$this->db,
		);

		$this->assertSame(404, $this->getStatus($response));
	}

	public function testStartDeletesExpiredTransactions(): void
	{
		$this->db->addTransaction([
			'state'   => 'old-state',
			'expires' => date('Y-m-d H:i:s', time() - 3600),
		]);

		$this->controller->start($this->req('GET', '/_auth/oidc/start', ['provider' => 'test']), $this->db);

		$states = array_column($this->db->transactions, 'state');
		$this->assertNotContains('old-state', $states);
	}

	// --- return_to (D1 — návrat do OP transakce) ---

	#[DataProvider('returnToProvider')]
	public function testIsValidReturnTo(string $value, bool $expected): void
	{
		$method = new \ReflectionMethod(OidcController::class, 'isValidReturnTo');
		$this->assertSame($expected, $method->invoke(null, $value));
	}

	public static function returnToProvider(): array
	{
		return [
			'op_auth pár'          => ['?op_auth=abc', true],
			'více párů'            => ['?op_auth=abc&x=1', true],
			'prázdné'              => ['', false],
			'cesta'                => ['/cesta', false],
			'trailing ampersand'   => ['?a=b&', false],
			'plná URL'             => ['https://evil.example/x', false],
			'fragment'             => ['?a=b#f', false],
			'procentové kódování'  => ['?a=%2F', false],
			'přes 200 znaků'       => ['?a=' . str_repeat('b', 200), false],
			'protocol-relative'    => ['//x', false],
			'bez otazníku'         => ['op_auth=abc', false],
		];
	}

	public function testStartStoresValidReturnTo(): void
	{
		$response = $this->controller->start(
			$this->req('GET', '/_auth/oidc/start', ['provider' => 'test', 'return' => '?op_auth=txn-1']),
			$this->db,
		);

		$this->assertSame(302, $this->getStatus($response));
		$txn = array_values($this->db->transactions)[0];
		$this->assertSame('?op_auth=txn-1', $txn['return_to']);
	}

	public function testStartIgnoresInvalidReturn(): void
	{
		$response = $this->controller->start(
			$this->req('GET', '/_auth/oidc/start', ['provider' => 'test', 'return' => 'https://evil.example/x']),
			$this->db,
		);

		// Start projde, transakce ale return_to nenese.
		$this->assertSame(302, $this->getStatus($response));
		$txn = array_values($this->db->transactions)[0];
		$this->assertNull($txn['return_to']);
	}

	public function testCallbackAppendsReturnToSuffix(): void
	{
		$userId = $this->db->addUser(['login' => 'jan', 'email' => 'jan@example.com', 'is_active' => 1, 'full_name' => 'Jan']);
		$this->db->addIdentity(['user_id' => $userId, 'issuer' => 'https://idp.example.com/realm', 'subject' => 'user-42']);
		$this->client->identity = new OidcIdentity('https://idp.example.com/realm', 'user-42', 'jan@example.com', true, 'Jan');

		$this->controller->start(
			$this->req('GET', '/_auth/oidc/start', ['provider' => 'test', 'return' => '?op_auth=txn-1']),
			$this->db,
		);
		$txn = array_values($this->db->transactions)[0];

		$response = $this->controller->callback(
			$this->req('GET', '/_auth/oidc/callback', ['state' => $txn['state'], 'code' => 'authcode-1']),
			$this->db,
		);

		$location = $response->getHeaders()['Location'];
		$this->assertStringStartsWith('http://127.0.0.1/' . self::DS_ID . '/app/?login=oidc&code=', $location);
		parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
		$this->assertSame('txn-1', $params['op_auth']);
	}

	public function testCallbackIgnoresInvalidStoredReturnTo(): void
	{
		$userId = $this->db->addUser(['login' => 'jan', 'email' => 'jan@example.com', 'is_active' => 1, 'full_name' => 'Jan']);
		$this->db->addIdentity(['user_id' => $userId, 'issuer' => 'https://idp.example.com/realm', 'subject' => 'user-42']);
		$this->client->identity = new OidcIdentity('https://idp.example.com/realm', 'user-42', 'jan@example.com', true, 'Jan');

		$txn = $this->startedTransaction();
		// Podvržený řádek (starší kód / přímý zápis) — callback musí validovat znovu.
		$this->db->transactions[$txn['id']]['return_to'] = '?redirect=x&url=https://evil';

		$response = $this->controller->callback(
			$this->req('GET', '/_auth/oidc/callback', ['state' => $txn['state'], 'code' => 'authcode-1']),
			$this->db,
		);

		$location = $response->getHeaders()['Location'];
		parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
		$this->assertArrayNotHasKey('redirect', $params);
		$this->assertArrayNotHasKey('url', $params);
		$this->assertArrayHasKey('code', $params);
	}

	// --- callback ---

	private function startedTransaction(): array
	{
		$this->controller->start($this->req('GET', '/_auth/oidc/start', ['provider' => 'test']), $this->db);
		return array_values($this->db->transactions)[0];
	}

	public function testCallbackSuccessCreatesSessionAndRedirectsWithHandoff(): void
	{
		$userId = $this->db->addUser(['login' => 'jan', 'email' => 'jan@example.com', 'is_active' => 1, 'full_name' => 'Jan']);
		$this->db->addIdentity(['user_id' => $userId, 'issuer' => 'https://idp.example.com/realm', 'subject' => 'user-42']);
		$this->client->identity = new OidcIdentity('https://idp.example.com/realm', 'user-42', 'jan@example.com', true, 'Jan');

		$txn = $this->startedTransaction();
		$response = $this->controller->callback(
			$this->req('GET', '/_auth/oidc/callback', ['state' => $txn['state'], 'code' => 'authcode-1']),
			$this->db,
		);

		$this->assertSame(302, $this->getStatus($response));
		$location = $response->getHeaders()['Location'];
		$this->assertStringStartsWith('http://127.0.0.1/' . self::DS_ID . '/app/?login=oidc&code=', $location);

		// Exchange dostal PKCE verifier z transakce.
		$this->assertSame($txn['pkce_verifier'], $this->client->exchangeCalls[0]['verifier']);

		// Session vznikla a transakce nese handoff + session token.
		$this->assertCount(1, $this->db->sessions);
		$session = array_values($this->db->sessions)[0];
		$this->assertSame($userId, $session['user_id']);
		$this->assertSame('10.0.0.1', $session['ip_address']);
		$updated = $this->db->transactions[$txn['id']];
		$this->assertNotNull($updated['handoff_code']);
		$this->assertSame($session['token'], $updated['session_token']);
	}

	public function testCallbackWithIdpErrorRedirectsDenied(): void
	{
		$response = $this->controller->callback(
			$this->req('GET', '/_auth/oidc/callback', ['error' => 'access_denied']),
			$this->db,
		);

		$this->assertSame(302, $this->getStatus($response));
		$this->assertStringContainsString('login_error=oidc_denied', $response->getHeaders()['Location']);
	}

	public function testCallbackWithUnknownStateRedirectsInvalidState(): void
	{
		$response = $this->controller->callback(
			$this->req('GET', '/_auth/oidc/callback', ['state' => 'bogus', 'code' => 'x']),
			$this->db,
		);

		$this->assertStringContainsString('login_error=oidc_invalid_state', $response->getHeaders()['Location']);
	}

	public function testCallbackWithExpiredStateRedirectsInvalidState(): void
	{
		$txn = $this->startedTransaction();
		$this->db->transactions[$txn['id']]['expires'] = date('Y-m-d H:i:s', time() - 10);

		$response = $this->controller->callback(
			$this->req('GET', '/_auth/oidc/callback', ['state' => $txn['state'], 'code' => 'x']),
			$this->db,
		);

		$this->assertStringContainsString('login_error=oidc_invalid_state', $response->getHeaders()['Location']);
	}

	public function testCallbackStateReplayAfterSuccessIsRejected(): void
	{
		$userId = $this->db->addUser(['login' => 'jan', 'email' => 'jan@example.com', 'is_active' => 1, 'full_name' => 'Jan']);
		$this->db->addIdentity(['user_id' => $userId, 'issuer' => 'https://idp.example.com/realm', 'subject' => 'user-42']);
		$this->client->identity = new OidcIdentity('https://idp.example.com/realm', 'user-42', 'jan@example.com', true, 'Jan');

		$txn = $this->startedTransaction();
		$req = $this->req('GET', '/_auth/oidc/callback', ['state' => $txn['state'], 'code' => 'authcode-1']);

		$first = $this->controller->callback($req, $this->db);
		$this->assertStringContainsString('login=oidc', $first->getHeaders()['Location']);

		$second = $this->controller->callback($req, $this->db);
		$this->assertStringContainsString('login_error=oidc_invalid_state', $second->getHeaders()['Location']);
	}

	public function testCallbackMapperFailureRedirectsWithDomainCode(): void
	{
		$this->client->identity = new OidcIdentity('https://idp.example.com/realm', 'user-42', null, false, null);

		$txn = $this->startedTransaction();
		$response = $this->controller->callback(
			$this->req('GET', '/_auth/oidc/callback', ['state' => $txn['state'], 'code' => 'authcode-1']),
			$this->db,
		);

		$this->assertStringContainsString('login_error=oidc_no_account', $response->getHeaders()['Location']);
		$this->assertCount(0, $this->db->sessions);
	}

	public function testCallbackProviderFailureRedirectsProviderError(): void
	{
		$this->client->failWith = new OidcException('oidc_provider_error', 'exchange failed');

		$txn = $this->startedTransaction();
		$response = $this->controller->callback(
			$this->req('GET', '/_auth/oidc/callback', ['state' => $txn['state'], 'code' => 'authcode-1']),
			$this->db,
		);

		$this->assertStringContainsString('login_error=oidc_provider_error', $response->getHeaders()['Location']);
	}

	// --- github kind (D3) ---

	public function testStartWithGithubProviderUsesGithubAuthorizeUrl(): void
	{
		$response = $this->controller->start(
			$this->req('GET', '/_auth/oidc/start', ['provider' => 'github']),
			$this->db,
		);

		$this->assertSame(302, $this->getStatus($response));
		$location = $response->getHeaders()['Location'];
		$this->assertStringStartsWith('https://github.com/login/oauth/authorize?', $location);

		$txn = array_values($this->db->transactions)[0];
		parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
		$this->assertSame('gh-cid', $params['client_id']);
		$this->assertSame($txn['state'], $params['state']);
		$this->assertSame('github', $txn['provider']);
		// Bez PKCE a nonce v URL — GitHub OAuth apps je nepodporují.
		$this->assertArrayNotHasKey('code_challenge', $params);
		$this->assertArrayNotHasKey('nonce', $params);
	}

	public function testGithubCallbackMapsIdentityAndIssuesHandoff(): void
	{
		$userId = $this->db->addUser(['login' => 'jan', 'email' => 'jan@example.com', 'is_active' => 1, 'full_name' => 'Jan']);
		$this->db->addIdentity(['user_id' => $userId, 'issuer' => 'https://github.com', 'subject' => '123456']);
		$this->github->identity = new OidcIdentity('https://github.com', '123456', 'jan@example.com', true, 'Jan');

		$this->controller->start($this->req('GET', '/_auth/oidc/start', ['provider' => 'github']), $this->db);
		$txn = array_values($this->db->transactions)[0];

		$response = $this->controller->callback(
			$this->req('GET', '/_auth/oidc/callback', ['state' => $txn['state'], 'code' => 'gh-code']),
			$this->db,
		);

		$this->assertSame(302, $this->getStatus($response));
		$this->assertStringStartsWith(
			'http://127.0.0.1/' . self::DS_ID . '/app/?login=oidc&code=',
			$response->getHeaders()['Location'],
		);
		$this->assertSame('gh-code', $this->github->fetchCalls[0]['code']);
		// OIDC klient se pro github vůbec nevolá.
		$this->assertSame([], $this->client->exchangeCalls);

		$session = array_values($this->db->sessions)[0];
		$this->assertSame($userId, $session['user_id']);
	}

	public function testGithubCallbackProviderFailureRedirectsProviderError(): void
	{
		$this->github->failWith = new OidcException('oidc_provider_error', 'token exchange failed');

		$this->controller->start($this->req('GET', '/_auth/oidc/start', ['provider' => 'github']), $this->db);
		$txn = array_values($this->db->transactions)[0];

		$response = $this->controller->callback(
			$this->req('GET', '/_auth/oidc/callback', ['state' => $txn['state'], 'code' => 'gh-code']),
			$this->db,
		);

		$this->assertStringContainsString('login_error=oidc_provider_error', $response->getHeaders()['Location']);
	}

	// --- exchange ---

	private function completedHandoff(): string
	{
		$userId = $this->db->addUser(['login' => 'jan', 'email' => 'jan@example.com', 'is_active' => 1, 'full_name' => 'Jan Novák']);
		$this->db->addIdentity(['user_id' => $userId, 'issuer' => 'https://idp.example.com/realm', 'subject' => 'user-42']);
		$this->client->identity = new OidcIdentity('https://idp.example.com/realm', 'user-42', 'jan@example.com', true, 'Jan Novák');

		$txn = $this->startedTransaction();
		$response = $this->controller->callback(
			$this->req('GET', '/_auth/oidc/callback', ['state' => $txn['state'], 'code' => 'authcode-1']),
			$this->db,
		);
		parse_str((string) parse_url($response->getHeaders()['Location'], PHP_URL_QUERY), $params);
		return (string) $params['code'];
	}

	public function testExchangeReturnsLoginEnvelopeAndDeletesTransaction(): void
	{
		$handoff = $this->completedHandoff();

		$response = $this->controller->exchange(
			$this->req('POST', '/_auth/oidc/exchange', [], ['code' => $handoff]),
			$this->db,
		);
		$payload = $response->getPayload();

		$this->assertTrue($payload['success']);
		$this->assertStringStartsWith('shpd_st_', $payload['data']['token']);
		$this->assertArrayHasKey('expires_at', $payload['data']);
		$this->assertSame('jan', $payload['data']['user']['login']);
		$this->assertSame('Jan Novák', $payload['data']['user']['full_name']);
		$this->assertCount(0, $this->db->transactions);
	}

	public function testExchangeSecondUseReturns401(): void
	{
		$handoff = $this->completedHandoff();
		$req = $this->req('POST', '/_auth/oidc/exchange', [], ['code' => $handoff]);

		$this->controller->exchange($req, $this->db);
		$second = $this->controller->exchange($req, $this->db);

		$this->assertSame(401, $this->getStatus($second));
	}

	public function testExchangeExpiredHandoffReturns401AndDeletesRow(): void
	{
		$handoff = $this->completedHandoff();
		foreach ($this->db->transactions as $id => $txn) {
			$this->db->transactions[$id]['expires'] = date('Y-m-d H:i:s', time() - 10);
		}

		$response = $this->controller->exchange(
			$this->req('POST', '/_auth/oidc/exchange', [], ['code' => $handoff]),
			$this->db,
		);

		$this->assertSame(401, $this->getStatus($response));
		$this->assertCount(0, $this->db->transactions);
	}

	public function testExchangeUnknownCodeReturns401(): void
	{
		$response = $this->controller->exchange(
			$this->req('POST', '/_auth/oidc/exchange', [], ['code' => 'bogus']),
			$this->db,
		);

		$this->assertSame(401, $this->getStatus($response));
	}

	public function testExchangeMissingCodeReturns401(): void
	{
		$response = $this->controller->exchange(
			$this->req('POST', '/_auth/oidc/exchange', [], []),
			$this->db,
		);

		$this->assertSame(401, $this->getStatus($response));
	}
}
