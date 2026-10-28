<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\PasswordController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Mail\MailOutboxService;
use Shipard\Core\Mail\OutboundMessage;
use Shipard\Tests\Fixtures\Core\Auth\InMemoryAuthDb;

/** Outbox bez DB a SMTP — jen zachytává zprávy. */
class CapturingOutbox extends MailOutboxService
{
	/** @var OutboundMessage[] */
	public array $sent = [];

	public static function create(): self
	{
		$ref = new \ReflectionClass(self::class);
		/** @var self $outbox */
		$outbox = $ref->newInstanceWithoutConstructor();
		return $outbox;
	}

	public function enqueueAndSend(OutboundMessage $message, ?\DateTimeImmutable $now = null): int
	{
		$this->sent[] = $message;
		return count($this->sent);
	}
}

/** Sdílí CapturingOutbox napříč requesty (controller je jinak stateless). */
class TestablePasswordController extends PasswordController
{
	public function __construct(
		DataSourceConfig $config,
		bool $devMode,
		public CapturingOutbox $outbox,
	) {
		parent::__construct($config, $devMode);
	}

	protected function mailService(DataSourceConnection $db): MailOutboxService
	{
		return $this->outbox;
	}
}

class PasswordControllerTest extends TestCase
{
	private const DS_ID = 'abcd-efgh-ijkl-mnop';

	private string $dsDir;
	private InMemoryAuthDb $db;
	private CapturingOutbox $outbox;
	private TestablePasswordController $controller;

	protected function setUp(): void
	{
		$this->dsDir = sys_get_temp_dir() . '/shpd-password-ctrl-test-' . uniqid();
		mkdir($this->dsDir . '/config', 0755, true);
		file_put_contents($this->dsDir . '/config/main.json', json_encode([
			'id'              => self::DS_ID,
			'name'            => 'Test DS',
			'database_name'   => 'test_db',
			'database_user'   => 'test_user',
			'created'         => '2026-01-01 00:00:00',
			'defaultLanguage' => 'cs',
		]));

		$this->db = InMemoryAuthDb::create();
		$this->outbox = CapturingOutbox::create();
		$this->controller = new TestablePasswordController(
			new DataSourceConfig($this->dsDir),
			devMode: true,
			outbox: $this->outbox,
		);
	}

	protected function tearDown(): void
	{
		@unlink($this->dsDir . '/config/main.json');
		@rmdir($this->dsDir . '/config');
		@rmdir($this->dsDir);
	}

	// --- helpers ---

	private function getStatus(Response $response): int
	{
		$ref = new \ReflectionClass($response);
		return $ref->getProperty('status')->getValue($response);
	}

	private function getErrorCode(Response $response): ?string
	{
		return $response->getPayload()['error']['code'] ?? null;
	}

	private function req(string $method, string $path, array $body = []): Request
	{
		return Request::fromArray(
			$method,
			'/api/v1' . $path,
			[],
			$body === [] ? '' : json_encode($body),
			['HTTP_HOST' => '127.0.0.1', 'REMOTE_ADDR' => '10.0.0.1'],
		);
	}

	private function addUser(array $overrides = []): int
	{
		return $this->db->addUser($overrides + [
			'login'         => 'jan',
			'password_hash' => password_hash('OriginalPass123', PASSWORD_DEFAULT),
			'full_name'     => 'Jan Novák',
			'email'         => 'jan@example.com',
			'is_active'     => 1,
			'is_admin'      => 0,
			'is_system'     => 0,
		]);
	}

	private function sessionAuth(int $userId, string $token, bool $isAdmin = false): AuthContext
	{
		return new AuthContext(true, $userId, 'session', $token, $isAdmin);
	}

	/** Vytáhne plaintext token z linku v posledním odeslaném mailu. */
	private function tokenFromLastMail(): string
	{
		$mail = end($this->outbox->sent);
		$this->assertNotFalse($mail);
		$this->assertSame(1, preg_match('/token=([A-Za-z0-9_%-]+)/', (string) $mail->bodyText, $m));
		return rawurldecode($m[1]);
	}

	// --- forgot ---

	public function testForgotUnknownIdentifierReturns200WithoutMail(): void
	{
		$this->addUser();

		$response = $this->controller->forgot(
			$this->req('POST', '/_auth/password/forgot', ['identifier' => 'neexistuje']),
			$this->db,
		);

		$this->assertSame(200, $this->getStatus($response));
		$this->assertCount(0, $this->outbox->sent);
		$this->assertCount(0, $this->db->authTokens);
	}

	public function testForgotByLoginSendsOneMail(): void
	{
		$this->addUser();

		$response = $this->controller->forgot(
			$this->req('POST', '/_auth/password/forgot', ['identifier' => 'jan']),
			$this->db,
		);

		$this->assertSame(200, $this->getStatus($response));
		$this->assertCount(1, $this->outbox->sent);
		$mail = $this->outbox->sent[0];
		$this->assertSame('jan@example.com', $mail->to);
		// Dev mód: link nese http scheme a /{ds-id} prefix.
		$this->assertStringContainsString(
			'http://127.0.0.1/' . self::DS_ID . '/app/?auth_action=set-password&token=',
			(string) $mail->bodyText,
		);
		// V mailu je login (D20 — e-mail shoda na více účtech).
		$this->assertStringContainsString('jan', (string) $mail->bodyText);
		$this->assertCount(1, $this->db->authTokens);
	}

	public function testForgotByEmailSendsMailPerAccountWithDistinctTokens(): void
	{
		$this->addUser();
		$this->addUser(['login' => 'jan2', 'full_name' => 'Jan Druhý']);

		$this->controller->forgot(
			$this->req('POST', '/_auth/password/forgot', ['identifier' => 'jan@example.com']),
			$this->db,
		);

		$this->assertCount(2, $this->outbox->sent);
		$tokens = array_map(
			fn (OutboundMessage $m) => preg_match('/token=([A-Za-z0-9_%-]+)/', (string) $m->bodyText, $x) ? $x[1] : null,
			$this->outbox->sent,
		);
		$this->assertNotNull($tokens[0]);
		$this->assertNotSame($tokens[0], $tokens[1]);
	}

	public function testForgotSkipsSystemInactiveAndNoEmailAccounts(): void
	{
		$this->addUser(['login' => 'system', 'is_system' => 1]);
		$this->addUser(['login' => 'inactive', 'is_active' => 0]);
		$this->addUser(['login' => 'noemail', 'email' => null]);

		foreach (['system', 'inactive', 'noemail'] as $login) {
			$response = $this->controller->forgot(
				$this->req('POST', '/_auth/password/forgot', ['identifier' => $login]),
				$this->db,
			);
			$this->assertSame(200, $this->getStatus($response), $login);
		}

		$this->assertCount(0, $this->outbox->sent);
	}

	// --- reset ---

	public function testResetSetsPasswordAndInvalidatesAllSessions(): void
	{
		$userId = $this->addUser();
		$this->db->addSession(['user_id' => $userId, 'token' => 'shpd_st_a', 'created' => date('Y-m-d H:i:s'), 'expires' => date('Y-m-d H:i:s', time() + 3600)]);
		$this->db->addSession(['user_id' => $userId, 'token' => 'shpd_st_b', 'created' => date('Y-m-d H:i:s'), 'expires' => date('Y-m-d H:i:s', time() + 3600)]);

		$this->controller->forgot($this->req('POST', '/_auth/password/forgot', ['identifier' => 'jan']), $this->db);
		$token = $this->tokenFromLastMail();

		$response = $this->controller->reset(
			$this->req('POST', '/_auth/password/reset', ['token' => $token, 'password' => 'NoveHeslo12345']),
			$this->db,
		);

		$this->assertSame(200, $this->getStatus($response));
		$this->assertTrue(password_verify('NoveHeslo12345', (string) $this->db->users[$userId]['password_hash']));
		$this->assertCount(0, $this->db->sessions);
	}

	public function testResetTokenIsSingleUse(): void
	{
		$this->addUser();
		$this->controller->forgot($this->req('POST', '/_auth/password/forgot', ['identifier' => 'jan']), $this->db);
		$token = $this->tokenFromLastMail();

		$first = $this->controller->reset(
			$this->req('POST', '/_auth/password/reset', ['token' => $token, 'password' => 'NoveHeslo12345']),
			$this->db,
		);
		$second = $this->controller->reset(
			$this->req('POST', '/_auth/password/reset', ['token' => $token, 'password' => 'JineHeslo12345']),
			$this->db,
		);

		$this->assertSame(200, $this->getStatus($first));
		$this->assertSame(400, $this->getStatus($second));
		$this->assertSame('INVALID_TOKEN', $this->getErrorCode($second));
	}

	public function testResetWithInvalidTokenReturns400(): void
	{
		$response = $this->controller->reset(
			$this->req('POST', '/_auth/password/reset', ['token' => 'shpd_pt_bogus', 'password' => 'NoveHeslo12345']),
			$this->db,
		);

		$this->assertSame(400, $this->getStatus($response));
		$this->assertSame('INVALID_TOKEN', $this->getErrorCode($response));
	}

	public function testResetPolicyViolationKeepsTokenValid(): void
	{
		$this->addUser();
		$this->controller->forgot($this->req('POST', '/_auth/password/forgot', ['identifier' => 'jan']), $this->db);
		$token = $this->tokenFromLastMail();

		$short = $this->controller->reset(
			$this->req('POST', '/_auth/password/reset', ['token' => $token, 'password' => 'kratke']),
			$this->db,
		);
		$this->assertSame(400, $this->getStatus($short));
		$this->assertSame('PASSWORD_POLICY', $this->getErrorCode($short));

		// Chyba politiky token nespálila — oprava hesla projde.
		$retry = $this->controller->reset(
			$this->req('POST', '/_auth/password/reset', ['token' => $token, 'password' => 'NoveHeslo12345']),
			$this->db,
		);
		$this->assertSame(200, $this->getStatus($retry));
	}

	public function testResetRejectsPasswordEqualToLogin(): void
	{
		$this->addUser(['login' => 'dlouhylogin12345']);
		$this->controller->forgot($this->req('POST', '/_auth/password/forgot', ['identifier' => 'dlouhylogin12345']), $this->db);
		$token = $this->tokenFromLastMail();

		$response = $this->controller->reset(
			$this->req('POST', '/_auth/password/reset', ['token' => $token, 'password' => 'DlouhyLogin12345']),
			$this->db,
		);

		$this->assertSame(400, $this->getStatus($response));
		$this->assertSame('PASSWORD_POLICY', $this->getErrorCode($response));
	}

	// --- change ---

	public function testChangeWithWrongCurrentPasswordReturns401(): void
	{
		$userId = $this->addUser();
		$this->db->addSession(['user_id' => $userId, 'token' => 'shpd_st_cur', 'created' => date('Y-m-d H:i:s'), 'expires' => date('Y-m-d H:i:s', time() + 3600)]);

		$response = $this->controller->change(
			$this->req('POST', '/_auth/password/change', ['currentPassword' => 'spatne', 'newPassword' => 'NoveHeslo12345']),
			$this->sessionAuth($userId, 'shpd_st_cur'),
			$this->db,
		);

		$this->assertSame(401, $this->getStatus($response));
	}

	public function testChangeWithoutLocalPasswordReturns400(): void
	{
		$userId = $this->addUser(['password_hash' => null]);

		$response = $this->controller->change(
			$this->req('POST', '/_auth/password/change', ['currentPassword' => '', 'newPassword' => 'NoveHeslo12345']),
			$this->sessionAuth($userId, 'shpd_st_cur'),
			$this->db,
		);

		$this->assertSame(400, $this->getStatus($response));
		$this->assertSame('NO_LOCAL_PASSWORD', $this->getErrorCode($response));
	}

	public function testChangeRevokesOtherSessionsButKeepsCurrent(): void
	{
		$userId = $this->addUser();
		$this->db->addSession(['user_id' => $userId, 'token' => 'shpd_st_cur', 'created' => date('Y-m-d H:i:s'), 'expires' => date('Y-m-d H:i:s', time() + 3600)]);
		$this->db->addSession(['user_id' => $userId, 'token' => 'shpd_st_other', 'created' => date('Y-m-d H:i:s'), 'expires' => date('Y-m-d H:i:s', time() + 3600)]);

		$response = $this->controller->change(
			$this->req('POST', '/_auth/password/change', ['currentPassword' => 'OriginalPass123', 'newPassword' => 'NoveHeslo12345']),
			$this->sessionAuth($userId, 'shpd_st_cur'),
			$this->db,
		);

		$this->assertSame(200, $this->getStatus($response));
		$this->assertTrue(password_verify('NoveHeslo12345', (string) $this->db->users[$userId]['password_hash']));
		$tokens = array_column($this->db->sessions, 'token');
		$this->assertSame(['shpd_st_cur'], $tokens);
	}

	public function testChangePolicyViolationReturns400(): void
	{
		$userId = $this->addUser();

		$response = $this->controller->change(
			$this->req('POST', '/_auth/password/change', ['currentPassword' => 'OriginalPass123', 'newPassword' => 'kratke']),
			$this->sessionAuth($userId, 'shpd_st_cur'),
			$this->db,
		);

		$this->assertSame(400, $this->getStatus($response));
		$this->assertSame('PASSWORD_POLICY', $this->getErrorCode($response));
	}

	public function testChangeRequiresSessionToken(): void
	{
		$userId = $this->addUser();

		$response = $this->controller->change(
			$this->req('POST', '/_auth/password/change', ['currentPassword' => 'OriginalPass123', 'newPassword' => 'NoveHeslo12345']),
			new AuthContext(true, $userId, 'api_key', 'shpd_ak_x'),
			$this->db,
		);

		$this->assertSame(401, $this->getStatus($response));
	}

	// --- invite ---

	public function testInviteRequiresAdmin(): void
	{
		$userId = $this->addUser();

		$response = $this->controller->invite(
			$this->req('POST', "/_users/{$userId}/invite"),
			$this->sessionAuth($userId, 'shpd_st_cur', isAdmin: false),
			$this->db,
			$userId,
		);

		$this->assertSame(403, $this->getStatus($response));
		$this->assertCount(0, $this->outbox->sent);
	}

	public function testInviteWithoutEmailReturns400(): void
	{
		$adminId = $this->addUser(['login' => 'admin', 'is_admin' => 1]);
		$targetId = $this->addUser(['login' => 'novy', 'email' => null]);

		$response = $this->controller->invite(
			$this->req('POST', "/_users/{$targetId}/invite"),
			$this->sessionAuth($adminId, 'shpd_st_adm', isAdmin: true),
			$this->db,
			$targetId,
		);

		$this->assertSame(400, $this->getStatus($response));
		$this->assertSame('NO_EMAIL', $this->getErrorCode($response));
	}

	public function testInviteSendsMailAndTokenWorksInReset(): void
	{
		$adminId = $this->addUser(['login' => 'admin', 'is_admin' => 1]);
		$targetId = $this->addUser(['login' => 'novy', 'email' => 'novy@example.com', 'password_hash' => null]);

		$response = $this->controller->invite(
			$this->req('POST', "/_users/{$targetId}/invite"),
			$this->sessionAuth($adminId, 'shpd_st_adm', isAdmin: true),
			$this->db,
			$targetId,
		);

		$this->assertSame(200, $this->getStatus($response));
		$this->assertCount(1, $this->outbox->sent);
		$this->assertSame('novy@example.com', $this->outbox->sent[0]->to);

		// Pozvánka je technicky reset — konzumuje ji stejný endpoint.
		$token = $this->tokenFromLastMail();
		$reset = $this->controller->reset(
			$this->req('POST', '/_auth/password/reset', ['token' => $token, 'password' => 'PrvniHeslo12345']),
			$this->db,
		);
		$this->assertSame(200, $this->getStatus($reset));
		$this->assertTrue(password_verify('PrvniHeslo12345', (string) $this->db->users[$targetId]['password_hash']));
	}

	public function testReinviteInvalidatesPreviousToken(): void
	{
		$adminId = $this->addUser(['login' => 'admin', 'is_admin' => 1]);
		$targetId = $this->addUser(['login' => 'novy', 'email' => 'novy@example.com']);
		$auth = $this->sessionAuth($adminId, 'shpd_st_adm', isAdmin: true);
		$req = $this->req('POST', "/_users/{$targetId}/invite");

		$this->controller->invite($req, $auth, $this->db, $targetId);
		$firstToken = $this->tokenFromLastMail();
		$this->controller->invite($req, $auth, $this->db, $targetId);

		$response = $this->controller->reset(
			$this->req('POST', '/_auth/password/reset', ['token' => $firstToken, 'password' => 'NoveHeslo12345']),
			$this->db,
		);
		$this->assertSame(400, $this->getStatus($response));
		$this->assertSame('INVALID_TOKEN', $this->getErrorCode($response));
	}

	// --- sessions ---

	public function testSessionsListMarksCurrentAndOmitsTokens(): void
	{
		$userId = $this->addUser();
		$otherId = $this->addUser(['login' => 'jiny']);
		$this->db->addSession(['user_id' => $userId, 'token' => 'shpd_st_cur', 'ip_address' => '10.0.0.1', 'created' => '2026-07-01 10:00:00', 'expires' => date('Y-m-d H:i:s', time() + 3600)]);
		$this->db->addSession(['user_id' => $userId, 'token' => 'shpd_st_other', 'ip_address' => '10.0.0.2', 'created' => '2026-07-02 10:00:00', 'expires' => date('Y-m-d H:i:s', time() + 3600)]);
		$this->db->addSession(['user_id' => $otherId, 'token' => 'shpd_st_foreign', 'ip_address' => null, 'created' => '2026-07-03 10:00:00', 'expires' => date('Y-m-d H:i:s', time() + 3600)]);

		$response = $this->controller->sessions(
			$this->req('GET', '/_auth/sessions'),
			$this->sessionAuth($userId, 'shpd_st_cur'),
			$this->db,
		);

		$this->assertSame(200, $this->getStatus($response));
		$sessions = $response->getPayload()['data']['sessions'];

		$this->assertCount(2, $sessions);
		$this->assertStringNotContainsString('shpd_st_', json_encode($sessions));
		$byCurrent = array_column($sessions, 'current');
		$this->assertContains(true, $byCurrent);
		$this->assertContains(false, $byCurrent);
	}

	public function testSessionDeleteForeignIdReturns404(): void
	{
		$userId = $this->addUser();
		$otherId = $this->addUser(['login' => 'jiny']);
		$foreignSessionId = $this->db->addSession(['user_id' => $otherId, 'token' => 'shpd_st_foreign', 'created' => date('Y-m-d H:i:s'), 'expires' => date('Y-m-d H:i:s', time() + 3600)]);
		$this->db->addSession(['user_id' => $userId, 'token' => 'shpd_st_cur', 'created' => date('Y-m-d H:i:s'), 'expires' => date('Y-m-d H:i:s', time() + 3600)]);

		$response = $this->controller->sessionDelete(
			$this->req('DELETE', "/_auth/sessions/{$foreignSessionId}"),
			$this->sessionAuth($userId, 'shpd_st_cur'),
			$this->db,
			$foreignSessionId,
		);

		$this->assertSame(404, $this->getStatus($response));
		$this->assertCount(2, $this->db->sessions);
	}

	public function testSessionDeleteOwnSessionSucceeds(): void
	{
		$userId = $this->addUser();
		$this->db->addSession(['user_id' => $userId, 'token' => 'shpd_st_cur', 'created' => date('Y-m-d H:i:s'), 'expires' => date('Y-m-d H:i:s', time() + 3600)]);
		$ownId = $this->db->addSession(['user_id' => $userId, 'token' => 'shpd_st_other', 'created' => date('Y-m-d H:i:s'), 'expires' => date('Y-m-d H:i:s', time() + 3600)]);

		$response = $this->controller->sessionDelete(
			$this->req('DELETE', "/_auth/sessions/{$ownId}"),
			$this->sessionAuth($userId, 'shpd_st_cur'),
			$this->db,
			$ownId,
		);

		$this->assertSame(200, $this->getStatus($response));
		$this->assertCount(1, $this->db->sessions);
	}

	public function testRevokeOthersKeepsCurrentSession(): void
	{
		$userId = $this->addUser();
		$this->db->addSession(['user_id' => $userId, 'token' => 'shpd_st_cur', 'created' => date('Y-m-d H:i:s'), 'expires' => date('Y-m-d H:i:s', time() + 3600)]);
		$this->db->addSession(['user_id' => $userId, 'token' => 'shpd_st_b', 'created' => date('Y-m-d H:i:s'), 'expires' => date('Y-m-d H:i:s', time() + 3600)]);
		$this->db->addSession(['user_id' => $userId, 'token' => 'shpd_st_c', 'created' => date('Y-m-d H:i:s'), 'expires' => date('Y-m-d H:i:s', time() + 3600)]);

		$response = $this->controller->sessionsRevokeOthers(
			$this->req('POST', '/_auth/sessions/revoke-others'),
			$this->sessionAuth($userId, 'shpd_st_cur'),
			$this->db,
		);

		$this->assertSame(200, $this->getStatus($response));
		$this->assertSame(2, $response->getPayload()['data']['revoked_sessions']);
		$this->assertSame(['shpd_st_cur'], array_column($this->db->sessions, 'token'));
	}
}
