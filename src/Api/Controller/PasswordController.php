<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\RequestBaseUrl;
use Shipard\Api\Response;
use Shipard\Api\SessionService;
use Shipard\Core\Auth\AuthTokenService;
use Shipard\Core\Auth\PasswordPolicy;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Mail\Exception\MailValidationException;
use Shipard\Core\Mail\MailOutboxService;
use Shipard\Core\Mail\MailServiceFactory;
use Shipard\Core\Mail\MailTemplate;
use Shipard\Core\Mail\OutboundMessage;
use Shipard\Core\Settings\SettingsStore;

/**
 * Samoobsluha lokálních účtů (auth Fáze 0b, D19–D21):
 *
 *   POST   /_auth/password/forgot        public — {identifier}, vždy 200
 *   POST   /_auth/password/reset         public — {token, password}
 *   POST   /_auth/password/change        session — {currentPassword, newPassword}
 *   POST   /_users/{id}/invite           admin — pošle pozvánkový mail
 *   GET    /_auth/sessions               session — vlastní relace
 *   DELETE /_auth/sessions/{id}          session — smaže vlastní relaci
 *   POST   /_auth/sessions/revoke-others session — odhlásí ostatní zařízení
 *
 * Forgot je anti-enumerační: odpověď (i časově — mail jde přes outbox) se
 * neliší pro existující a neexistující účet. Pozvánka je technicky reset
 * s delším TTL a jinou šablonou; obě konzumuje /password/reset.
 */
class PasswordController
{
	public function __construct(
		private readonly DataSourceConfig $config,
		private readonly bool $pathAddressed,
		private readonly SessionService $sessions = new SessionService(),
	) {}

	public function forgot(Request $request, DataSourceConnection $db): Response
	{
		$identifier = trim((string) ($request->getBody()['identifier'] ?? ''));
		if ($identifier === '') {
			return Response::error('VALIDATION_ERROR', 'Field identifier is required', 422);
		}

		foreach ($this->findAccountsForReset($identifier, $db) as $user) {
			$this->sendTokenMail($request, $db, $user, AuthTokenService::PURPOSE_PASSWORD_RESET, null);
		}

		// Vždy stejná odpověď — existence účtu se nesmí dát odvodit.
		return Response::success(['status' => 'ok']);
	}

	public function reset(Request $request, DataSourceConnection $db): Response
	{
		$body = $request->getBody() ?? [];
		$token = (string) ($body['token'] ?? '');
		$password = (string) ($body['password'] ?? '');
		$purposes = [AuthTokenService::PURPOSE_PASSWORD_RESET, AuthTokenService::PURPOSE_INVITE];

		$tokenService = $this->tokenService($db);

		// Neburning validace — chyba politiky nesmí spálit token, uživatel
		// jen opraví heslo a odešle znovu.
		$userId = $token === '' ? null : $tokenService->validate($token, $purposes);
		$user = $userId === null ? null : $db->fetchRow(
			'SELECT * FROM core_system_users WHERE id = %i',
			$userId,
		);
		if ($user === null || !$user['is_active']) {
			return Response::error('INVALID_TOKEN', 'Invalid or expired token', 400);
		}

		if (!PasswordPolicy::isValid($password, (string) $user['login'])) {
			return Response::error('PASSWORD_POLICY', 'Password does not meet the policy', 400);
		}

		// Souběh dvou resetů rozhodne atomický consume — druhý dostane 400.
		if ($tokenService->consume($token, $purposes) === null) {
			return Response::error('INVALID_TOKEN', 'Invalid or expired token', 400);
		}

		$this->setPasswordHash((int) $user['id'], $password, $db);
		$this->sessions->invalidateAllForUser((int) $user['id'], $db);

		return Response::success(['status' => 'ok']);
	}

	public function change(Request $request, AuthContext $auth, DataSourceConnection $db): Response
	{
		if (!$auth->isAuthenticated || $auth->tokenType !== 'session' || $auth->token === null) {
			return Response::error('UNAUTHORIZED', 'Valid session token required', 401);
		}

		$body = $request->getBody() ?? [];
		$current = (string) ($body['currentPassword'] ?? '');
		$new = (string) ($body['newPassword'] ?? '');

		$user = $db->fetchRow(
			'SELECT * FROM core_system_users WHERE id = %i',
			(int) $auth->userId,
		);
		if ($user === null) {
			return Response::error('UNAUTHORIZED', 'Valid session token required', 401);
		}

		if ($user['password_hash'] === null) {
			return Response::error('NO_LOCAL_PASSWORD', 'Account has no local password', 400);
		}

		if (!password_verify($current, (string) $user['password_hash'])) {
			return Response::error('UNAUTHORIZED', 'Invalid current password', 401);
		}

		if (!PasswordPolicy::isValid($new, (string) $user['login'])) {
			return Response::error('PASSWORD_POLICY', 'Password does not meet the policy', 400);
		}

		$this->setPasswordHash((int) $user['id'], $new, $db);
		$revoked = $this->sessions->invalidateOthers((int) $user['id'], $auth->token, $db);

		return Response::success(['status' => 'ok', 'revoked_sessions' => $revoked]);
	}

	public function invite(Request $request, AuthContext $auth, DataSourceConnection $db, int $userId): Response
	{
		if (!$auth->isAuthenticated || !$auth->isAdmin) {
			return Response::error('FORBIDDEN', 'Administrator session required', 403);
		}

		$user = $db->fetchRow(
			'SELECT * FROM core_system_users WHERE id = %i',
			$userId,
		);
		if ($user === null) {
			return Response::error('NOT_FOUND', 'User not found', 404);
		}

		if (!$user['is_active'] || (bool) ($user['is_system'] ?? false)) {
			return Response::error('BAD_REQUEST', 'User is not active', 400);
		}
		if (trim((string) ($user['email'] ?? '')) === '') {
			return Response::error('NO_EMAIL', 'User has no e-mail address', 400);
		}

		// Re-invite funguje — issue() zneplatní předchozí nepoužitý token.
		try {
			$this->sendTokenMail($request, $db, $user, AuthTokenService::PURPOSE_INVITE, (int) $auth->userId);
		} catch (MailValidationException $e) {
			return Response::error('MAIL_NOT_CONFIGURED', $e->getMessage(), 500);
		}

		return Response::success(['status' => 'ok']);
	}

	public function sessions(Request $request, AuthContext $auth, DataSourceConnection $db): Response
	{
		if (!$auth->isAuthenticated || $auth->tokenType !== 'session' || $auth->token === null) {
			return Response::error('UNAUTHORIZED', 'Valid session token required', 401);
		}

		$rows = $db->fetchAll(
			'SELECT id, token, ip_address, created, expires FROM core_system_sessions
			 WHERE user_id = %i ORDER BY created DESC, id DESC',
			(int) $auth->userId,
		);

		// Token se nikdy nevrací — slouží jen k označení aktuální relace.
		$sessions = array_map(fn (array $row) => [
			'id'         => (int) $row['id'],
			'created'    => date('c', strtotime((string) $row['created'])),
			'expires'    => date('c', strtotime((string) $row['expires'])),
			'ip_address' => $row['ip_address'],
			'current'    => $row['token'] === $auth->token,
		], $rows);

		return Response::success(['sessions' => $sessions]);
	}

	public function sessionDelete(Request $request, AuthContext $auth, DataSourceConnection $db, int $id): Response
	{
		if (!$auth->isAuthenticated || $auth->tokenType !== 'session' || $auth->token === null) {
			return Response::error('UNAUTHORIZED', 'Valid session token required', 401);
		}

		// Jen vlastní relace; cizí id → 404 bez leaku existence.
		$db->execute(
			'DELETE FROM core_system_sessions WHERE id = %i AND user_id = %i',
			$id,
			(int) $auth->userId,
		);
		if ($db->getAffectedRows() !== 1) {
			return Response::error('NOT_FOUND', 'Session not found', 404);
		}

		// 200 s tělem, ne 204 — frontend client.js parsuje odpověď vždy.
		return Response::success(['status' => 'ok']);
	}

	public function sessionsRevokeOthers(Request $request, AuthContext $auth, DataSourceConnection $db): Response
	{
		if (!$auth->isAuthenticated || $auth->tokenType !== 'session' || $auth->token === null) {
			return Response::error('UNAUTHORIZED', 'Valid session token required', 401);
		}

		$revoked = $this->sessions->invalidateOthers((int) $auth->userId, $auth->token, $db);

		return Response::success(['revoked_sessions' => $revoked]);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Účty pro forgot: přesná shoda loginu, jinak všechny účty s daným
	 * e-mailem. Neaktivní, systémové a bez e-mailu se tiše přeskočí.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function findAccountsForReset(string $identifier, DataSourceConnection $db): array
	{
		$eligible = fn (array $user): bool => (bool) $user['is_active']
			&& !(bool) ($user['is_system'] ?? false)
			&& trim((string) ($user['email'] ?? '')) !== '';

		$byLogin = $db->fetchRow(
			'SELECT * FROM core_system_users WHERE login = %s',
			$identifier,
		);
		if ($byLogin !== null) {
			return $eligible($byLogin) ? [$byLogin] : [];
		}

		return array_values(array_filter(
			$db->fetchAll('SELECT * FROM core_system_users WHERE email = %s', $identifier),
			$eligible,
		));
	}

	/**
	 * Vydá token a zařadí mail (reset/pozvánka) do outboxu s okamžitým
	 * pokusem o odeslání. Selhání se v reset větvi jen loguje — odpověď
	 * forgotu nesmí prozradit existenci účtu; invite ho propaguje.
	 */
	private function sendTokenMail(
		Request $request,
		DataSourceConnection $db,
		array $user,
		string $purpose,
		?int $createdBy,
	): void {
		$isInvite = $purpose === AuthTokenService::PURPOSE_INVITE;
		$ttlSeconds = $isInvite ? AuthTokenService::INVITE_TTL_SECONDS : AuthTokenService::RESET_TTL_SECONDS;

		$token = $this->tokenService($db)->issue((int) $user['id'], $purpose, $ttlSeconds);

		$lang = $this->config->getDefaultLanguage();
		$rendered = $this->mailTemplate()->render($isInvite ? 'invite' : 'reset', $lang, [
			'full_name' => (string) $user['full_name'],
			'login'     => (string) $user['login'],
			'ds_name'   => $this->dsName($db),
			'link'      => $this->setPasswordUrl($request, $token),
			'ttl'       => $this->ttlLabel($isInvite, $lang),
		]);

		$message = new OutboundMessage(
			to: (string) $user['email'],
			subject: $rendered['subject'],
			sourceModule: 'core.system',
			bodyText: $rendered['text'],
			bodyHtml: $rendered['html'],
			sourceRef: 'auth:' . $purpose . ':' . (int) $user['id'],
			createdBy: $createdBy,
		);

		try {
			$this->mailService($db)->enqueueAndSend($message);
		} catch (MailValidationException $e) {
			if ($isInvite) {
				throw $e;
			}
			ErrorLogger::error('Password reset mail enqueue failed', [
				'user_id' => (int) $user['id'],
				'error'   => $e->getMessage(),
			]);
		}
	}

	private function setPasswordHash(int $userId, string $password, DataSourceConnection $db): void
	{
		$db->execute(
			'UPDATE core_system_users SET password_hash = %s WHERE id = %i',
			password_hash($password, PASSWORD_DEFAULT),
			$userId,
		);
	}

	/** Absolutní link na set-password obrazovku — schéma z požadavku, prefix `/{ds-id}` při adresování cestou. */
	private function setPasswordUrl(Request $request, string $token): string
	{
		return RequestBaseUrl::of($request, $this->config->getId(), $this->pathAddressed)
			. '/app/?auth_action=set-password&token=' . rawurlencode($token);
	}

	private function dsName(DataSourceConnection $db): string
	{
		$appName = (new SettingsStore($db))->get('app.name');

		return is_string($appName) && $appName !== '' ? $appName : $this->config->getName();
	}

	private function ttlLabel(bool $isInvite, string $lang): string
	{
		if ($lang === 'cs') {
			return $isInvite ? '7 dní' : '1 hodinu';
		}

		return $isInvite ? '7 days' : '1 hour';
	}

	protected function tokenService(DataSourceConnection $db): AuthTokenService
	{
		return new AuthTokenService($db);
	}

	protected function mailService(DataSourceConnection $db): MailOutboxService
	{
		return MailServiceFactory::create($this->config, $db);
	}

	protected function mailTemplate(): MailTemplate
	{
		return new MailTemplate(dirname(__DIR__, 3) . '/modules/core/system/mail');
	}
}
