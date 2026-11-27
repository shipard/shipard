<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Request;
use Shipard\Api\RequestBaseUrl;
use Shipard\Api\Response;
use Shipard\Api\SessionService;
use Shipard\Core\Auth\GithubOauthClient;
use Shipard\Core\Auth\IdentityMapper;
use Shipard\Core\Auth\OidcClient;
use Shipard\Core\Auth\OidcDiscovery;
use Shipard\Core\Auth\OidcException;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;

/**
 * OIDC relying party endpointy (D1, D11, D13):
 *
 *   GET  /_auth/oidc/start?provider=x  302 na authorize URL providera;
 *                                      volitelné `return=` (validovaný
 *                                      query-suffix) se uloží do transakce
 *   GET  /_auth/oidc/callback          návrat od IdP → session + handoff kód,
 *                                      302 na /app/?login=oidc&code={handoff}
 *                                      (+ return_to suffix, např. &op_auth=…)
 *   POST /_auth/oidc/exchange          {code} → login envelope (token nikdy v URL)
 *
 * Chybové větve callbacku končí 302 na /app/?login_error={kod} — kódy viz
 * OidcException. Transakce flow žijí v core_system_auth_transactions:
 * start → callback (naplní handoff_code + session_token, expires +60 s)
 * → exchange (smaže řádek; kód je single-use).
 */
class OidcController
{
	private const int TRANSACTION_TTL_SECONDS = 600;
	private const int HANDOFF_TTL_SECONDS = 60;

	public function __construct(
		private readonly DataSourceConfig $config,
		private readonly bool $pathAddressed,
		private readonly SessionService $sessions = new SessionService(),
		private readonly IdentityMapper $mapper = new IdentityMapper(),
		private readonly ?OidcClient $client = null,
		private readonly ?GithubOauthClient $github = null,
	) {}

	public function start(Request $request, DataSourceConnection $db): Response
	{
		$providerId = (string) ($request->getQueryParams()['provider'] ?? '');
		$provider = $this->config->getAuthPolicy()->getProvider($providerId);
		if ($provider === null) {
			return Response::error('NOT_FOUND', 'Unknown OIDC provider', 404);
		}

		// Oportunistický úklid — expirované transakce nikdo jiný nemaže.
		$db->execute(
			'DELETE FROM core_system_auth_transactions WHERE expires < %s',
			date('Y-m-d H:i:s'),
		);

		$state = $this->urlSafeToken(32);
		$nonce = $this->urlSafeToken(32);
		$codeVerifier = $this->urlSafeToken(48);
		$codeChallenge = $this->base64url(hash('sha256', $codeVerifier, true));

		$returnTo = (string) ($request->getQueryParams()['return'] ?? '');
		if ($returnTo !== '' && !self::isValidReturnTo($returnTo)) {
			ErrorLogger::warn('OIDC start: invalid return param ignored', ['provider' => $provider->id]);
			$returnTo = '';
		}

		$db->insertRow('core_system_auth_transactions', [
			'state'         => $state,
			'provider'      => $provider->id,
			'pkce_verifier' => $codeVerifier,
			'nonce'         => $nonce,
			'return_to'     => $returnTo !== '' ? $returnTo : null,
			'created'       => date('Y-m-d H:i:s'),
			'expires'       => date('Y-m-d H:i:s', time() + self::TRANSACTION_TTL_SECONDS),
		]);

		try {
			// github: bez PKCE a nonce (OAuth apps je nepodporují) — transakce
			// je nese dál (vygenerované, nepoužité), CSRF drží single-use state.
			$url = $provider->kind === 'github'
				? $this->githubClient()->buildAuthorizeUrl($provider, $state, $this->callbackUri($request))
				: $this->oidcClient()->buildAuthorizeUrl(
					$provider,
					$state,
					$nonce,
					$codeChallenge,
					$this->callbackUri($request),
				);
		} catch (OidcException $e) {
			ErrorLogger::warn('OIDC start failed', ['provider' => $provider->id, 'error' => $e->getMessage()]);
			return $this->redirectError($request, $e->errorCode);
		}

		return Response::redirect($url);
	}

	public function callback(Request $request, DataSourceConnection $db): Response
	{
		$query = $request->getQueryParams();

		if (isset($query['error'])) {
			return $this->redirectError($request, 'oidc_denied');
		}

		$state = (string) ($query['state'] ?? '');
		$code = (string) ($query['code'] ?? '');
		if ($state === '' || $code === '') {
			return $this->redirectError($request, 'oidc_invalid_state');
		}

		$txn = $db->fetchRow(
			'SELECT * FROM core_system_auth_transactions WHERE state = %s',
			$state,
		);
		// handoff_code už vyplněný = state replay po úspěšném callbacku.
		if ($txn === null
			|| strtotime((string) $txn['expires']) < time()
			|| $txn['handoff_code'] !== null) {
			return $this->redirectError($request, 'oidc_invalid_state');
		}

		$provider = $this->config->getAuthPolicy()->getProvider((string) $txn['provider']);
		if ($provider === null) {
			return $this->redirectError($request, 'oidc_provider_error');
		}

		try {
			if ($provider->kind === 'github') {
				$identity = $this->githubClient()->fetchIdentity($provider, $code, $this->callbackUri($request));
			} else {
				$client = $this->oidcClient();
				$tokens = $client->exchangeCode(
					$provider,
					$code,
					(string) $txn['pkce_verifier'],
					$this->callbackUri($request),
				);
				$identity = $client->validateIdToken($provider, (string) $tokens['id_token'], (string) $txn['nonce']);
			}
			$userId = $this->mapper->resolve($identity, $provider, $db);
		} catch (OidcException $e) {
			ErrorLogger::warn('OIDC callback failed', ['provider' => $provider->id, 'code' => $e->errorCode, 'error' => $e->getMessage()]);
			return $this->redirectError($request, $e->errorCode);
		}

		[$sessionToken] = $this->sessions->createSession($userId, $db, $request->getClientIp());

		$handoffCode = $this->urlSafeToken(32);
		$db->execute(
			'UPDATE core_system_auth_transactions SET handoff_code = %s, session_token = %s, expires = %s WHERE id = %i',
			$handoffCode,
			$sessionToken,
			date('Y-m-d H:i:s', time() + self::HANDOFF_TTL_SECONDS),
			(int) $txn['id'],
		);

		$redirect = $this->appUrl($request) . '?login=oidc&code=' . rawurlencode($handoffCode);
		// Defense in depth: hodnota z DB se validuje znovu — mohla vzniknout
		// starším kódem nebo přímým zápisem.
		$returnTo = (string) ($txn['return_to'] ?? '');
		if ($returnTo !== '' && self::isValidReturnTo($returnTo)) {
			$redirect .= '&' . substr($returnTo, 1);
		}

		return Response::redirect($redirect);
	}

	public function exchange(Request $request, DataSourceConnection $db): Response
	{
		$code = (string) ($request->getBody()['code'] ?? '');
		if ($code === '') {
			return Response::error('UNAUTHORIZED', 'Invalid handoff code', 401);
		}

		$txn = $db->fetchRow(
			'SELECT * FROM core_system_auth_transactions WHERE handoff_code = %s',
			$code,
		);
		if ($txn === null) {
			return Response::error('UNAUTHORIZED', 'Invalid handoff code', 401);
		}

		// Single-use: smazat hned, i expirovaný — replay nesmí projít.
		$db->execute(
			'DELETE FROM core_system_auth_transactions WHERE id = %i',
			(int) $txn['id'],
		);

		if (strtotime((string) $txn['expires']) < time() || $txn['session_token'] === null) {
			return Response::error('UNAUTHORIZED', 'Invalid handoff code', 401);
		}

		$session = $db->fetchRow(
			'SELECT * FROM core_system_sessions WHERE token = %s',
			(string) $txn['session_token'],
		);
		if ($session === null) {
			return Response::error('UNAUTHORIZED', 'Invalid handoff code', 401);
		}

		$user = $db->fetchRow(
			'SELECT * FROM core_system_users WHERE id = %i',
			(int) $session['user_id'],
		);
		if ($user === null) {
			return Response::error('UNAUTHORIZED', 'Invalid handoff code', 401);
		}

		// Stejný envelope jako /_auth/login — frontend volá authStore.setAuth().
		return Response::success([
			'token'      => (string) $txn['session_token'],
			'expires_at' => date('c', strtotime((string) $session['expires'])),
			'user'       => [
				'id'           => (int) $user['id'],
				'login'        => $user['login'],
				'full_name'    => $user['full_name'],
				'is_admin'     => (bool) ($user['is_admin'] ?? false),
				'has_password' => ($user['password_hash'] ?? null) !== null,
			],
		]);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	protected function oidcClient(): OidcClient
	{
		return $this->client ?? new OidcClient(
			new OidcDiscovery($this->config->getDataSourceDir() . '/cache/oidc'),
		);
	}

	protected function githubClient(): GithubOauthClient
	{
		return $this->github ?? new GithubOauthClient();
	}

	/** Absolutní base URL požadavku — schéma z požadavku, prefix `/{ds-id}` při adresování cestou. */
	private function baseUrl(Request $request): string
	{
		return RequestBaseUrl::of($request, $this->config->getId(), $this->pathAddressed);
	}

	private function callbackUri(Request $request): string
	{
		return $this->baseUrl($request) . '/api/v1/_auth/oidc/callback';
	}

	private function appUrl(Request $request): string
	{
		return $this->baseUrl($request) . '/app/';
	}

	/**
	 * Návratový suffix (`?klic=hodnota&…`) pro pokračování po loginu — dnes
	 * `?op_auth={txn}` (OP flow kontinuita). Jen klíč=hodnota páry bez
	 * URL-významových znaků: žádné cesty, žádná plná URL, žádné procentové
	 * kódování — open redirect nemá kudy. Budoucí použití ať pravidlo
	 * rozšiřuje vědomě.
	 */
	private static function isValidReturnTo(string $v): bool
	{
		return strlen($v) <= 200
			&& preg_match('/^\?[A-Za-z0-9_\-]+=[A-Za-z0-9_\-]+(&[A-Za-z0-9_\-]+=[A-Za-z0-9_\-]+)*$/', $v) === 1;
	}

	private function redirectError(Request $request, string $errorCode): Response
	{
		return Response::redirect($this->appUrl($request) . '?login_error=' . rawurlencode($errorCode));
	}

	private function urlSafeToken(int $bytes): string
	{
		return $this->base64url(random_bytes($bytes));
	}

	private function base64url(string $binary): string
	{
		return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
	}
}
