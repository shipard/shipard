<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\HostingApiKeyAuthenticator;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Hosting\AiGwKeyStore;
use Shipard\Core\Hosting\AiGwToken;
use Shipard\Core\Hosting\HostingUrls;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Settings\SettingsStore;

/**
 * Provisioning API pro DS servery (D3) — protistrana agenta
 * `shpd-server hosting-sync`. Hosting nikdy nevolá server; server si
 * periodicky stahuje frontu a hlásí výsledky.
 *
 * Endpoints (všechny exempt v AuthMiddleware — auth si dělá kontroler):
 *   POST /_hosting/server/reconcile  inventura DS serveru → last_seen,
 *        last_version, diff evidence vs. realita jen do logu (F2)
 *   GET  /_hosting/server/queue      fronta požadavků (lifecycle request/
 *        creating) pro tento server; servírování překlopí request →
 *        creating + claimed_at
 *   POST /_hosting/server/confirm    výsledek provisioningu: ok →
 *        lifecycle active + vazba owner v hosting_core_ds_users (U1);
 *        failed → lifecycle failed + provision_error
 *   POST /_hosting/server/stats      push agregátů per DS (D7) → upsert
 *        hosting_core_ds_stats; kadenci řídí stats_wanted v reconcile
 *        response (nejstarší snapshot serveru starší než ~10 min)
 *
 * Autentizace: `Authorization: Bearer shpd_hk_…` — prefix lookup na
 * hosting_core_servers + hash_equals nad sha256 celého tokenu (vzor
 * AuthMiddleware::handleApiKey). Klíč plní CLI `hosting-server-key`,
 * na hostingu existuje jen jako prefix + hash. Identita klíče = řádek
 * serveru; AuthContext se nepoužívá (nenese ne-uživatelské principály).
 *
 * client_secret opouští hosting jedině v queue payloadu (https,
 * jednorázově při provisioningu) — jediné místo dešifrování mimo OP
 * token endpoint.
 *
 * Spec: tasks/hosting-03-provisioning-agent.md, docs/hosting.md §5.2.
 */
class HostingServerController
{
    /** Ne-archivní stavy modelu core.system.docStatesArchive. */
    private const ACTIVE_DOC_STATES = HostingApiKeyAuthenticator::ACTIVE_DOC_STATES;

    /** Snapshot starší než tolik minut → reconcile chce nový stats push (D7). */
    private const STATS_FRESH_MINUTES = 10;

    public function __construct(
        private readonly DataSourceConfig $config,
        private readonly ?DsSecretCipher $cipher = null,
    ) {}

    /**
     * POST /_hosting/server/reconcile
     *
     * Body: {version: string, dataSources: [{ds_id, name, modules}]}
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function reconcile(Request $request, DataSourceConnection $db, array $tables): Response
    {
        $gate = $this->gate($tables);
        if ($gate !== null) {
            return $gate;
        }
        $server = $this->authenticate($request, $db);
        if ($server instanceof Response) {
            return $server;
        }

        $body = $request->getBody();
        $inventory = is_array($body['dataSources'] ?? null) ? $body['dataSources'] : [];
        $version = (string) ($body['version'] ?? '');

        if ($version !== '') {
            $db->updateWhere(
                'hosting_core_servers',
                ['last_version' => mb_substr($version, 0, 30)],
                'id = %i',
                (int) $server['id'],
            );
        }

        // F2 diff jen loguje — žádné automatické akce. Evidence = aktivní
        // DS tohoto serveru; request/creating/failed na disku být nemusí.
        $expected = $db->fetchAll(
            'SELECT ds_id FROM hosting_core_data_sources WHERE server = %i AND lifecycle = %s',
            (int) $server['id'],
            'active',
        );
        $expectedIds = array_map(static fn(array $r): string => (string) $r['ds_id'], $expected);
        $reportedIds = [];
        foreach ($inventory as $item) {
            if (is_array($item) && isset($item['ds_id'])) {
                $reportedIds[] = (string) $item['ds_id'];
            }
        }

        $missing = array_values(array_diff($expectedIds, $reportedIds));
        $extra = array_values(array_diff($reportedIds, $expectedIds));
        if ($missing !== []) {
            ErrorLogger::warn('hosting reconcile: DS v evidenci chybí na serveru', [
                'server' => (int) $server['id'],
                'dsIds' => $missing,
            ]);
        }
        if ($extra !== []) {
            ErrorLogger::warn('hosting reconcile: DS na serveru chybí v evidenci', [
                'server' => (int) $server['id'],
                'dsIds' => $extra,
            ]);
        }

        return Response::success(['ok' => true, 'stats_wanted' => $this->statsWanted($db, (int) $server['id'], $tables)]);
    }

    /**
     * GET /_hosting/server/queue
     *
     * ?peek=1 (dry-run agenta): frontu nepřeklápí na creating, nesplnitelné
     * požadavky neoznačuje failed a payload neobsahuje client_secret.
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function queue(Request $request, DataSourceConnection $db, array $tables): Response
    {
        $gate = $this->gate($tables);
        if ($gate !== null) {
            return $gate;
        }
        $server = $this->authenticate($request, $db);
        if ($server instanceof Response) {
            return $server;
        }

        if (!(bool) $server['can_provision']) {
            return Response::success(['requests' => []]);
        }

        $settings = new SettingsStore($db);
        $issuer = rtrim((string) ($settings->get('hosting.oidc.issuer') ?? ''), '/');
        if ($issuer === '') {
            // Misconfigurace hostingu, ne chyba požadavků — bez issueru nelze
            // sestavit auth.providers, fronta se neservíruje.
            ErrorLogger::warn('hosting queue: hosting.oidc.issuer není nastaven — fronta se neservíruje', [
                'server' => (int) $server['id'],
            ]);
            return Response::success(['requests' => []]);
        }
        $label = (string) ($settings->get('app.name') ?? '');
        if ($label === '') {
            $label = 'Shipard';
        }

        // creating = retry po pádu agenta (požadavek zůstal převzatý,
        // ale nedoběhl confirm).
        $rows = $db->fetchAll(
            'SELECT * FROM hosting_core_data_sources'
            . ' WHERE server = %i AND lifecycle IN %in AND docState IN %in'
            . ' ORDER BY id ASC',
            (int) $server['id'],
            ['request', 'creating'],
            self::ACTIVE_DOC_STATES,
        );

        $peek = (string) ($request->getQueryParams()['peek'] ?? '') === '1';

        $now = date('Y-m-d H:i:s');
        $requests = [];
        foreach ($rows as $row) {
            if ($peek) {
                $requests[] = [
                    'request_id' => (int) $row['id'],
                    'ds_id' => (string) $row['ds_id'],
                    'name' => (string) $row['name'],
                    'install_module' => (string) ($row['install_module'] ?? ''),
                    'web_id' => (string) ($row['web_id'] ?? ''),
                    'language' => (string) ($row['language'] ?? ''),
                    'country' => (string) ($row['country'] ?? ''),
                    'lifecycle' => (string) $row['lifecycle'],
                ];
                continue;
            }
            $item = $this->buildQueueItem($db, $row, $issuer, $label);
            if ($item === null) {
                continue;
            }
            $db->updateWhere(
                'hosting_core_data_sources',
                ['lifecycle' => 'creating', 'claimed_at' => $now, 'modified' => $now],
                'id = %i',
                (int) $row['id'],
            );
            $requests[] = $item;
        }

        return Response::success(['requests' => $requests]);
    }

    /**
     * POST /_hosting/server/confirm
     *
     * Body: {request_id: int, ds_id: string, status: "ok"|"failed",
     *        error?: string, mail_token?: string}
     *
     * mail_token (D4) = shpd_ak_ klíč z kroku mail-router-setup agenta;
     * ukládá se šifrovaně a přepisuje předchozí hodnotu. Pole
     * `analyzer_token` od staršího agenta (hosting-10 D3, registr
     * analyzerů zrušen #85 D22) se tiše ignoruje — žádná chyba, žádný zápis.
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function confirm(Request $request, DataSourceConnection $db, array $tables): Response
    {
        $gate = $this->gate($tables);
        if ($gate !== null) {
            return $gate;
        }
        $server = $this->authenticate($request, $db);
        if ($server instanceof Response) {
            return $server;
        }

        $body = $request->getBody() ?? [];
        $requestId = (int) ($body['request_id'] ?? 0);
        $dsId = (string) ($body['ds_id'] ?? '');
        $status = (string) ($body['status'] ?? '');
        if ($requestId <= 0 || $dsId === '' || !in_array($status, ['ok', 'failed'], true)) {
            return Response::error('VALIDATION_ERROR', 'request_id, ds_id and status (ok|failed) are required', 422);
        }

        $row = $db->fetchRow(
            'SELECT * FROM hosting_core_data_sources WHERE id = %i',
            $requestId,
        );
        if ($row === null) {
            return Response::error('NOT_FOUND', 'Request not found', 404);
        }
        if ((int) $row['server'] !== (int) $server['id']) {
            return Response::error('FORBIDDEN', 'Request belongs to another server', 403);
        }
        if ((string) $row['ds_id'] !== $dsId) {
            return Response::error('VALIDATION_ERROR', 'ds_id does not match the request', 422);
        }

        $now = date('Y-m-d H:i:s');
        $lifecycle = (string) $row['lifecycle'];

        if ($status === 'ok') {
            // D4 mail_token — ukládá se šifrovaně a NEPODMÍNĚNĚ (i při
            // idempotentním re-confirmu už aktivního DS; retry agenta token
            // rotuje, hosting musí držet ten poslední). Jiné klíče těla
            // (např. analyzer_token staršího agenta) se neukládají.
            foreach (['mail_token'] as $tokenColumn) {
                $token = trim((string) ($body[$tokenColumn] ?? ''));
                if ($token !== '') {
                    $cipher = $this->cipher ?? DsSecretCipher::forConfig($this->config);
                    $db->updateWhere(
                        'hosting_core_data_sources',
                        [$tokenColumn => $cipher->encrypt($token), 'modified' => $now],
                        'id = %i',
                        (int) $row['id'],
                    );
                }
            }
            if ($lifecycle !== 'active') {
                $db->updateWhere(
                    'hosting_core_data_sources',
                    ['lifecycle' => 'active', 'provision_error' => null, 'modified' => $now],
                    'id = %i',
                    (int) $row['id'],
                );
            }
            // U1: DS se vlastníkovi ihned ukáže na portálu. Idempotentně —
            // unikát (user, data_source) + explicitní check.
            $owner = $row['owner'] !== null ? (int) $row['owner'] : null;
            if ($owner !== null) {
                $existing = $db->fetchRow(
                    'SELECT id FROM hosting_core_ds_users WHERE user = %i AND data_source = %i',
                    $owner,
                    (int) $row['id'],
                );
                if ($existing === null) {
                    $db->insertRow('hosting_core_ds_users', [
                        'user' => $owner,
                        'data_source' => (int) $row['id'],
                        'role' => 'admin',
                        'created' => $now,
                        'modified' => $now,
                    ]);
                }
            } else {
                ErrorLogger::warn('hosting confirm ok: požadavek nemá vlastníka — vazba ds_users se nezaloží', [
                    'requestId' => (int) $row['id'],
                    'dsId' => $dsId,
                ]);
            }
            return Response::success(['ok' => true, 'lifecycle' => 'active']);
        }

        // status = failed
        if ($lifecycle === 'active') {
            // Opožděný/duplicitní failed po úspěšném confirmu — nedegradovat.
            ErrorLogger::warn('hosting confirm failed ignorován — DS už je active', [
                'requestId' => (int) $row['id'],
                'dsId' => $dsId,
            ]);
            return Response::success(['ok' => true, 'lifecycle' => 'active']);
        }
        $error = trim((string) ($body['error'] ?? ''));
        $db->updateWhere(
            'hosting_core_data_sources',
            [
                'lifecycle' => 'failed',
                'provision_error' => $error !== '' ? mb_substr($error, 0, 4000) : 'Unknown provisioning error',
                'modified' => $now,
            ],
            'id = %i',
            (int) $row['id'],
        );
        return Response::success(['ok' => true, 'lifecycle' => 'failed']);
    }

    /**
     * POST /_hosting/server/stats
     *
     * Body: {stats: [{ds_id: string, alerts: int|null, mail: int|null}]}
     *
     * Snapshot upsert (D7) — jeden řádek per DS (unique data_source),
     * collected_at = now. Neznámé/cizí ds_id se přeskočí s warningem do
     * logu (vzor reconcile). Jen počty — nic jiného se nepřijímá.
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function stats(Request $request, DataSourceConnection $db, array $tables): Response
    {
        $gate = $this->gate($tables);
        if ($gate !== null) {
            return $gate;
        }
        // Tabulka snapshotů přišla až s Fází 5 — hosting bez ds-upgrade
        // nesmí shodit starší akce, proto check per-action a ne v gate().
        if (!isset($tables['hosting_core_ds_stats'])) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }
        $server = $this->authenticate($request, $db);
        if ($server instanceof Response) {
            return $server;
        }

        $body = $request->getBody() ?? [];
        if (!is_array($body['stats'] ?? null)) {
            return Response::error('VALIDATION_ERROR', 'stats array is required', 422);
        }

        $now = date('Y-m-d H:i:s');
        $accepted = 0;
        foreach ($body['stats'] as $item) {
            $dsId = is_array($item) ? (string) ($item['ds_id'] ?? '') : '';
            if ($dsId === '') {
                continue;
            }
            $ds = $db->fetchRow(
                'SELECT id FROM hosting_core_data_sources WHERE ds_id = %s AND server = %i',
                $dsId,
                (int) $server['id'],
            );
            if ($ds === null) {
                ErrorLogger::warn('hosting stats: neznámé nebo cizí ds_id — položka přeskočena', [
                    'server' => (int) $server['id'],
                    'dsId' => $dsId,
                ]);
                continue;
            }

            $values = [
                'alerts_count' => is_numeric($item['alerts'] ?? null) ? (int) $item['alerts'] : null,
                'mail_count' => is_numeric($item['mail'] ?? null) ? (int) $item['mail'] : null,
                'collected_at' => $now,
            ];
            $existing = $db->fetchRow(
                'SELECT id FROM hosting_core_ds_stats WHERE data_source = %i',
                (int) $ds['id'],
            );
            if ($existing !== null) {
                $db->updateWhere('hosting_core_ds_stats', $values, 'id = %i', (int) $existing['id']);
            } else {
                $db->insertRow('hosting_core_ds_stats', $values + ['data_source' => (int) $ds['id']]);
            }
            $accepted++;
        }

        return Response::success(['ok' => true, 'accepted' => $accepted]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Modul hosting neaktivní (chybí tabulky) → 404.
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    private function gate(array $tables): ?Response
    {
        if (!isset($tables['hosting_core_servers'])
            || !isset($tables['hosting_core_data_sources'])
            || !isset($tables['hosting_core_ds_users'])
        ) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }
        return null;
    }

    /**
     * Kadence stats pushe (D7): true, když server nemá žádný snapshot nebo
     * je nejstarší `collected_at` jeho aktivních DS starší než
     * STATS_FRESH_MINUTES. Bez tabulky (hosting bez ds-upgrade) → false,
     * agent by stejně neměl kam pushovat.
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    private function statsWanted(DataSourceConnection $db, int $serverId, array $tables): bool
    {
        if (!isset($tables['hosting_core_ds_stats'])) {
            return false;
        }
        $oldest = $db->fetchSingle(
            'SELECT MIN(st.collected_at) FROM hosting_core_ds_stats st'
            . ' JOIN hosting_core_data_sources ds ON ds.id = st.data_source'
            . ' WHERE ds.server = %i AND ds.lifecycle = %s',
            $serverId,
            'active',
        );
        if ($oldest === null) {
            return true;
        }
        // Dibi vrací datetime dle konfigurace i jako string s 'T'
        // oddělovačem — porovnávat přes timestamp, ne lexikograficky.
        $oldestTs = $oldest instanceof \DateTimeInterface
            ? $oldest->getTimestamp()
            : strtotime((string) $oldest);
        if ($oldestTs === false) {
            return true;
        }
        return $oldestTs < time() - self::STATS_FRESH_MINUTES * 60;
    }

    /**
     * Bearer shpd_hk_… → řádek serveru, jinak 401. Update last_seen.
     * Validace sdílená s mail-router endpointy — HostingApiKeyAuthenticator.
     *
     * @return array<string, mixed>|Response
     */
    private function authenticate(Request $request, DataSourceConnection $db): array|Response
    {
        $authenticator = new HostingApiKeyAuthenticator(
            'hosting_core_servers',
            errorMessage: 'Server key required',
            invalidMessage: 'Invalid server key',
        );
        return $authenticator->authenticate($request, $db);
    }

    /**
     * Payload jednoho požadavku fronty; nesplnitelný požadavek (chybějící
     * vlastník, secret nejde dešifrovat, chybí url_app) → rovnou lifecycle
     * failed + provision_error a null (retry: admin opraví a přepne na
     * request).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function buildQueueItem(DataSourceConnection $db, array $row, string $issuer, string $label): ?array
    {
        $fail = function (string $error) use ($db, $row): null {
            ErrorLogger::warn('hosting queue: požadavek nelze servírovat', [
                'requestId' => (int) $row['id'],
                'dsId' => (string) $row['ds_id'],
                'error' => $error,
            ]);
            $db->updateWhere(
                'hosting_core_data_sources',
                ['lifecycle' => 'failed', 'provision_error' => $error, 'modified' => date('Y-m-d H:i:s')],
                'id = %i',
                (int) $row['id'],
            );
            return null;
        };

        $owner = $row['owner'] !== null ? $db->fetchRow(
            'SELECT id, login, full_name, email FROM core_system_users WHERE id = %i AND is_active = 1',
            (int) $row['owner'],
        ) : null;
        if ($owner === null) {
            return $fail('Owner account missing or inactive');
        }

        $host = parse_url((string) $row['url_app'], PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return $fail('url_app has no valid host');
        }

        if (($row['oidc_client_secret'] ?? null) === null) {
            return $fail('OIDC client secret is not set');
        }
        try {
            $secret = ($this->cipher ?? DsSecretCipher::forConfig($this->config))
                ->decrypt((string) $row['oidc_client_secret']);
        } catch (\Throwable $e) {
            return $fail('OIDC client secret decrypt failed: ' . $e->getMessage());
        }

        $email = (string) ($owner['email'] ?? '');
        if ($email === '') {
            // Hosting účty vznikají pozvánkou na e-mail — login bývá e-mail;
            // fallback drží agenta funkčního i pro ručně založené účty.
            $email = (string) $owner['login'];
        }

        $item = [
            'request_id' => (int) $row['id'],
            'ds_id' => (string) $row['ds_id'],
            'name' => (string) $row['name'],
            'install_module' => (string) ($row['install_module'] ?? ''),
            'web_id' => (string) ($row['web_id'] ?? ''),
            'language' => (string) ($row['language'] ?? ''),
            'country' => (string) ($row['country'] ?? ''),
            'host' => $host,
            'owner' => [
                'email' => $email,
                'name' => (string) $owner['full_name'],
                // Přesně to, co OP dává do id_tokenu jako sub.
                'sub' => (string) (int) $owner['id'],
            ],
            'oidc' => [
                'issuer' => $issuer,
                'client_id' => (string) $row['ds_id'],
                'client_secret' => $secret,
                'label' => $label,
            ],
        ];

        // AI gateway (D5): sekce jen když je gateway zřízená (org klíč
        // existuje). Selhání AI sekce provisioning neblokuje — DS vznikne
        // bez AI, backfill přes hosting-ai-token + ai-backend-set-key.
        $ai = $this->buildAiSection($db, $row, $issuer);
        if ($ai !== null) {
            $item['ai'] = $ai;
        }

        return $item;
    }

    /**
     * Sekce `ai` queue payloadu — lazy mint gateway tokenu: existující
     * aktivní token se dešifruje z token_encrypted (retry-stabilní,
     * re-provisioning), jinak se mintuje nový řádek. Pokrývá i požadavky
     * vzniklé před zavedením gateway.
     *
     * @param array<string, mixed> $row
     * @return array{base_url: string, api_key: string}|null
     */
    private function buildAiSection(DataSourceConnection $db, array $row, string $issuer): ?array
    {
        if (!AiGwKeyStore::exists($this->config->getDataSourceDir())) {
            return null;
        }

        try {
            $cipher = $this->cipher ?? DsSecretCipher::forConfig($this->config);

            $tokenRow = $db->fetchRow(
                'SELECT * FROM hosting_core_ai_tokens'
                . ' WHERE data_source = %i AND active = 1 AND docState IN %in'
                . ' ORDER BY id DESC',
                (int) $row['id'],
                self::ACTIVE_DOC_STATES,
            );

            $token = null;
            if ($tokenRow !== null && ($tokenRow['token_encrypted'] ?? null) !== null) {
                try {
                    $token = $cipher->decrypt((string) $tokenRow['token_encrypted']);
                } catch (\Throwable $e) {
                    ErrorLogger::warn('hosting queue: gateway token nejde dešifrovat — mintuje se nový', [
                        'requestId' => (int) $row['id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($token === null) {
                $minted = AiGwToken::mint();
                $now = date('Y-m-d H:i:s');
                $db->insertRow('hosting_core_ai_tokens', [
                    'data_source' => (int) $row['id'],
                    'token_prefix' => $minted['prefix'],
                    'token_hash' => $minted['hash'],
                    'token_encrypted' => $cipher->encrypt($minted['token']),
                    'active' => 1,
                    'note' => 'provisioning',
                    'created' => $now,
                    'modified' => $now,
                ]);
                $token = $minted['token'];
            }

            return [
                'base_url' => HostingUrls::aiGwBaseUrl($issuer),
                'api_key' => $token,
            ];
        } catch (\Throwable $e) {
            ErrorLogger::warn('hosting queue: ai sekci nelze sestavit — požadavek jde bez AI', [
                'requestId' => (int) $row['id'],
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
