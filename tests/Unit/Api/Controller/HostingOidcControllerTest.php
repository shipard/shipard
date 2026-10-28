<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\HostingOidcController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Auth\OidcClient;
use Shipard\Core\Auth\OidcDiscovery;
use Shipard\Core\Auth\OidcProviderConfig;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Hosting\OpKeyStore;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Tests\Fixtures\Module\Hosting\InMemoryHostingOidcDb;

/**
 * Discovery/JWKS lokálně, žádné HTTP — pro RP-equivalence round-trip
 * (OP payload → reálný OidcClient::validateIdToken).
 */
class StubOpDiscovery extends OidcDiscovery
{
    public function __construct(
        private readonly array $discoveryDoc,
        private readonly array $jwksDoc,
    ) {
        parent::__construct(sys_get_temp_dir() . '/shpd-stub-op-discovery');
    }

    public function getDiscovery(string $issuer): array
    {
        return $this->discoveryDoc;
    }

    public function getJwks(string $issuer): array
    {
        return $this->jwksDoc;
    }

    public function refreshJwks(string $issuer): ?array
    {
        return null;
    }
}

class HostingOidcControllerTest extends TestCase
{
    private const DS_ID = 'abcd-efgh-ijkl-mnop';
    private const CLIENT_DS_ID = 'wxyz-wxyz-wxyz-wxyz';
    private const ISSUER = 'http://127.0.0.1/abcd-efgh-ijkl-mnop/api/v1/_hosting/oidc';
    private const REDIRECT_URI = 'http://127.0.0.1/wxyz-wxyz-wxyz-wxyz/api/v1/_auth/oidc/callback';
    private const CLIENT_SECRET = 'topsecret-client-value';

    private static string $dsDir;

    private InMemoryHostingOidcDb $db;
    private DsSecretCipher $cipher;
    private HostingOidcController $controller;
    private int $clientId;
    private int $userId;

    public static function setUpBeforeClass(): void
    {
        // Keygen RSA 3072 je pomalý — jeden klíč pro celou třídu.
        self::$dsDir = sys_get_temp_dir() . '/shpd-hosting-oidc-test-' . uniqid();
        mkdir(self::$dsDir . '/config', 0755, true);
        file_put_contents(self::$dsDir . '/config/main.json', json_encode([
            'id'            => self::DS_ID,
            'name'          => 'Hosting DS',
            'database_name' => 'test_db',
            'database_user' => 'test_user',
            'created'       => '2026-01-01 00:00:00',
        ]));
        OpKeyStore::generateKey(self::$dsDir);
    }

    public static function tearDownAfterClass(): void
    {
        OpKeyStore::resetCache();
        @unlink(OpKeyStore::keyFilePath(self::$dsDir));
        @rmdir(self::$dsDir . '/secrets');
        @unlink(self::$dsDir . '/config/main.json');
        @rmdir(self::$dsDir . '/config');
        @rmdir(self::$dsDir);
    }

    protected function setUp(): void
    {
        $this->db = InMemoryHostingOidcDb::create();
        $this->db->setSetting('hosting.oidc.issuer', self::ISSUER);
        $this->cipher = DsSecretCipher::fromKey(str_repeat('k', 32));

        $this->clientId = $this->db->addDataSource([
            'ds_id'              => self::CLIENT_DS_ID,
            'name'               => 'Client DS',
            'lifecycle'          => 'active',
            'docState'           => 40,
            'oidc_client_secret' => $this->cipher->encrypt(self::CLIENT_SECRET),
            'oidc_redirect_uri'  => self::REDIRECT_URI,
        ]);
        $this->userId = $this->db->addUser([
            'login'     => 'user@example.com',
            'email'     => 'user@example.com',
            'full_name' => 'Test User',
            'is_active' => 1,
        ]);

        $config = new DataSourceConfig(self::$dsDir);
        $this->controller = new HostingOidcController(
            $config,
            devMode: true,
            keyStore: OpKeyStore::forConfig($config),
            cipher: $this->cipher,
        );
    }

    // -------------------------------------------------------------------------
    // Gating
    // -------------------------------------------------------------------------

    public function testMissingModuleTablesReturn404(): void
    {
        $resp = $this->controller->discovery($this->req('GET', '/_hosting/oidc/.well-known/openid-configuration'), $this->db, []);
        $this->assertSame(404, $this->getStatus($resp));

        $resp = $this->controller->token($this->req('POST', '/_hosting/oidc/token'), [], $this->db, []);
        $this->assertSame(404, $this->getStatus($resp));
    }

    public function testMissingIssuerSettingReturns404(): void
    {
        $this->db->settings = [];
        $resp = $this->controller->discovery($this->req('GET', '/_hosting/oidc/.well-known/openid-configuration'), $this->db, $this->tables());
        $this->assertSame(404, $this->getStatus($resp));
    }

    // -------------------------------------------------------------------------
    // Discovery + JWKS
    // -------------------------------------------------------------------------

    public function testDiscoveryPayload(): void
    {
        $resp = $this->controller->discovery($this->req('GET', '/_hosting/oidc/.well-known/openid-configuration'), $this->db, $this->tables());
        $this->assertSame(200, $this->getStatus($resp));

        $doc = $resp->getPayload();
        // Raw OAuth tvar — žádný success envelope.
        $this->assertArrayNotHasKey('success', $doc);
        $this->assertSame(self::ISSUER, $doc['issuer']);
        $this->assertSame(self::ISSUER . '/authorize', $doc['authorization_endpoint']);
        $this->assertSame(self::ISSUER . '/token', $doc['token_endpoint']);
        $this->assertSame(self::ISSUER . '/jwks', $doc['jwks_uri']);
        $this->assertSame(['code'], $doc['response_types_supported']);
        $this->assertSame(['RS256'], $doc['id_token_signing_alg_values_supported']);
        $this->assertSame(['client_secret_post'], $doc['token_endpoint_auth_methods_supported']);
        $this->assertSame(['S256'], $doc['code_challenge_methods_supported']);
    }

    public function testJwksPayload(): void
    {
        $resp = $this->controller->jwks($this->db, $this->tables());
        $this->assertSame(200, $this->getStatus($resp));

        $jwks = $resp->getPayload();
        $this->assertArrayNotHasKey('success', $jwks);
        $this->assertCount(1, $jwks['keys']);
        $jwk = $jwks['keys'][0];
        $this->assertSame('RSA', $jwk['kty']);
        $this->assertSame('RS256', $jwk['alg']);
        $this->assertNotEmpty($jwk['kid']);
        $this->assertNotEmpty($jwk['n']);
        $this->assertNotEmpty($jwk['e']);
    }

    // -------------------------------------------------------------------------
    // authorize
    // -------------------------------------------------------------------------

    public function testAuthorizeUnknownClientIs400WithoutRedirect(): void
    {
        $resp = $this->controller->authorize(
            $this->authorizeRequest(['client_id' => 'nope-nope-nope-nope']),
            $this->db,
            $this->tables(),
        );

        $this->assertSame(400, $this->getStatus($resp));
        $this->assertArrayNotHasKey('Location', $resp->getHeaders());
        $this->assertSame('html', $this->getBodyType($resp));
    }

    public function testAuthorizeRedirectMismatchIs400WithoutRedirect(): void
    {
        $resp = $this->controller->authorize(
            $this->authorizeRequest(['redirect_uri' => 'http://evil.example.com/callback']),
            $this->db,
            $this->tables(),
        );

        $this->assertSame(400, $this->getStatus($resp));
        $this->assertArrayNotHasKey('Location', $resp->getHeaders());
    }

    public function testAuthorizeInactiveClientIs400(): void
    {
        $this->db->dataSources[$this->clientId]['lifecycle'] = 'suspended';
        $resp = $this->controller->authorize($this->authorizeRequest(), $this->db, $this->tables());
        $this->assertSame(400, $this->getStatus($resp));
    }

    public function testAuthorizeArchivedClientIs400(): void
    {
        $this->db->dataSources[$this->clientId]['docState'] = 90;
        $resp = $this->controller->authorize($this->authorizeRequest(), $this->db, $this->tables());
        $this->assertSame(400, $this->getStatus($resp));
    }

    public function testAuthorizeClientWithoutSecretIs400(): void
    {
        $this->db->dataSources[$this->clientId]['oidc_client_secret'] = null;
        $resp = $this->controller->authorize($this->authorizeRequest(), $this->db, $this->tables());
        $this->assertSame(400, $this->getStatus($resp));
    }

    public function testAuthorizeMissingNonceRedirectsWithError(): void
    {
        $resp = $this->controller->authorize(
            $this->authorizeRequest(['nonce' => '']),
            $this->db,
            $this->tables(),
        );

        $this->assertSame(302, $this->getStatus($resp));
        $location = $resp->getHeaders()['Location'];
        $this->assertStringStartsWith(self::REDIRECT_URI . '?error=invalid_request', $location);
        $this->assertStringContainsString('state=state-123', $location);
    }

    public function testAuthorizeWrongChallengeMethodRedirectsWithError(): void
    {
        $resp = $this->controller->authorize(
            $this->authorizeRequest(['code_challenge_method' => 'plain']),
            $this->db,
            $this->tables(),
        );

        $this->assertSame(302, $this->getStatus($resp));
        $this->assertStringContainsString('error=invalid_request', $resp->getHeaders()['Location']);
    }

    public function testAuthorizeOkCreatesTransactionAndRedirectsToApp(): void
    {
        $resp = $this->controller->authorize($this->authorizeRequest(), $this->db, $this->tables());

        $this->assertSame(302, $this->getStatus($resp));
        $location = $resp->getHeaders()['Location'];
        // Dev mód: http + DS prefix.
        $this->assertStringStartsWith('http://127.0.0.1/' . self::DS_ID . '/app/?op_auth=', $location);

        $this->assertCount(1, $this->db->oidcCodes);
        $txn = array_values($this->db->oidcCodes)[0];
        $this->assertSame(43, strlen((string) $txn['txn']));
        $this->assertStringContainsString('op_auth=' . $txn['txn'], $location);
        $this->assertSame($this->clientId, $txn['client']);
        $this->assertSame('state-123', $txn['state']);
        $this->assertNull($txn['user']);
        $this->assertNull($txn['code']);
        $this->assertGreaterThan(date('Y-m-d H:i:s', time() + 500), $txn['expires']);
    }

    public function testAuthorizeCleansExpiredTransactions(): void
    {
        $this->db->addOidcCode([
            'txn'            => 'expired-txn',
            'client'         => $this->clientId,
            'state'          => 's',
            'nonce'          => 'n',
            'code_challenge' => 'c',
            'redirect_uri'   => self::REDIRECT_URI,
            'created'        => date('Y-m-d H:i:s', time() - 1200),
            'expires'        => date('Y-m-d H:i:s', time() - 600),
        ]);

        $this->controller->authorize($this->authorizeRequest(), $this->db, $this->tables());

        foreach ($this->db->oidcCodes as $row) {
            $this->assertNotSame('expired-txn', $row['txn']);
        }
    }

    // -------------------------------------------------------------------------
    // approve
    // -------------------------------------------------------------------------

    public function testApproveWithoutSessionIs401(): void
    {
        $txn = $this->createTransaction();
        $resp = $this->controller->approve(
            $this->req('POST', '/_hosting/oidc/approve', [], ['txn' => $txn]),
            AuthContext::anonymous(),
            $this->db,
            $this->tables(),
        );
        $this->assertSame(401, $this->getStatus($resp));
    }

    public function testApproveUnknownTxnIs400(): void
    {
        $resp = $this->approve('unknown-txn');
        $this->assertSame(400, $this->getStatus($resp));
        $this->assertSame('OIDC_TXN_INVALID', $resp->getPayload()['error']['code']);
    }

    public function testApproveExpiredTxnIs400(): void
    {
        $txn = $this->createTransaction(expiresIn: -10);
        $resp = $this->approve($txn);
        $this->assertSame(400, $this->getStatus($resp));
    }

    public function testApproveUsedTxnIs400(): void
    {
        $txn = $this->createTransaction();
        $first = $this->approve($txn);
        $this->assertSame(200, $this->getStatus($first));

        $second = $this->approve($txn);
        $this->assertSame(400, $this->getStatus($second));
        $this->assertSame('OIDC_TXN_INVALID', $second->getPayload()['error']['code']);
    }

    public function testApproveOkReturnsRedirectWithCodeAndState(): void
    {
        $txn = $this->createTransaction();
        $resp = $this->approve($txn);

        $this->assertSame(200, $this->getStatus($resp));
        $redirect = $resp->getPayload()['data']['redirect'];
        $this->assertStringStartsWith(self::REDIRECT_URI . '?code=', $redirect);
        $this->assertStringContainsString('&state=state-123', $redirect);

        $row = array_values($this->db->oidcCodes)[0];
        $this->assertSame($this->userId, $row['user']);
        $this->assertSame(43, strlen((string) $row['code']));
        $this->assertStringContainsString('code=' . $row['code'], $redirect);
        // TTL zkrácené na kódové okno (60 s).
        $this->assertLessThan(date('Y-m-d H:i:s', time() + 120), $row['expires']);
    }

    // -------------------------------------------------------------------------
    // token
    // -------------------------------------------------------------------------

    public function testTokenHappyPathIssuesIdToken(): void
    {
        $resp = $this->controller->token(
            $this->req('POST', '/_hosting/oidc/token'),
            $this->tokenForm(),
            $this->db,
            $this->tables(),
        );

        $this->assertSame(200, $this->getStatus($resp));
        $payload = $resp->getPayload();
        $this->assertArrayNotHasKey('success', $payload);
        $this->assertSame('Bearer', $payload['token_type']);
        $this->assertSame(300, $payload['expires_in']);
        $this->assertNotEmpty($payload['access_token']);

        $header = json_decode(
            (string) base64_decode(strtr(explode('.', $payload['id_token'])[0], '-_', '+/')),
            true,
        );
        $this->assertSame('RS256', $header['alg']);
        $this->assertSame($this->keyStore()->kid(), $header['kid']);

        $claims = (array) JWT::decode(
            $payload['id_token'],
            JWK::parseKeySet(['keys' => [$this->keyStore()->publicJwk()]], 'RS256'),
        );
        $this->assertSame(self::ISSUER, $claims['iss']);
        $this->assertSame((string) $this->userId, $claims['sub']);
        $this->assertSame(self::CLIENT_DS_ID, $claims['aud']);
        $this->assertSame('nonce-456', $claims['nonce']);
        $this->assertSame('user@example.com', $claims['email']);
        $this->assertTrue($claims['email_verified']);
        $this->assertSame('Test User', $claims['name']);
        $this->assertSame(300, $claims['exp'] - $claims['iat']);

        // Kód je spotřebovaný.
        $this->assertCount(0, $this->db->oidcCodes);
    }

    public function testTokenReplayIsInvalidGrant(): void
    {
        $form = $this->tokenForm();
        $first = $this->controller->token($this->req('POST', '/x'), $form, $this->db, $this->tables());
        $this->assertSame(200, $this->getStatus($first));

        $second = $this->controller->token($this->req('POST', '/x'), $form, $this->db, $this->tables());
        $this->assertSame(400, $this->getStatus($second));
        $this->assertSame(['error' => 'invalid_grant'], $second->getPayload());
    }

    public function testTokenWrongVerifierIsInvalidGrantAndConsumesCode(): void
    {
        $form = $this->tokenForm();
        $form['code_verifier'] = 'wrong-verifier-wrong-verifier-wrong-verifier';

        $resp = $this->controller->token($this->req('POST', '/x'), $form, $this->db, $this->tables());

        $this->assertSame(400, $this->getStatus($resp));
        $this->assertSame(['error' => 'invalid_grant'], $resp->getPayload());
        // Single-use i při selhání — kód je pryč.
        $this->assertCount(0, $this->db->oidcCodes);
    }

    public function testTokenWrongSecretIsInvalidGrant(): void
    {
        $form = $this->tokenForm();
        $form['client_secret'] = 'wrong-secret';
        $resp = $this->controller->token($this->req('POST', '/x'), $form, $this->db, $this->tables());
        $this->assertSame(400, $this->getStatus($resp));
        $this->assertSame(['error' => 'invalid_grant'], $resp->getPayload());
    }

    public function testTokenWrongClientIdIsInvalidGrant(): void
    {
        $form = $this->tokenForm();
        $form['client_id'] = 'jine-dsds-dsds-dsds';
        $resp = $this->controller->token($this->req('POST', '/x'), $form, $this->db, $this->tables());
        $this->assertSame(400, $this->getStatus($resp));
    }

    public function testTokenWrongRedirectUriIsInvalidGrant(): void
    {
        $form = $this->tokenForm();
        $form['redirect_uri'] = 'http://evil.example.com/callback';
        $resp = $this->controller->token($this->req('POST', '/x'), $form, $this->db, $this->tables());
        $this->assertSame(400, $this->getStatus($resp));
    }

    public function testTokenExpiredCodeIsInvalidGrant(): void
    {
        $form = $this->tokenForm();
        foreach ($this->db->oidcCodes as $id => $row) {
            $this->db->oidcCodes[$id]['expires'] = date('Y-m-d H:i:s', time() - 10);
        }
        $resp = $this->controller->token($this->req('POST', '/x'), $form, $this->db, $this->tables());
        $this->assertSame(400, $this->getStatus($resp));
        $this->assertCount(0, $this->db->oidcCodes);
    }

    // -------------------------------------------------------------------------
    // RP-equivalence round-trip — id_token projde reálnou RP validací
    // -------------------------------------------------------------------------

    public function testIdTokenPassesRpValidation(): void
    {
        $tokenResp = $this->controller->token(
            $this->req('POST', '/x'),
            $this->tokenForm(),
            $this->db,
            $this->tables(),
        );
        $idToken = $tokenResp->getPayload()['id_token'];

        $discovery = $this->controller->discovery(
            $this->req('GET', '/_hosting/oidc/.well-known/openid-configuration'),
            $this->db,
            $this->tables(),
        )->getPayload();
        $jwks = $this->controller->jwks($this->db, $this->tables())->getPayload();

        $provider = OidcProviderConfig::fromArray([
            'id'           => 'hosting',
            'issuer'       => self::ISSUER,
            'clientId'     => self::CLIENT_DS_ID,
            'clientSecret' => self::CLIENT_SECRET,
        ]);
        $client = new OidcClient(new StubOpDiscovery($discovery, $jwks));

        $identity = $client->validateIdToken($provider, $idToken, 'nonce-456');

        $this->assertSame(self::ISSUER, $identity->issuer);
        $this->assertSame((string) $this->userId, $identity->subject);
        $this->assertSame('user@example.com', $identity->email);
        $this->assertTrue($identity->emailVerified);
        $this->assertSame('Test User', $identity->name);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function keyStore(): OpKeyStore
    {
        return OpKeyStore::forConfig(new DataSourceConfig(self::$dsDir));
    }

    /** @return array<string, TableDefinition> */
    private function tables(): array
    {
        return [
            'hosting_core_data_sources' => $this->makeTable('hosting_core_data_sources'),
            'hosting_core_oidc_codes'   => $this->makeTable('hosting_core_oidc_codes'),
        ];
    }

    private function makeTable(string $name): TableDefinition
    {
        return TableDefinition::fromArray([
            'tableId'   => 1,
            'name'      => $name,
            'adminOnly' => true,
            'columns'   => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
                ['id' => 'txn', 'name' => 'Txn', 'type' => 'varchar', 'length' => 64],
            ],
        ]);
    }

    private function req(string $method, string $path, array $query = [], array $body = []): Request
    {
        return Request::fromArray(
            $method,
            '/api/v1' . $path,
            $query,
            $body === [] ? '' : (string) json_encode($body),
            ['HTTP_HOST' => '127.0.0.1'],
        );
    }

    private function authorizeRequest(array $overrides = []): Request
    {
        $query = array_merge([
            'response_type'         => 'code',
            'client_id'             => self::CLIENT_DS_ID,
            'redirect_uri'          => self::REDIRECT_URI,
            'scope'                 => 'openid profile email',
            'state'                 => 'state-123',
            'nonce'                 => 'nonce-456',
            'code_challenge'        => $this->codeChallenge('verifier-789-verifier-789-verifier-789-verif'),
            'code_challenge_method' => 'S256',
        ], $overrides);

        return $this->req('GET', '/_hosting/oidc/authorize', $query);
    }

    /** Založí transakci přes authorize a vrátí txn token. */
    private function createTransaction(int $expiresIn = 600): string
    {
        $this->controller->authorize($this->authorizeRequest(), $this->db, $this->tables());
        $row = array_values($this->db->oidcCodes)[count($this->db->oidcCodes) - 1];
        if ($expiresIn !== 600) {
            $this->db->oidcCodes[$row['id']]['expires'] = date('Y-m-d H:i:s', time() + $expiresIn);
        }
        return (string) $row['txn'];
    }

    private function approve(string $txn): Response
    {
        return $this->controller->approve(
            $this->req('POST', '/_hosting/oidc/approve', [], ['txn' => $txn]),
            new AuthContext(true, $this->userId, 'session', 'tok', isAdmin: false),
            $this->db,
            $this->tables(),
        );
    }

    /** Celý flow authorize → approve, vrátí form pro token endpoint. */
    private function tokenForm(): array
    {
        $txn = $this->createTransaction();
        $approveResp = $this->approve($txn);
        $redirect = $approveResp->getPayload()['data']['redirect'];
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $params);

        return [
            'grant_type'    => 'authorization_code',
            'code'          => $params['code'],
            'redirect_uri'  => self::REDIRECT_URI,
            'client_id'     => self::CLIENT_DS_ID,
            'client_secret' => self::CLIENT_SECRET,
            'code_verifier' => 'verifier-789-verifier-789-verifier-789-verif',
        ];
    }

    private function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function getStatus(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        return $ref->getProperty('status')->getValue($response);
    }

    private function getBodyType(Response $response): string
    {
        $ref = new \ReflectionClass($response);
        return $ref->getProperty('bodyType')->getValue($response);
    }
}
