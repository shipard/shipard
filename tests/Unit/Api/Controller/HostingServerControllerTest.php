<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\HostingServerController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Tests\Fixtures\Module\Hosting\InMemoryHostingServerDb;

class HostingServerControllerTest extends TestCase
{
    private const ISSUER = 'http://127.0.0.1/gggg-gggg-gggg-gggg/api/v1/_hosting/oidc';
    private const CLIENT_SECRET = 'plaintext-client-secret';

    private InMemoryHostingServerDb $db;
    private DsSecretCipher $cipher;
    private HostingServerController $controller;
    private int $serverId;
    private int $otherServerId;
    private int $ownerId;
    private string $token;
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        \Shipard\Core\Hosting\AiGwKeyStore::resetCache();
        foreach ($this->tempDirs as $dir) {
            $keyFile = \Shipard\Core\Hosting\AiGwKeyStore::keyFilePath($dir);
            @unlink($keyFile);
            @rmdir(dirname($keyFile));
            @rmdir($dir);
        }
        $this->tempDirs = [];
    }

    protected function setUp(): void
    {
        $this->db = InMemoryHostingServerDb::create();
        $this->db->setSetting('hosting.oidc.issuer', self::ISSUER);
        $this->cipher = DsSecretCipher::fromKey(str_repeat('k', 32));

        $random = str_repeat('a', 43);
        $this->token = 'shpd_hk_' . $random;
        $this->serverId = $this->db->addServer([
            'name' => 'Dev server',
            'fqdn' => 'dev.example.com',
            'api_key_prefix' => substr($random, 0, 12),
            'api_key_hash' => hash('sha256', $this->token),
            'can_provision' => 1,
        ]);
        $this->otherServerId = $this->db->addServer([
            'name' => 'Other server',
            'fqdn' => 'other.example.com',
        ]);
        $this->ownerId = $this->db->addUser([
            'login' => 'owner@example.com',
            'email' => 'owner@example.com',
            'full_name' => 'Owner User',
        ]);

        $this->controller = new HostingServerController(
            $this->createMock(DataSourceConfig::class),
            cipher: $this->cipher,
        );
    }

    // -------------------------------------------------------------------------
    // Gating + auth
    // -------------------------------------------------------------------------

    public function testMissingModuleTablesReturn404(): void
    {
        $resp = $this->controller->queue($this->req('GET', 'queue'), $this->db, []);
        $this->assertSame(404, $this->getStatus($resp));
    }

    public function testMissingAuthorizationHeaderIs401(): void
    {
        $resp = $this->controller->queue($this->req('GET', 'queue', token: false), $this->db, $this->tables());
        $this->assertSame(401, $this->getStatus($resp));
    }

    public function testWrongTokenIs401(): void
    {
        $resp = $this->controller->queue(
            $this->req('GET', 'queue', token: 'shpd_hk_' . str_repeat('b', 43)),
            $this->db,
            $this->tables(),
        );
        $this->assertSame(401, $this->getStatus($resp));
    }

    public function testNonHkTokenIs401(): void
    {
        $resp = $this->controller->queue(
            $this->req('GET', 'queue', token: 'shpd_ak_' . str_repeat('a', 32)),
            $this->db,
            $this->tables(),
        );
        $this->assertSame(401, $this->getStatus($resp));
    }

    public function testRevokedKeyIs401(): void
    {
        $this->db->servers[$this->serverId]['api_key_prefix'] = null;
        $this->db->servers[$this->serverId]['api_key_hash'] = null;

        $resp = $this->controller->queue($this->req('GET', 'queue'), $this->db, $this->tables());
        $this->assertSame(401, $this->getStatus($resp));
    }

    public function testArchivedServerIs401(): void
    {
        $this->db->servers[$this->serverId]['docState'] = 90;

        $resp = $this->controller->queue($this->req('GET', 'queue'), $this->db, $this->tables());
        $this->assertSame(401, $this->getStatus($resp));
    }

    public function testAuthenticatedCallUpdatesLastSeen(): void
    {
        $this->assertNull($this->db->servers[$this->serverId]['last_seen']);
        $this->controller->queue($this->req('GET', 'queue'), $this->db, $this->tables());
        $this->assertNotNull($this->db->servers[$this->serverId]['last_seen']);
    }

    // -------------------------------------------------------------------------
    // reconcile
    // -------------------------------------------------------------------------

    public function testReconcileStoresVersionAndReturnsOk(): void
    {
        $resp = $this->controller->reconcile(
            $this->req('POST', 'reconcile', body: [
                'version' => '0.1.0',
                'dataSources' => [['ds_id' => 'aaaa-aaaa-aaaa-aaaa', 'name' => 'A', 'modules' => ['install.base']]],
            ]),
            $this->db,
            $this->tables(),
        );

        $this->assertSame(200, $this->getStatus($resp));
        $this->assertSame('0.1.0', $this->db->servers[$this->serverId]['last_version']);
    }

    // -------------------------------------------------------------------------
    // reconcile — stats_wanted (D7)
    // -------------------------------------------------------------------------

    public function testReconcileStatsWantedTrueWithoutSnapshot(): void
    {
        $this->db->addDataSource(['ds_id' => 'cccc-cccc-cccc-cccc', 'name' => 'Live', 'server' => $this->serverId]);

        $resp = $this->reconcile();
        $this->assertTrue($resp->getPayload()['data']['stats_wanted']);
    }

    public function testReconcileStatsWantedFalseWithFreshSnapshot(): void
    {
        $dsId = $this->db->addDataSource(['ds_id' => 'cccc-cccc-cccc-cccc', 'name' => 'Live', 'server' => $this->serverId]);
        $this->db->addDsStat(['data_source' => $dsId, 'collected_at' => date('Y-m-d H:i:s')]);
        // Starý snapshot cizího serveru kadenci tohoto serveru neovlivňuje.
        $foreignId = $this->db->addDataSource(['ds_id' => 'ffff-ffff-ffff-ffff', 'name' => 'F', 'server' => $this->otherServerId]);
        $this->db->addDsStat(['data_source' => $foreignId, 'collected_at' => date('Y-m-d H:i:s', time() - 3600)]);

        $resp = $this->reconcile();
        $this->assertFalse($resp->getPayload()['data']['stats_wanted']);
    }

    public function testReconcileStatsWantedTrueWithStaleSnapshot(): void
    {
        // Dva DS — kadenci určuje nejstarší snapshot.
        $freshId = $this->db->addDataSource(['ds_id' => 'cccc-cccc-cccc-cccc', 'name' => 'Fresh', 'server' => $this->serverId]);
        $this->db->addDsStat(['data_source' => $freshId, 'collected_at' => date('Y-m-d H:i:s')]);
        $staleId = $this->db->addDataSource(['ds_id' => 'dddd-dddd-dddd-dddd', 'name' => 'Stale', 'server' => $this->serverId]);
        $this->db->addDsStat(['data_source' => $staleId, 'collected_at' => date('Y-m-d H:i:s', time() - 11 * 60)]);

        $resp = $this->reconcile();
        $this->assertTrue($resp->getPayload()['data']['stats_wanted']);
    }

    public function testReconcileStatsWantedFalseWithoutStatsTable(): void
    {
        // Hosting před ds-upgrade: agent nemá kam pushovat.
        $this->db->addDataSource(['ds_id' => 'cccc-cccc-cccc-cccc', 'name' => 'Live', 'server' => $this->serverId]);

        $resp = $this->controller->reconcile(
            $this->req('POST', 'reconcile', body: ['version' => '0.1.0', 'dataSources' => []]),
            $this->db,
            $this->tables(withStats: false),
        );
        $this->assertSame(200, $this->getStatus($resp));
        $this->assertFalse($resp->getPayload()['data']['stats_wanted']);
    }

    // -------------------------------------------------------------------------
    // queue
    // -------------------------------------------------------------------------

    public function testQueueServesOwnRequestsAndClaimsThem(): void
    {
        $reqId = $this->addRequest(['server' => $this->serverId]);
        // Požadavek jiného serveru se nesmí servírovat ani překlopit.
        $foreignId = $this->addRequest(['server' => $this->otherServerId, 'ds_id' => 'ffff-ffff-ffff-ffff']);
        // Aktivní DS do fronty nepatří.
        $this->db->addDataSource(['ds_id' => 'cccc-cccc-cccc-cccc', 'name' => 'Live', 'server' => $this->serverId]);

        $resp = $this->controller->queue($this->req('GET', 'queue'), $this->db, $this->tables());
        $this->assertSame(200, $this->getStatus($resp));

        $requests = $resp->getPayload()['data']['requests'];
        $this->assertCount(1, $requests);
        $item = $requests[0];
        $this->assertSame($reqId, $item['request_id']);
        $this->assertSame('bbbb-bbbb-bbbb-bbbb', $item['ds_id']);
        $this->assertSame('nova.shpd.dev', $item['host']);
        $this->assertSame('owner@example.com', $item['owner']['email']);
        $this->assertSame('Owner User', $item['owner']['name']);
        $this->assertSame((string) $this->ownerId, $item['owner']['sub']);
        $this->assertSame(self::ISSUER, $item['oidc']['issuer']);
        $this->assertSame('bbbb-bbbb-bbbb-bbbb', $item['oidc']['client_id']);
        $this->assertSame(self::CLIENT_SECRET, $item['oidc']['client_secret']);
        $this->assertSame('Shipard', $item['oidc']['label']);

        $this->assertSame('creating', $this->db->dataSources[$reqId]['lifecycle']);
        $this->assertNotNull($this->db->dataSources[$reqId]['claimed_at']);
        $this->assertSame('request', $this->db->dataSources[$foreignId]['lifecycle']);
    }

    public function testQueuePayloadCarriesLanguageAndCountry(): void
    {
        $this->addRequest(['server' => $this->serverId, 'language' => 'en', 'country' => 'sk']);

        $resp = $this->controller->queue($this->req('GET', 'queue'), $this->db, $this->tables());

        $item = $resp->getPayload()['data']['requests'][0];
        $this->assertSame('en', $item['language']);
        $this->assertSame('sk', $item['country']);
    }

    public function testQueueLabelComesFromAppName(): void
    {
        $this->db->setSetting('app.name', 'Můj hosting');
        $this->addRequest(['server' => $this->serverId]);

        $resp = $this->controller->queue($this->req('GET', 'queue'), $this->db, $this->tables());
        $this->assertSame('Můj hosting', $resp->getPayload()['data']['requests'][0]['oidc']['label']);
    }

    public function testQueueServesCreatingForRetry(): void
    {
        $reqId = $this->addRequest(['server' => $this->serverId, 'lifecycle' => 'creating']);

        $resp = $this->controller->queue($this->req('GET', 'queue'), $this->db, $this->tables());
        $this->assertCount(1, $resp->getPayload()['data']['requests']);
        $this->assertSame('creating', $this->db->dataSources[$reqId]['lifecycle']);
    }

    public function testQueuePeekDoesNotClaimAndOmitsSecret(): void
    {
        $reqId = $this->addRequest(['server' => $this->serverId]);

        $resp = $this->controller->queue(
            Request::fromArray('GET', '/api/v1/_hosting/server/queue', ['peek' => '1'], '', [
                'HTTP_HOST' => '127.0.0.1',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            ]),
            $this->db,
            $this->tables(),
        );

        $requests = $resp->getPayload()['data']['requests'];
        $this->assertCount(1, $requests);
        $this->assertArrayNotHasKey('oidc', $requests[0]);
        $this->assertArrayNotHasKey('owner', $requests[0]);
        $this->assertSame('bbbb-bbbb-bbbb-bbbb', $requests[0]['ds_id']);
        // Peek má ukazovat, co reálně poletí — včetně vrstvy A.
        $this->assertSame('cs', $requests[0]['language']);
        $this->assertSame('cz', $requests[0]['country']);
        // Peek frontu nepřeklápí.
        $this->assertSame('request', $this->db->dataSources[$reqId]['lifecycle']);
        $this->assertNull($this->db->dataSources[$reqId]['claimed_at']);
    }

    public function testQueueEmptyWithoutCanProvision(): void
    {
        $this->db->servers[$this->serverId]['can_provision'] = 0;
        $this->addRequest(['server' => $this->serverId]);

        $resp = $this->controller->queue($this->req('GET', 'queue'), $this->db, $this->tables());
        $this->assertSame([], $resp->getPayload()['data']['requests']);
    }

    public function testQueueEmptyWithoutIssuerSetting(): void
    {
        $this->db->settings = [];
        $reqId = $this->addRequest(['server' => $this->serverId]);

        $resp = $this->controller->queue($this->req('GET', 'queue'), $this->db, $this->tables());
        $this->assertSame([], $resp->getPayload()['data']['requests']);
        // Misconfigurace hostingu požadavek nezabíjí — zůstává request.
        $this->assertSame('request', $this->db->dataSources[$reqId]['lifecycle']);
    }

    public function testQueueRequestWithoutOwnerFailsTheRequest(): void
    {
        $reqId = $this->addRequest(['server' => $this->serverId, 'owner' => null]);

        $resp = $this->controller->queue($this->req('GET', 'queue'), $this->db, $this->tables());
        $this->assertSame([], $resp->getPayload()['data']['requests']);
        $this->assertSame('failed', $this->db->dataSources[$reqId]['lifecycle']);
        $this->assertNotNull($this->db->dataSources[$reqId]['provision_error']);
    }

    public function testQueueRequestWithoutSecretFailsTheRequest(): void
    {
        $reqId = $this->addRequest(['server' => $this->serverId, 'oidc_client_secret' => null]);

        $resp = $this->controller->queue($this->req('GET', 'queue'), $this->db, $this->tables());
        $this->assertSame([], $resp->getPayload()['data']['requests']);
        $this->assertSame('failed', $this->db->dataSources[$reqId]['lifecycle']);
    }

    // -------------------------------------------------------------------------
    // queue — ai sekce (D5)
    // -------------------------------------------------------------------------

    public function testQueueWithoutOrgKeyHasNoAiSection(): void
    {
        // Default mock config → AiGwKeyStore::exists false.
        $this->addRequest(['server' => $this->serverId]);

        $resp = $this->controller->queue($this->req('GET', 'queue'), $this->db, $this->tables());
        $this->assertArrayNotHasKey('ai', $resp->getPayload()['data']['requests'][0]);
        $this->assertSame([], $this->db->aiTokens);
    }

    public function testQueueWithOrgKeyMintsGatewayToken(): void
    {
        $reqId = $this->addRequest(['server' => $this->serverId]);
        $controller = $this->controllerWithOrgKey();

        $resp = $controller->queue($this->req('GET', 'queue'), $this->db, $this->tables());
        $item = $resp->getPayload()['data']['requests'][0];

        $this->assertArrayHasKey('ai', $item);
        $this->assertSame(
            'http://127.0.0.1/gggg-gggg-gggg-gggg/api/v1/_hosting/ai-gw',
            $item['ai']['base_url'],
        );
        $token = $item['ai']['api_key'];
        $this->assertMatchesRegularExpression('/^shpd_gw_[A-Za-z0-9_-]{43}$/', $token);

        // Mint založil řádek: prefix + hash sedí s tokenem, plaintext jen
        // šifrovaně.
        $this->assertCount(1, $this->db->aiTokens);
        $row = array_values($this->db->aiTokens)[0];
        $this->assertSame($reqId, $row['data_source']);
        $this->assertSame(substr($token, strlen('shpd_gw_'), 12), $row['token_prefix']);
        $this->assertSame(hash('sha256', $token), $row['token_hash']);
        $this->assertSame($token, $this->cipher->decrypt((string) $row['token_encrypted']));
    }

    public function testQueueReusesExistingActiveToken(): void
    {
        $reqId = $this->addRequest(['server' => $this->serverId]);
        $existing = 'shpd_gw_' . str_repeat('e', 43);
        $this->db->addAiToken([
            'data_source' => $reqId,
            'token_prefix' => substr($existing, strlen('shpd_gw_'), 12),
            'token_hash' => hash('sha256', $existing),
            'token_encrypted' => $this->cipher->encrypt($existing),
        ]);

        $resp = $this->controllerWithOrgKey()->queue($this->req('GET', 'queue'), $this->db, $this->tables());

        // Retry-stabilní: stejný token, žádný nový řádek.
        $this->assertSame($existing, $resp->getPayload()['data']['requests'][0]['ai']['api_key']);
        $this->assertCount(1, $this->db->aiTokens);
    }

    public function testQueueUndecryptableTokenMintsNewOne(): void
    {
        $reqId = $this->addRequest(['server' => $this->serverId]);
        $this->db->addAiToken([
            'data_source' => $reqId,
            'token_prefix' => 'xxxxxxxxxxxx',
            'token_hash' => str_repeat('0', 64),
            'token_encrypted' => 'v1:not:decryptable:garbage',
        ]);

        $resp = $this->controllerWithOrgKey()->queue($this->req('GET', 'queue'), $this->db, $this->tables());

        $token = $resp->getPayload()['data']['requests'][0]['ai']['api_key'];
        $this->assertMatchesRegularExpression('/^shpd_gw_/', $token);
        $this->assertCount(2, $this->db->aiTokens);
    }

    public function testQueuePeekHasNoAiSection(): void
    {
        $this->addRequest(['server' => $this->serverId]);

        $resp = $this->controllerWithOrgKey()->queue(
            Request::fromArray('GET', '/api/v1/_hosting/server/queue', ['peek' => '1'], '', [
                'HTTP_HOST' => '127.0.0.1',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            ]),
            $this->db,
            $this->tables(),
        );

        // Peek payload nesmí nést plaintext token — ai sekce tam nepatří.
        $this->assertArrayNotHasKey('ai', $resp->getPayload()['data']['requests'][0]);
        $this->assertSame([], $this->db->aiTokens);
    }

    /** Controller s configem ukazujícím na temp DS dir s org klíčem. */
    private function controllerWithOrgKey(): HostingServerController
    {
        $tempDir = sys_get_temp_dir() . '/shpd_hsc_aigw_' . uniqid();
        mkdir($tempDir, 0755, true);
        \Shipard\Core\Hosting\AiGwKeyStore::write($tempDir, 'sk-ant-org-key');
        $this->tempDirs[] = $tempDir;

        $config = $this->createMock(DataSourceConfig::class);
        $config->method('getDataSourceDir')->willReturn($tempDir);

        return new HostingServerController($config, cipher: $this->cipher);
    }

    // -------------------------------------------------------------------------
    // confirm
    // -------------------------------------------------------------------------

    public function testConfirmOkActivatesAndLinksOwner(): void
    {
        $reqId = $this->addRequest(['server' => $this->serverId, 'lifecycle' => 'creating']);

        $resp = $this->confirm($reqId, 'bbbb-bbbb-bbbb-bbbb', 'ok');
        $this->assertSame(200, $this->getStatus($resp));

        $row = $this->db->dataSources[$reqId];
        $this->assertSame('active', $row['lifecycle']);
        $this->assertNull($row['provision_error']);

        $this->assertCount(1, $this->db->dsUsers);
        $link = array_values($this->db->dsUsers)[0];
        $this->assertSame($this->ownerId, (int) $link['user']);
        $this->assertSame($reqId, (int) $link['data_source']);
        $this->assertSame('admin', $link['role']);
    }

    public function testConfirmOkIsIdempotent(): void
    {
        $reqId = $this->addRequest(['server' => $this->serverId, 'lifecycle' => 'creating']);

        $this->confirm($reqId, 'bbbb-bbbb-bbbb-bbbb', 'ok');
        $resp = $this->confirm($reqId, 'bbbb-bbbb-bbbb-bbbb', 'ok');

        $this->assertSame(200, $this->getStatus($resp));
        $this->assertSame('active', $this->db->dataSources[$reqId]['lifecycle']);
        $this->assertCount(1, $this->db->dsUsers);
    }

    public function testConfirmFailedStoresError(): void
    {
        $reqId = $this->addRequest(['server' => $this->serverId, 'lifecycle' => 'creating']);

        $resp = $this->confirm($reqId, 'bbbb-bbbb-bbbb-bbbb', 'failed', 'domain-add failed: exit 1');
        $this->assertSame(200, $this->getStatus($resp));

        $row = $this->db->dataSources[$reqId];
        $this->assertSame('failed', $row['lifecycle']);
        $this->assertSame('domain-add failed: exit 1', $row['provision_error']);
        $this->assertCount(0, $this->db->dsUsers);
    }

    public function testConfirmFailedAfterOkDoesNotDowngrade(): void
    {
        $reqId = $this->addRequest(['server' => $this->serverId, 'lifecycle' => 'creating']);

        $this->confirm($reqId, 'bbbb-bbbb-bbbb-bbbb', 'ok');
        $resp = $this->confirm($reqId, 'bbbb-bbbb-bbbb-bbbb', 'failed', 'stale error');

        $this->assertSame(200, $this->getStatus($resp));
        $this->assertSame('active', $this->db->dataSources[$reqId]['lifecycle']);
    }

    public function testConfirmOkStoresMailTokenEncrypted(): void
    {
        $reqId = $this->addRequest(['server' => $this->serverId, 'lifecycle' => 'creating']);
        $token = 'shpd_ak_' . str_repeat('c', 32);

        $resp = $this->confirm($reqId, 'bbbb-bbbb-bbbb-bbbb', 'ok', mailToken: $token);
        $this->assertSame(200, $this->getStatus($resp));

        $stored = (string) $this->db->dataSources[$reqId]['mail_token'];
        $this->assertNotSame($token, $stored);
        $this->assertSame($token, $this->cipher->decrypt($stored));
    }

    public function testConfirmWithoutMailTokenLeavesColumnUntouched(): void
    {
        $reqId = $this->addRequest([
            'server' => $this->serverId,
            'lifecycle' => 'creating',
            'mail_token' => $this->cipher->encrypt('shpd_ak_' . str_repeat('d', 32)),
        ]);

        $this->confirm($reqId, 'bbbb-bbbb-bbbb-bbbb', 'ok');

        $this->assertSame(
            'shpd_ak_' . str_repeat('d', 32),
            $this->cipher->decrypt((string) $this->db->dataSources[$reqId]['mail_token']),
        );
    }

    public function testConfirmMailTokenOverwritesOnActiveReconfirm(): void
    {
        // Retry agenta rotuje token — hosting drží poslední, i když už je
        // DS active (lifecycle update se přeskočí, token ne).
        $reqId = $this->addRequest([
            'server' => $this->serverId,
            'lifecycle' => 'active',
            'mail_token' => $this->cipher->encrypt('shpd_ak_' . str_repeat('d', 32)),
        ]);
        $newToken = 'shpd_ak_' . str_repeat('e', 32);

        $resp = $this->confirm($reqId, 'bbbb-bbbb-bbbb-bbbb', 'ok', mailToken: $newToken);
        $this->assertSame(200, $this->getStatus($resp));

        $this->assertSame(
            $newToken,
            $this->cipher->decrypt((string) $this->db->dataSources[$reqId]['mail_token']),
        );
    }

    public function testConfirmIgnoresAnalyzerTokenFromOlderAgent(): void
    {
        // Registr analyzerů zanikl (#85 D22): starší agent, který ještě
        // posílá analyzer_token, nesmí dostat chybu — pole se jen nezapíše,
        // mail_token z téhož těla se uloží normálně.
        $reqId = $this->addRequest(['server' => $this->serverId, 'lifecycle' => 'creating']);
        $mailToken = 'shpd_ak_' . str_repeat('c', 32);

        $resp = $this->confirm(
            $reqId,
            'bbbb-bbbb-bbbb-bbbb',
            'ok',
            mailToken: $mailToken,
            analyzerToken: 'shpd_ak_' . str_repeat('f', 32),
        );
        $this->assertSame(200, $this->getStatus($resp));

        $row = $this->db->dataSources[$reqId];
        $this->assertSame($mailToken, $this->cipher->decrypt((string) $row['mail_token']));
        $this->assertArrayNotHasKey('analyzer_token', $row);
        $this->assertSame('active', $row['lifecycle']);
    }

    public function testConfirmForeignRequestIs403(): void
    {
        $reqId = $this->addRequest(['server' => $this->otherServerId]);

        $resp = $this->confirm($reqId, 'bbbb-bbbb-bbbb-bbbb', 'ok');
        $this->assertSame(403, $this->getStatus($resp));
        $this->assertSame('request', $this->db->dataSources[$reqId]['lifecycle']);
    }

    public function testConfirmUnknownRequestIs404(): void
    {
        $resp = $this->confirm(999, 'bbbb-bbbb-bbbb-bbbb', 'ok');
        $this->assertSame(404, $this->getStatus($resp));
    }

    public function testConfirmDsIdMismatchIs422(): void
    {
        $reqId = $this->addRequest(['server' => $this->serverId]);

        $resp = $this->confirm($reqId, 'zzzz-zzzz-zzzz-zzzz', 'ok');
        $this->assertSame(422, $this->getStatus($resp));
        $this->assertSame('request', $this->db->dataSources[$reqId]['lifecycle']);
    }

    public function testConfirmInvalidBodyIs422(): void
    {
        $resp = $this->controller->confirm(
            $this->req('POST', 'confirm', body: ['request_id' => 1, 'ds_id' => 'x', 'status' => 'maybe']),
            $this->db,
            $this->tables(),
        );
        $this->assertSame(422, $this->getStatus($resp));
    }

    // -------------------------------------------------------------------------
    // stats (D7)
    // -------------------------------------------------------------------------

    public function testStatsInsertsSnapshot(): void
    {
        $dsId = $this->db->addDataSource(['ds_id' => 'cccc-cccc-cccc-cccc', 'name' => 'Live', 'server' => $this->serverId]);

        $resp = $this->stats([['ds_id' => 'cccc-cccc-cccc-cccc', 'alerts' => 3, 'mail' => 5]]);

        $this->assertSame(200, $this->getStatus($resp));
        $this->assertSame(1, $resp->getPayload()['data']['accepted']);
        $this->assertCount(1, $this->db->dsStats);
        $row = array_values($this->db->dsStats)[0];
        $this->assertSame($dsId, (int) $row['data_source']);
        $this->assertSame(3, $row['alerts_count']);
        $this->assertSame(5, $row['mail_count']);
        $this->assertNotNull($row['collected_at']);
    }

    public function testStatsUpsertOverwritesExistingRow(): void
    {
        $this->db->addDataSource(['ds_id' => 'cccc-cccc-cccc-cccc', 'name' => 'Live', 'server' => $this->serverId]);

        $this->stats([['ds_id' => 'cccc-cccc-cccc-cccc', 'alerts' => 3, 'mail' => 5]]);
        $resp = $this->stats([['ds_id' => 'cccc-cccc-cccc-cccc', 'alerts' => 0, 'mail' => null]]);

        $this->assertSame(1, $resp->getPayload()['data']['accepted']);
        // Snapshot — druhý push přepisuje řádek, tabulka neroste.
        $this->assertCount(1, $this->db->dsStats);
        $row = array_values($this->db->dsStats)[0];
        $this->assertSame(0, $row['alerts_count']);
        $this->assertNull($row['mail_count']);
    }

    public function testStatsSkipsUnknownAndForeignDsIds(): void
    {
        $this->db->addDataSource(['ds_id' => 'cccc-cccc-cccc-cccc', 'name' => 'Live', 'server' => $this->serverId]);
        $this->db->addDataSource(['ds_id' => 'ffff-ffff-ffff-ffff', 'name' => 'F', 'server' => $this->otherServerId]);

        $resp = $this->stats([
            ['ds_id' => 'cccc-cccc-cccc-cccc', 'alerts' => 1, 'mail' => 1],
            ['ds_id' => 'ffff-ffff-ffff-ffff', 'alerts' => 2, 'mail' => 2],
            ['ds_id' => 'zzzz-zzzz-zzzz-zzzz', 'alerts' => 3, 'mail' => 3],
            'garbage',
        ]);

        $this->assertSame(200, $this->getStatus($resp));
        $this->assertSame(1, $resp->getPayload()['data']['accepted']);
        $this->assertCount(1, $this->db->dsStats);
    }

    public function testStatsWithoutStatsArrayIs422(): void
    {
        $resp = $this->controller->stats(
            $this->req('POST', 'stats', body: ['foo' => 'bar']),
            $this->db,
            $this->tables(),
        );
        $this->assertSame(422, $this->getStatus($resp));
    }

    public function testStatsWithoutStatsTableIs404(): void
    {
        $resp = $this->controller->stats(
            $this->req('POST', 'stats', body: ['stats' => []]),
            $this->db,
            $this->tables(withStats: false),
        );
        $this->assertSame(404, $this->getStatus($resp));
    }

    public function testStatsMissingAuthorizationHeaderIs401(): void
    {
        $resp = $this->controller->stats(
            $this->req('POST', 'stats', body: ['stats' => []], token: false),
            $this->db,
            $this->tables(),
        );
        $this->assertSame(401, $this->getStatus($resp));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @param list<mixed> $items */
    private function stats(array $items): Response
    {
        return $this->controller->stats(
            $this->req('POST', 'stats', body: ['stats' => $items]),
            $this->db,
            $this->tables(),
        );
    }

    private function reconcile(): Response
    {
        return $this->controller->reconcile(
            $this->req('POST', 'reconcile', body: ['version' => '0.1.0', 'dataSources' => []]),
            $this->db,
            $this->tables(),
        );
    }

    /** Řádek požadavku se všemi náležitostmi; overrides umí i nully. */
    private function addRequest(array $overrides = []): int
    {
        return $this->db->addDataSource(array_merge([
            'ds_id' => 'bbbb-bbbb-bbbb-bbbb',
            'name' => 'Nová firma',
            'web_id' => 'nova',
            'url_app' => 'https://nova.shpd.dev',
            'install_module' => 'install.base',
            'lifecycle' => 'request',
            'owner' => $this->ownerId,
            'oidc_client_secret' => $this->cipher->encrypt(self::CLIENT_SECRET),
            'oidc_redirect_uri' => 'https://nova.shpd.dev/api/v1/_auth/oidc/callback',
        ], $overrides));
    }

    private function confirm(
        int $requestId,
        string $dsId,
        string $status,
        ?string $error = null,
        ?string $mailToken = null,
        ?string $analyzerToken = null,
    ): Response {
        $body = ['request_id' => $requestId, 'ds_id' => $dsId, 'status' => $status];
        if ($error !== null) {
            $body['error'] = $error;
        }
        if ($mailToken !== null) {
            $body['mail_token'] = $mailToken;
        }
        if ($analyzerToken !== null) {
            $body['analyzer_token'] = $analyzerToken;
        }
        return $this->controller->confirm(
            $this->req('POST', 'confirm', body: $body),
            $this->db,
            $this->tables(),
        );
    }

    /** @param bool $withStats false = hosting před ds-upgrade (bez ds_stats) */
    private function tables(bool $withStats = true): array
    {
        $tables = [
            'hosting_core_servers' => $this->makeTable('hosting_core_servers'),
            'hosting_core_data_sources' => $this->makeTable('hosting_core_data_sources'),
            'hosting_core_ds_users' => $this->makeTable('hosting_core_ds_users'),
        ];
        if ($withStats) {
            $tables['hosting_core_ds_stats'] = $this->makeTable('hosting_core_ds_stats');
        }
        return $tables;
    }

    private function makeTable(string $name): TableDefinition
    {
        return TableDefinition::fromArray([
            'tableId' => 1,
            'name' => $name,
            'adminOnly' => true,
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
                ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
            ],
        ]);
    }

    /** @param array<string, mixed> $body */
    private function req(string $method, string $action, array $body = [], string|false|null $token = null): Request
    {
        $server = ['HTTP_HOST' => '127.0.0.1'];
        if ($token !== false) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . ($token ?? $this->token);
        }
        return Request::fromArray(
            $method,
            '/api/v1/_hosting/server/' . $action,
            [],
            $body === [] ? '' : (string) json_encode($body),
            $server,
        );
    }

    private function getStatus(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        return $ref->getProperty('status')->getValue($response);
    }
}
