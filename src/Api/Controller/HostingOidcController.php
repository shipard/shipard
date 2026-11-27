<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\RequestBaseUrl;
use Shipard\Api\Response;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Hosting\Exception\OpKeyException;
use Shipard\Core\Hosting\OpKeyStore;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Settings\SettingsStore;

/**
 * OIDC Provider hostingu (D2, D8, D10, D12) — minimální OP: authorization
 * code + PKCE S256, client_secret_post, RS256. Klienti = řádky
 * hosting_core_data_sources (client_id = ds_id), uživatelé =
 * core_system_users hostingu. Vydává přesně to, co RP validuje
 * (OidcClient::validateIdToken), nic víc.
 *
 * Endpoints:
 *   GET  /_hosting/oidc/.well-known/openid-configuration  discovery
 *   GET  /_hosting/oidc/jwks                              veřejný klíč
 *   GET  /_hosting/oidc/authorize                         založí transakci,
 *        302 na /app/?op_auth={txn} — session bridge (D10)
 *   POST /_hosting/oidc/approve                           Bearer session; naváže
 *        uživatele, vydá kód, vrátí RP redirect URL (NENÍ exempt)
 *   POST /_hosting/oidc/token                             form-encoded; kód →
 *        id_token (single-use, PKCE, secret, redirect_uri)
 *
 * Vědomá volba: OP je čistě identitní autorita — kód vydá kterémukoli
 * přihlášenému uživateli hostingu bez kontroly hosting_core_ds_users.
 * O vpuštění do DS rozhoduje RP (IdentityMapper).
 *
 * Gating: modul neaktivní (chybí tabulky) nebo nevyplněný issuer setting
 * → 404. Issuer se bere doslovně ze settingu hosting.oidc.issuer (D12),
 * nikdy z requestu — RP porovnává iss claim byte-exact.
 */
class HostingOidcController
{
    /** authorize → approve okno (SPA včetně případného loginu). */
    private const int TXN_TTL_SECONDS = 600;
    /** approve → token okno (autorizační kód). */
    private const int CODE_TTL_SECONDS = 60;
    /** Platnost id_tokenu (exp − iat) a deklarovaná platnost access_tokenu. */
    private const int TOKEN_TTL_SECONDS = 300;

    /** Ne-archivní stavy modelu core.system.docStatesArchive. */
    private const ACTIVE_DOC_STATES = [10, 40, 80];

    public function __construct(
        private readonly DataSourceConfig $config,
        private readonly bool $pathAddressed,
        private readonly ?OpKeyStore $keyStore = null,
        private readonly ?DsSecretCipher $cipher = null,
    ) {}

    /**
     * GET /_hosting/oidc/.well-known/openid-configuration
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function discovery(Request $request, DataSourceConnection $db, array $tables): Response
    {
        $issuer = $this->gate($db, $tables);
        if ($issuer instanceof Response) {
            return $issuer;
        }

        // D12: issuer doslovně ze settingu — nesoulad s hostem requestu je
        // jen diagnostický signál, nikdy důvod issuer odvozovat.
        $issuerHost = parse_url($issuer, PHP_URL_HOST);
        if ($issuerHost !== null && $issuerHost !== $request->getHost()) {
            ErrorLogger::warn('OIDC OP issuer/host mismatch', [
                'issuer' => $issuer,
                'requestHost' => $request->getHost(),
            ]);
        }

        return Response::raw([
            'issuer'                                => $issuer,
            'authorization_endpoint'                => $issuer . '/authorize',
            'token_endpoint'                        => $issuer . '/token',
            'jwks_uri'                              => $issuer . '/jwks',
            'response_types_supported'              => ['code'],
            'subject_types_supported'               => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post'],
            'code_challenge_methods_supported'      => ['S256'],
            'scopes_supported'                      => ['openid', 'profile', 'email'],
        ]);
    }

    /**
     * GET /_hosting/oidc/jwks
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function jwks(DataSourceConnection $db, array $tables): Response
    {
        $issuer = $this->gate($db, $tables);
        if ($issuer instanceof Response) {
            return $issuer;
        }

        try {
            $jwk = $this->keyStore()->publicJwk();
        } catch (OpKeyException $e) {
            ErrorLogger::error('OIDC OP key unavailable', ['error' => $e->getMessage()]);
            return Response::error('INTERNAL_ERROR', 'OIDC OP key unavailable', 500);
        }

        return Response::raw(['keys' => [$jwk]]);
    }

    /**
     * GET /_hosting/oidc/authorize (exempt)
     *
     * Pořadí validace je závazné: dokud není ověřený klient + redirect_uri
     * (exact match), NIKDY neredirectujeme — chyba je 400 HTML stránka.
     * Teprve pak smí chyby tvaru requestu jít 302 na redirect_uri.
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function authorize(Request $request, DataSourceConnection $db, array $tables): Response
    {
        $issuer = $this->gate($db, $tables);
        if ($issuer instanceof Response) {
            return $issuer;
        }

        $query = $request->getQueryParams();
        $clientId = (string) ($query['client_id'] ?? '');
        $redirectUri = (string) ($query['redirect_uri'] ?? '');

        // 1. Klient + redirect_uri — selhání = 400 stránka, žádný redirect
        //    na neověřenou adresu. Statický text, žádný reflektovaný vstup.
        $client = $clientId === '' ? null : $db->fetchRow(
            'SELECT * FROM hosting_core_data_sources WHERE ds_id = %s',
            $clientId,
        );
        if ($client === null
            || (string) $client['lifecycle'] !== 'active'
            || !in_array((int) $client['docState'], self::ACTIVE_DOC_STATES, true)
            || ($client['oidc_client_secret'] ?? null) === null
            || ($client['oidc_redirect_uri'] ?? null) === null
            || $redirectUri === ''
            || $redirectUri !== (string) $client['oidc_redirect_uri']
        ) {
            return Response::html(
                '<!doctype html><html lang="en"><head><meta charset="utf-8">'
                . '<title>OIDC error</title></head><body>'
                . '<h1>OIDC error</h1><p>Unknown client or invalid redirect URI.</p>'
                . '</body></html>',
                400,
            );
        }

        // 2. Tvar požadavku — redirect_uri je ověřená, chyby smí 302 zpět.
        $state = (string) ($query['state'] ?? '');
        $nonce = (string) ($query['nonce'] ?? '');
        $codeChallenge = (string) ($query['code_challenge'] ?? '');
        if ((string) ($query['response_type'] ?? '') !== 'code'
            || $state === ''
            || $nonce === ''
            || $codeChallenge === ''
            || (string) ($query['code_challenge_method'] ?? '') !== 'S256'
        ) {
            $location = $redirectUri . '?error=invalid_request'
                . ($state !== '' ? '&state=' . rawurlencode($state) : '');
            return Response::redirect($location);
        }

        // 3. OK — oportunistický úklid (expirované transakce nikdo jiný
        //    nemaže), INSERT transakce, redirect do SPA (session bridge D10).
        $db->execute(
            'DELETE FROM hosting_core_oidc_codes WHERE expires < %s',
            date('Y-m-d H:i:s'),
        );

        $txn = $this->urlSafeToken(32);
        $db->insertRow('hosting_core_oidc_codes', [
            'txn'            => $txn,
            'client'         => (int) $client['id'],
            'state'          => $state,
            'nonce'          => $nonce,
            'code_challenge' => $codeChallenge,
            'redirect_uri'   => $redirectUri,
            'created'        => date('Y-m-d H:i:s'),
            'expires'        => date('Y-m-d H:i:s', time() + self::TXN_TTL_SECONDS),
        ]);

        return Response::redirect($this->appUrl($request) . '?op_auth=' . rawurlencode($txn));
    }

    /**
     * POST /_hosting/oidc/approve (Bearer session — NENÍ exempt)
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function approve(Request $request, AuthContext $auth, DataSourceConnection $db, array $tables): Response
    {
        $issuer = $this->gate($db, $tables);
        if ($issuer instanceof Response) {
            return $issuer;
        }

        // AuthMiddleware nepřihlášené nepustí (endpoint není exempt) —
        // přesto ověřit, ať kontroler nestojí jen na wiringu middlewaru.
        if (!$auth->isAuthenticated || $auth->userId === null) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $txnToken = (string) ($request->getBody()['txn'] ?? '');
        $txn = $txnToken === '' ? null : $db->fetchRow(
            'SELECT * FROM hosting_core_oidc_codes WHERE txn = %s',
            $txnToken,
        );
        // user už vyplněný = transakce je použitá (single-use).
        if ($txn === null
            || strtotime((string) $txn['expires']) < time()
            || $txn['user'] !== null
        ) {
            return Response::error('OIDC_TXN_INVALID', 'Invalid or expired transaction', 400);
        }

        // `user IS NULL` v podmínce zavírá souběžné approve téže transakce.
        $code = $this->urlSafeToken(32);
        $db->execute(
            'UPDATE hosting_core_oidc_codes SET user = %i, code = %s, expires = %s WHERE id = %i AND user IS NULL',
            $auth->userId,
            $code,
            date('Y-m-d H:i:s', time() + self::CODE_TTL_SECONDS),
            (int) $txn['id'],
        );
        $updated = $db->fetchRow(
            'SELECT * FROM hosting_core_oidc_codes WHERE id = %i',
            (int) $txn['id'],
        );
        if ($updated === null || (string) $updated['code'] !== $code) {
            return Response::error('OIDC_TXN_INVALID', 'Invalid or expired transaction', 400);
        }

        return Response::success([
            'redirect' => (string) $txn['redirect_uri']
                . '?code=' . rawurlencode($code)
                . '&state=' . rawurlencode((string) $txn['state']),
        ]);
    }

    /**
     * POST /_hosting/oidc/token (exempt, form-encoded)
     *
     * $form přichází z dispatcheru ($_POST) — Request::getBody() umí jen
     * JSON. Všechna selhání vrací OAuth tvar {"error":"invalid_grant"}
     * (RP čte raw tělo, ne náš error envelope) bez rozlišení důvodu.
     *
     * @param array<string, mixed> $form
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function token(Request $request, array $form, DataSourceConnection $db, array $tables): Response
    {
        $issuer = $this->gate($db, $tables);
        if ($issuer instanceof Response) {
            return $issuer;
        }

        $code = (string) ($form['code'] ?? '');
        if ((string) ($form['grant_type'] ?? '') !== 'authorization_code' || $code === '') {
            return $this->invalidGrant();
        }

        $txn = $db->fetchRow(
            'SELECT * FROM hosting_core_oidc_codes WHERE code = %s',
            $code,
        );
        if ($txn === null) {
            return $this->invalidGrant();
        }

        // Single-use: smazat hned, i při následném selhání — replay nesmí projít.
        $db->execute(
            'DELETE FROM hosting_core_oidc_codes WHERE id = %i',
            (int) $txn['id'],
        );

        if (strtotime((string) $txn['expires']) < time()) {
            return $this->invalidGrant();
        }

        $client = $db->fetchRow(
            'SELECT * FROM hosting_core_data_sources WHERE id = %i',
            (int) $txn['client'],
        );
        if ($client === null || (string) $client['ds_id'] !== (string) ($form['client_id'] ?? '')) {
            return $this->invalidGrant();
        }

        try {
            $secret = ($this->cipher ?? DsSecretCipher::forConfig($this->config))
                ->decrypt((string) $client['oidc_client_secret']);
        } catch (\Throwable $e) {
            ErrorLogger::warn('OIDC OP client secret decrypt failed', [
                'client' => (string) $client['ds_id'],
                'error' => $e->getMessage(),
            ]);
            return $this->invalidGrant();
        }
        if (!hash_equals($secret, (string) ($form['client_secret'] ?? ''))) {
            return $this->invalidGrant();
        }

        if ((string) ($form['redirect_uri'] ?? '') !== (string) $txn['redirect_uri']) {
            return $this->invalidGrant();
        }

        $verifier = (string) ($form['code_verifier'] ?? '');
        if ($verifier === ''
            || !hash_equals(
                (string) $txn['code_challenge'],
                $this->base64url(hash('sha256', $verifier, true)),
            )
        ) {
            return $this->invalidGrant();
        }

        $user = $db->fetchRow(
            'SELECT * FROM core_system_users WHERE id = %i',
            (int) $txn['user'],
        );
        if ($user === null) {
            return $this->invalidGrant();
        }

        // Claims přesně dle RP validace (iss byte-exact, aud = client_id,
        // nonce z transakce; email_verified true — účty hostingu vznikají
        // pozvánkou/adminem). Nic navíc.
        $iat = time();
        $claims = [
            'iss'            => $issuer,
            'sub'            => (string) (int) $user['id'],
            'aud'            => (string) $client['ds_id'],
            'exp'            => $iat + self::TOKEN_TTL_SECONDS,
            'iat'            => $iat,
            'nonce'          => (string) $txn['nonce'],
            'email'          => (string) ($user['email'] ?? ''),
            'email_verified' => true,
            'name'           => (string) ($user['full_name'] ?? ''),
        ];

        try {
            $idToken = $this->keyStore()->sign($claims);
        } catch (OpKeyException $e) {
            ErrorLogger::error('OIDC OP key unavailable', ['error' => $e->getMessage()]);
            return Response::error('INTERNAL_ERROR', 'OIDC OP key unavailable', 500);
        }

        // access_token vydáváme jen kvůli tvaru OAuth odpovědi — RP čte
        // pouze id_token, žádný userinfo endpoint neexistuje.
        return Response::raw([
            'access_token' => $this->urlSafeToken(32),
            'token_type'   => 'Bearer',
            'expires_in'   => self::TOKEN_TTL_SECONDS,
            'id_token'     => $idToken,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Modul neaktivní nebo nevyplněný issuer → 404; jinak issuer setting.
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    private function gate(DataSourceConnection $db, array $tables): Response|string
    {
        if (!isset($tables['hosting_core_data_sources']) || !isset($tables['hosting_core_oidc_codes'])) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }

        $issuer = (string) ((new SettingsStore($db))->get('hosting.oidc.issuer') ?? '');
        if ($issuer === '') {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }

        // RP config i discovery porovnání issuer rtrim-ují, ale iss claim
        // se porovnává byte-exact — kanonická forma je bez trailing slash.
        return rtrim($issuer, '/');
    }

    protected function keyStore(): OpKeyStore
    {
        return $this->keyStore ?? OpKeyStore::forConfig($this->config);
    }

    private function invalidGrant(): Response
    {
        return Response::raw(['error' => 'invalid_grant'], 400);
    }

    /** Absolutní base URL požadavku — schéma z požadavku, prefix `/{ds-id}` při adresování cestou. */
    private function baseUrl(Request $request): string
    {
        return RequestBaseUrl::of($request, $this->config->getId(), $this->pathAddressed);
    }

    private function appUrl(Request $request): string
    {
        return $this->baseUrl($request) . '/app/';
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
