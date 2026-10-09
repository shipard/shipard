<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\AnalysisController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Security\DsSecretCipher;

/**
 * Unit testy pro AnalysisController. Pokrývají auth gate, validace,
 * branch logiku. Plnou SQL cestu (insert do více tabulek v jedné tx)
 * pokrývají integration testy proti reálné DB.
 *
 * DsSecretCipher používáme reálný (s tmp secrets.key), abychom otestovali
 * decrypt path v /claim a chybový stav když klíč chybí.
 */
class AnalysisControllerTest extends TestCase
{
    private string $tmpDir;
    private DataSourceConfig $config;

    protected function setUp(): void
    {
        DsSecretCipher::resetCache();
        $this->tmpDir = sys_get_temp_dir() . '/shpd_analysis_test_' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir . '/config', 0700, true);
        file_put_contents($this->tmpDir . '/config/main.json', json_encode([
            'id' => 'test-test-test-test',
            'name' => 'Analysis Test',
            'database_name' => 'test_db',
            'database_user' => 'test',
            'database_password' => 'pw',
            'created' => date('c'),
        ]));
        DsSecretCipher::generateKey($this->tmpDir);
        $this->config = new DataSourceConfig($this->tmpDir);
    }

    protected function tearDown(): void
    {
        DsSecretCipher::resetCache();
        $this->rrmdir($this->tmpDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @chmod($path, 0600);
                @unlink($path);
            }
        }
        @chmod($dir, 0700);
        @rmdir($dir);
    }

    private function controller(DataSourceConnection $db): AnalysisController
    {
        return new AnalysisController(
            $db,
            $this->config,
            $this->tmpDir,
            [],
            new DocumentRegistry(),
        );
    }

    private function request(string $method, string $path, array $headers = [], array $body = []): Request
    {
        $server = ['HTTP_HOST' => 'test', 'REMOTE_ADDR' => '127.0.0.1'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $rawBody = $body === [] ? '' : (string) json_encode($body);
        return Request::fromArray($method, $path, [], $rawBody, $server);
    }

    private function statusOf(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        $prop = $ref->getProperty('status');
        return (int) $prop->getValue($response);
    }

    private function analyzerAuth(): AuthContext
    {
        return new AuthContext(true, 7, 'api_key', 'shpd_ak_xxx');
    }

    private function userAuth(): AuthContext
    {
        return new AuthContext(true, 100, 'session', 'shpd_st_xxx');
    }

    // -------------------------------------------------------------------
    // Auth gate (analyzer endpoints)
    // -------------------------------------------------------------------

    public function testQueueRejectsAnonymous(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $response = $ctrl->queue(AuthContext::anonymous(), $this->request('GET', '/_mail/analysis/queue'));

        $this->assertSame(401, $this->statusOf($response));
    }

    public function testQueueRejectsSessionToken(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $response = $ctrl->queue($this->userAuth(), $this->request('GET', '/_mail/analysis/queue'));

        $this->assertSame(401, $this->statusOf($response));
    }

    public function testQueueRejectsWrongUser(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => 'admin']);
        $ctrl = $this->controller($db);

        $response = $ctrl->queue($this->analyzerAuth(), $this->request('GET', '/_mail/analysis/queue'));

        $this->assertSame(403, $this->statusOf($response));
        $this->assertSame('FORBIDDEN', $response->getPayload()['error']['code']);
    }

    // -------------------------------------------------------------------
    // /queue
    // -------------------------------------------------------------------

    public function testQueueReturnsEmptyWhenNoMessages(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_ai_analyzer'],   // auth user
            ['id' => 1],                   // default profile
        );
        $db->method('fetchAll')->willReturn([]);
        $db->method('fetchSingle')->willReturn(0);

        $ctrl = $this->controller($db);
        $response = $ctrl->queue($this->analyzerAuth(), $this->request('GET', '/_mail/analysis/queue'));

        $this->assertSame(200, $this->statusOf($response));
        $payload = $response->getPayload();
        $this->assertTrue($payload['success']);
        $this->assertSame([], $payload['data']['messages']);
        $this->assertSame(0, $payload['data']['total_available']);
    }

    public function testQueueReturnsMessagesWithRecommendedProfile(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_ai_analyzer'],
            ['id' => 17],  // default profile
        );
        $db->method('fetchAll')->willReturn([
            [
                'ndx' => 100,
                'received_at' => '2026-04-26 10:00:00',
                'subject' => 'Faktura č. 1',
                'sender_email' => 'a@example.com',
                'profile_override' => null,
                'raw_source_attachment' => 5,
            ],
            [
                'ndx' => 101,
                'received_at' => '2026-04-26 10:05:00',
                'subject' => 'Faktura č. 2',
                'sender_email' => 'b@example.com',
                'profile_override' => 42,   // explicit override on this msg
                'raw_source_attachment' => 6,
            ],
        ]);
        $db->method('fetchSingle')->willReturn(2); // attachment count + total

        $ctrl = $this->controller($db);
        $response = $ctrl->queue($this->analyzerAuth(), $this->request('GET', '/_mail/analysis/queue'));

        $payload = $response->getPayload();
        $this->assertCount(2, $payload['data']['messages']);
        $this->assertSame(17, $payload['data']['messages'][0]['recommended_profile_ndx']);
        $this->assertSame(42, $payload['data']['messages'][1]['recommended_profile_ndx']);
        $this->assertTrue($payload['data']['messages'][0]['has_raw_source']);
    }

    public function testQueueFiltersDisabledMailboxes(): void
    {
        // SQL obou dotazů (výdej i COUNT) musí nést JOIN na schránku
        // a podmínku "schránka povolena NEBO message-level enabled=1".
        // Reálné vyhodnocení SQL kryjí integration testy.
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_ai_analyzer'],
            ['id' => 1],
        );
        $queueArgs = null;
        $db->method('fetchAll')->willReturnCallback(
            static function (...$args) use (&$queueArgs): array {
                $queueArgs = $args;
                return [];
            },
        );
        $countArgs = null;
        $db->method('fetchSingle')->willReturnCallback(
            static function (...$args) use (&$countArgs): int {
                $countArgs = $args;
                return 0;
            },
        );

        $ctrl = $this->controller($db);
        $ctrl->queue($this->analyzerAuth(), $this->request('GET', '/_mail/analysis/queue'));

        foreach (['výdej' => $queueArgs, 'COUNT' => $countArgs] as $label => $args) {
            $this->assertNotNull($args, $label);
            $sql = (string) $args[0];
            $this->assertStringContainsString('JOIN %n mb ON mb.id = m.mailbox', $sql, $label);
            $this->assertStringContainsString(
                '(mb.ai_analysis_disabled = %i OR m.ai_analysis_enabled = %i)',
                $sql,
                $label,
            );
            $this->assertContains('core_mail_mailboxes', $args, $label);
        }
    }

    public function testQueueExcludesMessagesUnderPreprocessing(): void
    {
        // Gate předzpracování (tasks/mail-preprocess.md D9): oba dotazy
        // (výdej i COUNT) musí vyřadit preprocess_state 10/20.
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_ai_analyzer'],
            ['id' => 1],
        );
        $queueArgs = null;
        $db->method('fetchAll')->willReturnCallback(
            static function (...$args) use (&$queueArgs): array {
                $queueArgs = $args;
                return [];
            },
        );
        $countArgs = null;
        $db->method('fetchSingle')->willReturnCallback(
            static function (...$args) use (&$countArgs): int {
                $countArgs = $args;
                return 0;
            },
        );

        $ctrl = $this->controller($db);
        $ctrl->queue($this->analyzerAuth(), $this->request('GET', '/_mail/analysis/queue'));

        foreach (['výdej' => $queueArgs, 'COUNT' => $countArgs] as $label => $args) {
            $this->assertNotNull($args, $label);
            $this->assertStringContainsString('AND m.preprocess_state NOT IN %in', (string) $args[0], $label);
            $this->assertContains([10, 20], $args, $label);
        }
    }

    // -------------------------------------------------------------------
    // /claim — pre-decrypt branches (no real DB needed)
    // -------------------------------------------------------------------

    public function testClaimReturns409WhenMessageIsBeingPreprocessed(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => '_ai_analyzer']);
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturn(new \Dibi\Row([
            'id' => 42,
            'analysis_state' => 10,
            'preprocess_state' => 20, // runner právě běží
            'profile_override' => null,
        ]));
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $response = $ctrl->claim($this->analyzerAuth(), $this->request('POST', '/x', [], ['analyzer_id' => 'uuid-1']), 42);

        $this->assertSame(409, $this->statusOf($response));
        $this->assertSame('INVALID_STATE', $response->getPayload()['error']['code']);
        $this->assertStringContainsString('preprocess', $response->getPayload()['error']['message']);
    }

    public function testClaimRequiresAnalyzerId(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => '_ai_analyzer']);

        $ctrl = $this->controller($db);
        $response = $ctrl->claim($this->analyzerAuth(), $this->request('POST', '/x', [], []), 42);

        $this->assertSame(422, $this->statusOf($response));
        $this->assertSame('VALIDATION_ERROR', $response->getPayload()['error']['code']);
    }

    public function testClaimReturns404WhenMessageNotFound(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => '_ai_analyzer']);
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturn(null);
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $body = ['analyzer_id' => 'uuid-1'];
        $response = $ctrl->claim($this->analyzerAuth(), $this->request('POST', '/x', [], $body), 42);

        $this->assertSame(404, $this->statusOf($response));
        $this->assertSame('NOT_FOUND', $response->getPayload()['error']['code']);
    }

    public function testClaimReturns409WhenWrongState(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => '_ai_analyzer']);
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturn(new \Dibi\Row([
            'id' => 42,
            'analysis_state' => 30,  // already analyzed
            'profile_override' => null,
        ]));
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $body = ['analyzer_id' => 'uuid-1'];
        $response = $ctrl->claim($this->analyzerAuth(), $this->request('POST', '/x', [], $body), 42);

        $this->assertSame(409, $this->statusOf($response));
        $this->assertSame('INVALID_STATE', $response->getPayload()['error']['code']);
    }

    public function testClaimReturns409WhenAlreadyClaimed(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => '_ai_analyzer']);
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturnOnConsecutiveCalls(
            new \Dibi\Row(['id' => 42, 'analysis_state' => 10, 'profile_override' => null]),
            new \Dibi\Row(['id' => 99]), // active claim already exists
        );
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $body = ['analyzer_id' => 'uuid-1'];
        $response = $ctrl->claim($this->analyzerAuth(), $this->request('POST', '/x', [], $body), 42);

        $this->assertSame(409, $this->statusOf($response));
        $this->assertSame('ALREADY_CLAIMED', $response->getPayload()['error']['code']);
    }

    public function testClaimReturnsErrorWhenNoActiveProfile(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        // 1) auth, 2) profile resolution returns null
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_ai_analyzer'],
            null, // no default profile
        );
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturnOnConsecutiveCalls(
            new \Dibi\Row(['id' => 42, 'analysis_state' => 10, 'profile_override' => null]),
            null,
        );
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $body = ['analyzer_id' => 'uuid-1'];
        $response = $ctrl->claim($this->analyzerAuth(), $this->request('POST', '/x', [], $body), 42);

        $this->assertSame(409, $this->statusOf($response));
        $this->assertSame('NO_PROFILE', $response->getPayload()['error']['code']);
    }

    public function testClaimReturnsErrorWhenBackendKeyMissing(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_ai_analyzer'],
            // Profile
            ['id' => 17, 'profile_id' => 'czech', 'backend' => 5,
             'prompt_version' => 'v1', 'prompt_template' => 't',
             'output_schema' => '{}', 'supported_doc_types' => '[]',
             'language' => 'cs', 'confidence_thresholds' => '{}'],
            // Backend (api_key=null)
            ['id' => 5, 'backend_id' => 'default', 'provider' => 'anthropic',
             'model' => 'claude', 'api_key' => null, 'base_url' => null,
             'max_tokens' => 4096, 'temperature' => 0.0, 'is_active' => 1],
        );
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturnOnConsecutiveCalls(
            new \Dibi\Row(['id' => 42, 'analysis_state' => 10, 'profile_override' => null]),
            null,
        );
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $body = ['analyzer_id' => 'uuid-1'];
        $response = $ctrl->claim($this->analyzerAuth(), $this->request('POST', '/x', [], $body), 42);

        $this->assertSame(409, $this->statusOf($response));
        $this->assertSame('BACKEND_KEY_MISSING', $response->getPayload()['error']['code']);
    }

    // -------------------------------------------------------------------
    // /payload
    // -------------------------------------------------------------------

    public function testPayloadRequiresClaimTokenHeader(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => '_ai_analyzer']);
        $ctrl = $this->controller($db);

        $response = $ctrl->payload($this->analyzerAuth(), $this->request('GET', '/x'), 42);

        $this->assertSame(401, $this->statusOf($response));
        $this->assertSame('MISSING_CLAIM_TOKEN', $response->getPayload()['error']['code']);
    }

    public function testPayloadRejectsExpiredClaim(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_ai_analyzer'],
            [
                'id' => 1,
                'message' => 42,
                'released' => 0,
                'expires_at' => '2020-01-01 00:00:00',  // expired
            ],
        );
        $ctrl = $this->controller($db);

        $response = $ctrl->payload(
            $this->analyzerAuth(),
            $this->request('GET', '/x', ['X-Claim-Token' => 'ct_abc']),
            42,
        );

        $this->assertSame(410, $this->statusOf($response));
        $this->assertSame('CLAIM_EXPIRED', $response->getPayload()['error']['code']);
    }

    public function testPayloadRejectsTokenForDifferentMessage(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_ai_analyzer'],
            [
                'id' => 1,
                'message' => 999,    // different message
                'released' => 0,
                'expires_at' => date('Y-m-d H:i:s', time() + 300),
            ],
        );
        $ctrl = $this->controller($db);

        $response = $ctrl->payload(
            $this->analyzerAuth(),
            $this->request('GET', '/x', ['X-Claim-Token' => 'ct_abc']),
            42,
        );

        $this->assertSame(401, $this->statusOf($response));
        $this->assertSame('CLAIM_TOKEN_MISMATCH', $response->getPayload()['error']['code']);
    }

    public function testPayloadReturnsMessageAndAttachments(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_ai_analyzer'],
            [
                'id' => 1,
                'message' => 42,
                'released' => 0,
                'expires_at' => date('Y-m-d H:i:s', time() + 300),
            ],
            [
                'subject' => 'Faktura',
                'sender_email' => 'a@b.cz',
                'sender_name' => 'A. Builder',
                'body_plain' => 'Body text',
                'body_html' => null,
                'received_at' => '2026-04-26 10:00:00',
                'raw_source_attachment' => 5,
            ],
        );
        $db->method('fetchAll')->willReturn([
            ['id' => 7, 'name' => 'invoice.pdf', 'mime_type' => 'application/pdf', 'file_size' => 1024],
        ]);

        $ctrl = $this->controller($db);
        $response = $ctrl->payload(
            $this->analyzerAuth(),
            $this->request('GET', '/x', ['X-Claim-Token' => 'ct_abc']),
            42,
        );

        $this->assertSame(200, $this->statusOf($response));
        $data = $response->getPayload()['data'];
        $this->assertSame('Faktura', $data['message']['subject']);
        $this->assertCount(1, $data['attachments']);
        $this->assertSame(7, $data['attachments'][0]['ndx']);
    }

    // -------------------------------------------------------------------
    // /reanalyze (UI auth — different gate)
    // -------------------------------------------------------------------

    public function testReanalyzeRejectsAnonymous(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $response = $ctrl->reanalyze(AuthContext::anonymous(), $this->request('POST', '/x'), 42);

        $this->assertSame(401, $this->statusOf($response));
    }

    public function testReanalyzeRejectsInvalidAnalysisState(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $dibi = $this->createMock(\Dibi\Connection::class);
        // analysis_state=10 (ve frontě) — reanalyze vyžaduje 30 nebo 70
        $dibi->method('fetch')->willReturn(new \Dibi\Row([
            'id' => 42, 'docState' => 10, 'analysis_state' => 10,
        ]));
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $response = $ctrl->reanalyze($this->userAuth(), $this->request('POST', '/x'), 42);

        $this->assertSame(409, $this->statusOf($response));
        $this->assertSame('INVALID_STATE', $response->getPayload()['error']['code']);
    }

    public function testReanalyzeRejectsArchivedMessage(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $dibi = $this->createMock(\Dibi\Connection::class);
        // analysis_state validní (70), ale zpráva v Archivu → 409
        $dibi->method('fetch')->willReturn(new \Dibi\Row([
            'id' => 42, 'docState' => 80, 'analysis_state' => 70,
        ]));
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $response = $ctrl->reanalyze($this->userAuth(), $this->request('POST', '/x'), 42);

        $this->assertSame(409, $this->statusOf($response));
        $this->assertSame('INVALID_STATE', $response->getPayload()['error']['code']);
    }

    public function testReanalyzeRejectsInvalidProfileOverride(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturnOnConsecutiveCalls(
            new \Dibi\Row(['id' => 42, 'docState' => 10, 'analysis_state' => 30]),
            null, // profile_override invalid
        );
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $body = ['profile_override_ndx' => 999];
        $response = $ctrl->reanalyze($this->userAuth(), $this->request('POST', '/x', [], $body), 42);

        $this->assertSame(422, $this->statusOf($response));
        $this->assertSame('INVALID_PROFILE', $response->getPayload()['error']['code']);
    }

    public function testReanalyzeReturns404WhenMessageMissing(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturn(null);
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $response = $ctrl->reanalyze($this->userAuth(), $this->request('POST', '/x'), 42);

        $this->assertSame(404, $this->statusOf($response));
    }

    public function testReanalyzeRejectsAppliedProposalWithLiveTarget(): void
    {
        // Zpráva s target_row > 0 a poslední úspěšnou analýzou resolution=40
        // (aplikováno) → 409, nejdřív unapply (jinak osiří lineage).
        $db = $this->createMock(DataSourceConnection::class);
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturnOnConsecutiveCalls(
            new \Dibi\Row(['id' => 42, 'docState' => 40, 'analysis_state' => 30, 'target_row' => 777]),
            new \Dibi\Row(['resolution' => 40]),
        );
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $response = $ctrl->reanalyze($this->userAuth(), $this->request('POST', '/x'), 42);

        $this->assertSame(409, $this->statusOf($response));
        $this->assertSame('INVALID_STATE', $response->getPayload()['error']['code']);
    }

    /**
     * Mock dibi pro happy-path reanalyze: fetch vrací postupně řádek zprávy
     * a (volitelně) flag schránky; update data se zachytí do $captured.
     */
    private function dibiForReanalyze(array $msgRow, ?array $mailboxRow, ?array &$captured): \Dibi\Connection
    {
        $rows = [new \Dibi\Row($msgRow)];
        if ($mailboxRow !== null) {
            $rows[] = new \Dibi\Row($mailboxRow);
        }
        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
        $dibi->method('update')->willReturnCallback(
            static function (string $table, array $data) use (&$captured, $fluent): \Dibi\Fluent {
                $captured = $data;
                return $fluent;
            },
        );
        return $dibi;
    }

    public function testReanalyzeSetsEnabledOverrideForDisabledMailbox(): void
    {
        $captured = null;
        $dibi = $this->dibiForReanalyze(
            ['id' => 42, 'docState' => 20, 'analysis_state' => 30, 'target_row' => null,
                'mailbox' => 5, 'ai_analysis_enabled' => null],
            ['ai_analysis_disabled' => 1],
            $captured,
        );
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $response = $ctrl->reanalyze($this->userAuth(), $this->request('POST', '/x'), 42);

        $this->assertSame(200, $this->statusOf($response));
        $this->assertNotNull($captured);
        $this->assertSame(1, $captured['ai_analysis_enabled']);
        $this->assertSame(10, $captured['analysis_state']);
    }

    public function testReanalyzeSpawnsRunnerAfterCommitWhenQueued(): void
    {
        // D14: po commitu reanalýzy spawn runneru — jen přes wiring (closure)
        // a jen pro zprávu ve frontě (AnalysisQueue::isEligible → fetchSingle).
        $captured = null;
        $dibi = $this->dibiForReanalyze(
            ['id' => 42, 'docState' => 20, 'analysis_state' => 30, 'target_row' => null,
                'mailbox' => 5, 'ai_analysis_enabled' => 1],
            null,
            $captured,
        );
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getDibiConnection')->willReturn($dibi);
        $db->method('fetchSingle')->willReturnOnConsecutiveCalls(1, 0);
        $spawned = [];
        $ctrl = new AnalysisController(
            $db, $this->config, $this->tmpDir, [], new DocumentRegistry(),
            null, null, null, null, null,
            static function (int $id) use (&$spawned): void {
                $spawned[] = $id;
            },
        );

        $response = $ctrl->reanalyze($this->userAuth(), $this->request('POST', '/x'), 42);
        $this->assertSame(200, $this->statusOf($response));
        $this->assertSame([42], $spawned);

        // Druhé volání: zpráva ve frontě není (gate předzpracování) → bez spawnu.
        $dibi2 = $this->dibiForReanalyze(
            ['id' => 42, 'docState' => 20, 'analysis_state' => 30, 'target_row' => null,
                'mailbox' => 5, 'ai_analysis_enabled' => 1],
            null,
            $captured,
        );
        $db2 = $this->createMock(DataSourceConnection::class);
        $db2->method('getDibiConnection')->willReturn($dibi2);
        $db2->method('fetchSingle')->willReturn(0);
        $spawned = [];
        $ctrl2 = new AnalysisController(
            $db2, $this->config, $this->tmpDir, [], new DocumentRegistry(),
            null, null, null, null, null,
            static function (int $id) use (&$spawned): void {
                $spawned[] = $id;
            },
        );
        $ctrl2->reanalyze($this->userAuth(), $this->request('POST', '/x'), 42);
        $this->assertSame([], $spawned);
    }

    public function testReanalyzeKeepsOverrideUntouchedWhenAlreadyEnabled(): void
    {
        // Message-level enabled=1 → flag schránky se vůbec nedotazuje
        $captured = null;
        $dibi = $this->dibiForReanalyze(
            ['id' => 42, 'docState' => 20, 'analysis_state' => 30, 'target_row' => null,
                'mailbox' => 5, 'ai_analysis_enabled' => 1],
            null,
            $captured,
        );
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $response = $ctrl->reanalyze($this->userAuth(), $this->request('POST', '/x'), 42);

        $this->assertSame(200, $this->statusOf($response));
        $this->assertNotNull($captured);
        $this->assertArrayNotHasKey('ai_analysis_enabled', $captured);
    }

    public function testReanalyzeKeepsOverrideUntouchedForEnabledMailbox(): void
    {
        $captured = null;
        $dibi = $this->dibiForReanalyze(
            ['id' => 42, 'docState' => 20, 'analysis_state' => 30, 'target_row' => null,
                'mailbox' => 5, 'ai_analysis_enabled' => null],
            ['ai_analysis_disabled' => 0],
            $captured,
        );
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $response = $ctrl->reanalyze($this->userAuth(), $this->request('POST', '/x'), 42);

        $this->assertSame(200, $this->statusOf($response));
        $this->assertNotNull($captured);
        $this->assertArrayNotHasKey('ai_analysis_enabled', $captured);
    }

    // -------------------------------------------------------------------
    // /failed
    // -------------------------------------------------------------------

    public function testFailedRequiresClaimToken(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => '_ai_analyzer']);

        $ctrl = $this->controller($db);
        $response = $ctrl->failed($this->analyzerAuth(), $this->request('POST', '/x'), 42);

        $this->assertSame(401, $this->statusOf($response));
        $this->assertSame('MISSING_CLAIM_TOKEN', $response->getPayload()['error']['code']);
    }

    // -------------------------------------------------------------------
    // /result
    // -------------------------------------------------------------------

    // -------------------------------------------------------------------
    // /apply + /reject + /unapply (message-centricky)
    //
    // Guardy jádra (MessageProposalApplier) nad mockovanou DB — plné apply
    // cesty pokrývá AnalysisControllerExchangeTest.
    // -------------------------------------------------------------------

    /**
     * DB mock pro message-centrické akce: fetchRow routuje podle názvu
     * tabulky (první %n argument) na řádek zprávy / poslední úspěšné analýzy.
     */
    private function dbForProposal(?array $message, ?array $analysis): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static fn(string $sql, ...$args) => match ($args[0] ?? null) {
                'core_mail_incoming_messages' => $message,
                'core_mail_message_analyses'  => $analysis,
                default                       => null,
            },
        );
        return $db;
    }

    public function testApplyMessageRejectsAnonymous(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $response = $ctrl->applyMessage(
            AuthContext::anonymous(),
            $this->request('POST', '/x'),
            42,
        );

        $this->assertSame(401, $this->statusOf($response));
    }

    public function testApplyMessageReturns404WhenNotFound(): void
    {
        $db = $this->dbForProposal(null, null);

        $ctrl = $this->controller($db);
        $response = $ctrl->applyMessage($this->userAuth(), $this->request('POST', '/x'), 42);

        $this->assertSame(404, $this->statusOf($response));
        $this->assertSame('NOT_FOUND', $response->getPayload()['error']['code']);
    }

    public function testApplyMessageRejectsAlreadyResolved(): void
    {
        // Poslední analýza už nese verdikt (resolution=50) → 409.
        $db = $this->dbForProposal(
            ['id' => 42, 'docState' => 20, 'analysis_state' => 30, 'target_row' => null],
            ['id' => 9, 'resolution' => 50, 'canonical_json' => '{}', 'proposed_type' => 'invoiceReceived'],
        );

        $ctrl = $this->controller($db);
        $response = $ctrl->applyMessage($this->userAuth(), $this->request('POST', '/x'), 42);

        $this->assertSame(409, $this->statusOf($response));
        $this->assertSame('INVALID_STATE', $response->getPayload()['error']['code']);
    }

    public function testApplyMessageRejectsTrashedMessage(): void
    {
        $db = $this->dbForProposal(
            ['id' => 42, 'docState' => 90, 'analysis_state' => 30, 'target_row' => null],
            null,
        );

        $ctrl = $this->controller($db);
        $response = $ctrl->applyMessage($this->userAuth(), $this->request('POST', '/x'), 42);

        $this->assertSame(409, $this->statusOf($response));
        $this->assertSame('INVALID_STATE', $response->getPayload()['error']['code']);
    }

    public function testRejectMessageRequiresReason(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $response = $ctrl->rejectMessage(
            $this->userAuth(),
            $this->request('POST', '/x', [], []),
            42,
        );

        $this->assertSame(422, $this->statusOf($response));
        $payload = $response->getPayload();
        $this->assertSame('VALIDATION_ERROR', $payload['error']['code']);
        $this->assertSame('reason', $payload['error']['details'][0]['field']);
    }

    public function testRejectMessageRequiresNonEmptyReason(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $response = $ctrl->rejectMessage(
            $this->userAuth(),
            $this->request('POST', '/x', [], ['reason' => '   ']),
            42,
        );

        $this->assertSame(422, $this->statusOf($response));
    }

    public function testRejectMessageWritesResolutionRejected(): void
    {
        $db = $this->dbForProposal(
            ['id' => 42, 'docState' => 20, 'analysis_state' => 30, 'target_row' => null],
            ['id' => 9, 'resolution' => null, 'canonical_json' => '{}', 'proposed_type' => 'invoiceReceived'],
        );
        // writeRejectResolution: analysis update + message update v jedné tx.
        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        $fluent->method('execute');
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('update')->willReturn($fluent);
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $response = $ctrl->rejectMessage(
            $this->userAuth(),
            $this->request('POST', '/x', [], ['reason' => 'není faktura']),
            42,
        );

        $this->assertSame(200, $this->statusOf($response));
        $data = $response->getPayload()['data'];
        $this->assertSame(42, $data['messageNdx']);
        $this->assertSame(9, $data['analysisNdx']);
        $this->assertSame(50, $data['resolution']);
    }

    public function testUnapplyMessageReturns404WhenMessageMissing(): void
    {
        $db = $this->dbForProposal(null, null);

        $ctrl = $this->controller($db);
        $response = $ctrl->unapplyMessage($this->userAuth(), $this->request('POST', '/x'), 42);

        $this->assertSame(404, $this->statusOf($response));
    }

    public function testUnapplyMessageRejectsWhenLatestProposalNotApplied(): void
    {
        $db = $this->dbForProposal(
            ['id' => 42, 'docState' => 20, 'analysis_state' => 30, 'target_row' => null],
            ['id' => 9, 'resolution' => null, 'canonical_json' => '{}', 'proposed_type' => 'invoiceReceived'],
        );

        $ctrl = $this->controller($db);
        $response = $ctrl->unapplyMessage($this->userAuth(), $this->request('POST', '/x'), 42);

        $this->assertSame(409, $this->statusOf($response));
        $this->assertSame('INVALID_STATE', $response->getPayload()['error']['code']);
    }

    // -------------------------------------------------------------------
    // message_classification (spec mail-states-and-classification §B1)
    //
    // Plný /result flow potřebuje reálnou DB (insert do 3 tabulek) — tady
    // testujeme helper applyMessageClassification přes reflection, stejný
    // vzor jako validateAndStoreCanonical v AnalysisControllerExchangeTest.
    // -------------------------------------------------------------------

    /** Sloupce pozornosti, které UPDATE klasifikace nese vždy (tasks/mail-other-attention.md D3). */
    private const NO_ATTENTION = ['attention' => null, 'action_note' => null, 'action_due' => null];

    private function callApplyClassification(\Dibi\Connection $dibi, array $body): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);
        $ref = new \ReflectionClass($ctrl);
        $method = $ref->getMethod('applyMessageClassification');
        $method->invoke($ctrl, $dibi, 42, $body);
    }

    public function testClassificationUpdatesPrimaryTypeWithAiSource(): void
    {
        $whereCalls = [];
        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnCallback(
            function (string $name, array $args) use (&$whereCalls, $fluent) {
                if ($name === 'where') {
                    $whereCalls[] = $args;
                }
                return $fluent;
            },
        );
        $fluent->expects($this->once())->method('execute');

        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->expects($this->once())
            ->method('update')
            ->with(
                'core_mail_incoming_messages',
                ['primary_type' => 'other', 'primary_type_source' => 'ai', ...self::NO_ATTENTION],
            )
            ->willReturn($fluent);

        $this->callApplyClassification($dibi, [
            'message_classification' => ['primary_type' => 'other', 'confidence' => 0.97],
        ]);

        // WHERE musí obsahovat guard proti přepsání uživatelské volby
        $flat = array_map(static fn(array $args): string => implode('|', array_map('strval', $args)), $whereCalls);
        $this->assertContains('primary_type_source != %s|user', $flat);
    }

    public function testClassificationFallsBackToAnalysisJson(): void
    {
        // Analyzer daemon (bez změn) top-level pole neposílá — klasifikace
        // dorazí jen uvnitř analysis_json (celý model output).
        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        $fluent->expects($this->once())->method('execute');

        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->expects($this->once())
            ->method('update')
            ->with(
                'core_mail_incoming_messages',
                ['primary_type' => 'invoiceReceived', 'primary_type_source' => 'ai', ...self::NO_ATTENTION],
            )
            ->willReturn($fluent);

        $this->callApplyClassification($dibi, [
            'analysis_json' => [
                'overall_confidence' => 0.95,
                'message_classification' => ['primary_type' => 'invoiceReceived', 'confidence' => 0.98],
                'documents' => [],
            ],
        ]);
    }

    public function testClassificationPrefersTopLevelFieldOverAnalysisJson(): void
    {
        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        $fluent->method('execute');

        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->expects($this->once())
            ->method('update')
            ->with(
                'core_mail_incoming_messages',
                ['primary_type' => 'other', 'primary_type_source' => 'ai', ...self::NO_ATTENTION],
            )
            ->willReturn($fluent);

        $this->callApplyClassification($dibi, [
            'message_classification' => ['primary_type' => 'other', 'confidence' => 0.9],
            'analysis_json' => [
                'message_classification' => ['primary_type' => 'invoiceReceived', 'confidence' => 0.98],
            ],
        ]);
    }

    public function testClassificationIgnoresUnknownPrimaryType(): void
    {
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->expects($this->never())->method('update');

        $this->callApplyClassification($dibi, [
            'message_classification' => ['primary_type' => 'spam', 'confidence' => 0.9],
        ]);
    }

    public function testClassificationSkipsWhenFieldMissing(): void
    {
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->expects($this->never())->method('update');

        $this->callApplyClassification($dibi, ['model_name' => 'claude']);
    }

    // -------------------------------------------------------------------
    // Pozornost u zprávy bez dokladu (tasks/mail-other-attention.md D3)
    // -------------------------------------------------------------------

    /**
     * Dibi mock zachycující data jediného UPDATE klasifikace.
     *
     * @param array<string, mixed>|null $captured
     */
    private function capturingDibi(?array &$captured): \Dibi\Connection
    {
        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        $fluent->expects($this->once())->method('execute');

        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->expects($this->once())->method('update')->willReturnCallback(
            function (string $table, array $data) use (&$captured, $fluent): \Dibi\Fluent {
                $this->assertSame('core_mail_incoming_messages', $table);
                $captured = $data;
                return $fluent;
            },
        );
        return $dibi;
    }

    public function testClassificationOtherActionWritesAttentionNoteAndDue(): void
    {
        $captured = null;
        $this->callApplyClassification($this->capturingDibi($captured), [
            'message_classification' => [
                'primary_type' => 'other',
                'confidence' => 0.9,
                'attention' => 'action',
                'action_note' => "  Prodloužit 3 domény,\n jinak 15. 10. expirují.  ",
                'due_date' => '2026-10-15',
            ],
        ]);

        $this->assertSame([
            'primary_type' => 'other',
            'primary_type_source' => 'ai',
            'attention' => 'action',
            'action_note' => 'Prodloužit 3 domény, jinak 15. 10. expirují.',
            'action_due' => '2026-10-15',
        ], $captured);
    }

    public function testClassificationActionNoteIsTruncatedToColumnLength(): void
    {
        $captured = null;
        $this->callApplyClassification($this->capturingDibi($captured), [
            'message_classification' => [
                'primary_type' => 'other',
                'attention' => 'action',
                'action_note' => str_repeat('ž', 250),
            ],
        ]);

        $this->assertSame(200, mb_strlen((string) $captured['action_note']));
        $this->assertNull($captured['action_due']);
    }

    public function testClassificationInfoDropsNoteAndDueEvenWhenSent(): void
    {
        $captured = null;
        $this->callApplyClassification($this->capturingDibi($captured), [
            'message_classification' => [
                'primary_type' => 'other',
                'attention' => 'info',
                'action_note' => 'Nic nedělat',
                'due_date' => '2026-10-15',
            ],
        ]);

        $this->assertSame('info', $captured['attention']);
        $this->assertNull($captured['action_note']);
        $this->assertNull($captured['action_due']);
    }

    public function testClassificationUnknownAttentionLeavesFieldsNullButStoresType(): void
    {
        // Neznámá hodnota = warning + ignore, uložení resultu se nerozbije
        // (stejně jako neznámý primary_type) — typ se zapíše, pozornost NULL.
        $captured = null;
        $this->callApplyClassification($this->capturingDibi($captured), [
            'message_classification' => [
                'primary_type' => 'other',
                'attention' => 'urgent',
                'action_note' => 'Zaplatit',
                'due_date' => '2026-10-15',
            ],
        ]);

        $this->assertSame(['primary_type' => 'other', 'primary_type_source' => 'ai', ...self::NO_ATTENTION], $captured);
    }

    public function testClassificationDocumentTypeClearsAttentionFields(): void
    {
        // Zpráva mohla být dřív `other` s poznámkou „Zaplatit“ — reanalýzou
        // se stala fakturou, tři pole musí být NULL i když je model poslal.
        $captured = null;
        $this->callApplyClassification($this->capturingDibi($captured), [
            'message_classification' => [
                'primary_type' => 'invoiceReceived',
                'attention' => 'action',
                'action_note' => 'Zaplatit',
                'due_date' => '2026-10-15',
            ],
        ]);

        $this->assertSame(['primary_type' => 'invoiceReceived', 'primary_type_source' => 'ai', ...self::NO_ATTENTION], $captured);
    }

    public function testClassificationActionWithNullNoteDueAndPartyStoresNull(): void
    {
        // Oprava v4.7.1: model absenci vyjadřuje nullem (schéma ho od v4.7.1
        // připouští) — server uloží NULL bez warningu a typ i pozornost zapíše.
        $captured = null;
        $this->callApplyClassification($this->capturingDibi($captured), [
            'message_classification' => [
                'primary_type' => 'other',
                'confidence' => 0.9,
                'attention' => 'action',
                'action_note' => null,
                'due_date' => null,
                'party' => null,
            ],
        ]);

        $this->assertSame([
            'primary_type' => 'other',
            'primary_type_source' => 'ai',
            'attention' => 'action',
            'action_note' => null,
            'action_due' => null,
        ], $captured);
    }

    public function testClassificationInvalidDueDateYieldsNull(): void
    {
        foreach (['15. 10. 2026', '2026-02-30', '2026-10-15T00:00:00', '', 20261015] as $invalid) {
            $captured = null;
            $this->callApplyClassification($this->capturingDibi($captured), [
                'message_classification' => [
                    'primary_type' => 'other',
                    'attention' => 'action',
                    'action_note' => 'Zaplatit',
                    'due_date' => $invalid,
                ],
            ]);
            $this->assertNull($captured['action_due'], 'due_date ' . json_encode($invalid));
            $this->assertSame('Zaplatit', $captured['action_note']);
        }
    }

    // -------------------------------------------------------------------
    // ai_title (tasks/mail-message-title-partner.md D1/D2) — stejný vzor
    // přes reflection jako klasifikace.
    // -------------------------------------------------------------------

    /**
     * @param array<string, mixed>      $body
     * @param array<string, mixed>|null $canonical
     */
    private function callApplyTitle(\Dibi\Connection $dibi, array $body, ?array $canonical, ?string $proposedType): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db); // configRuntime = null → composer bez labelu typu
        $ref = new \ReflectionClass($ctrl);
        $method = $ref->getMethod('applyMessageTitle');
        $method->invoke($ctrl, $dibi, 42, $body, $canonical, $proposedType);
    }

    /** Dibi mock očekávající právě jeden UPDATE ai_title s danou hodnotou. */
    private function dibiExpectingTitle(?string $expected): \Dibi\Connection
    {
        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        $fluent->expects($this->once())->method('execute');

        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->expects($this->once())
            ->method('update')
            ->with('core_mail_incoming_messages', ['ai_title' => $expected])
            ->willReturn($fluent);
        return $dibi;
    }

    /** @return array<string, mixed> */
    private function titleCanonical(): array
    {
        return [
            'docType' => 'invoiceReceived',
            'docNumber' => 'FV-2026-0042',
            'selfParty' => 'customer',
            'supplier' => ['name' => 'Dodavatel s.r.o.', 'companyId' => '12345678'],
            'currency' => 'CZK',
            'totals' => ['totalAmount' => 13105.0],
        ];
    }

    public function testTitleFromClassificationWins(): void
    {
        $this->callApplyTitle(
            $this->dibiExpectingTitle('Faktura 2026-0042 — Dodavatel s.r.o., 13 105 Kč'),
            ['message_classification' => [
                'primary_type' => 'invoiceReceived',
                'confidence' => 0.97,
                'title' => "  Faktura 2026-0042 — Dodavatel s.r.o.,\n 13 105 Kč ",
            ]],
            $this->titleCanonical(),
            'invoiceReceived',
        );
    }

    public function testTitleFallsBackToComposerWhenMissing(): void
    {
        // Starší analyzer / prompt v4.2.0 bez title → deterministický fallback
        // z canonicalu (P8), žádná 422.
        $this->callApplyTitle(
            $this->dibiExpectingTitle('FV-2026-0042 — Dodavatel s.r.o., 13 105 CZK'),
            ['message_classification' => ['primary_type' => 'invoiceReceived', 'confidence' => 0.97]],
            $this->titleCanonical(),
            'invoiceReceived',
        );
    }

    public function testTitleReadsAnalysisJsonFallback(): void
    {
        $this->callApplyTitle(
            $this->dibiExpectingTitle('Newsletter — obchodní sdělení'),
            ['analysis_json' => ['message_classification' => ['primary_type' => 'other', 'title' => 'Newsletter — obchodní sdělení']]],
            null,
            null,
        );
    }

    public function testTitleIsNulledWhenNothingAvailable(): void
    {
        // Re-analýza bez dokumentu a bez title → ai_title = NULL (AI-vlastněný sloupec).
        $this->callApplyTitle(
            $this->dibiExpectingTitle(null),
            ['message_classification' => ['primary_type' => 'other', 'confidence' => 0.9, 'title' => '   ']],
            null,
            null,
        );
    }

    public function testTitleIsTruncatedToColumnLength(): void
    {
        $long = str_repeat('x', 250);
        $this->callApplyTitle(
            $this->dibiExpectingTitle(str_repeat('x', 200)),
            ['message_classification' => ['primary_type' => 'other', 'title' => $long]],
            null,
            null,
        );
    }

    public function testKnownPrimaryTypesFallbackWithoutConfig(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db); // configRuntime = null
        $ref = new \ReflectionClass($ctrl);
        $types = $ref->getMethod('knownPrimaryTypes')->invoke($ctrl);

        $this->assertContains('invoiceReceived', $types);
        $this->assertContains('other', $types);
        $this->assertContains('creditNote', $types); // enabled:false typy se tolerují
    }

    /** DB mock s validním analyzer auth + živou claim pro /result. */
    private function dbForResult(): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_ai_analyzer'],
            [
                'id' => 1,
                'message' => 42,
                'released' => 0,
                'expires_at' => date('Y-m-d H:i:s', time() + 300),
            ],
        );
        return $db;
    }

    public function testResultRequiresModelAndPromptVersion(): void
    {
        $ctrl = $this->controller($this->dbForResult());
        $response = $ctrl->result(
            $this->analyzerAuth(),
            $this->request('POST', '/x', ['X-Claim-Token' => 'ct_abc'], []),
            42,
        );

        $this->assertSame(422, $this->statusOf($response));
        $this->assertSame('VALIDATION_ERROR', $response->getPayload()['error']['code']);
    }

    public function testResultRejectsExtractedDocumentsField(): void
    {
        // Kontrakt v4: pole extracted_documents se nepřijímá (D11 big-bang).
        $ctrl = $this->controller($this->dbForResult());
        $response = $ctrl->result(
            $this->analyzerAuth(),
            $this->request('POST', '/x', ['X-Claim-Token' => 'ct_abc'], [
                'model_name' => 'claude',
                'prompt_version' => 'v4',
                'extracted_documents' => [],
                'message_classification' => ['primary_type' => 'invoiceReceived'],
            ]),
            42,
        );

        $this->assertSame(422, $this->statusOf($response));
        $payload = $response->getPayload();
        $this->assertSame('VALIDATION_ERROR', $payload['error']['code']);
        $this->assertSame('extracted_documents', $payload['error']['details'][0]['field']);
    }

    public function testResultRequiresMessageClassification(): void
    {
        // Kontrakt v4: message_classification s neprázdným primary_type je povinná.
        $ctrl = $this->controller($this->dbForResult());
        $response = $ctrl->result(
            $this->analyzerAuth(),
            $this->request('POST', '/x', ['X-Claim-Token' => 'ct_abc'], [
                'model_name' => 'claude',
                'prompt_version' => 'v4',
            ]),
            42,
        );

        $this->assertSame(422, $this->statusOf($response));
        $payload = $response->getPayload();
        $this->assertSame('VALIDATION_ERROR', $payload['error']['code']);
        $this->assertSame('message_classification', $payload['error']['details'][0]['field']);
    }

    public function testResultRequiresNonEmptyPrimaryType(): void
    {
        $ctrl = $this->controller($this->dbForResult());
        $response = $ctrl->result(
            $this->analyzerAuth(),
            $this->request('POST', '/x', ['X-Claim-Token' => 'ct_abc'], [
                'model_name' => 'claude',
                'prompt_version' => 'v4',
                'message_classification' => ['primary_type' => '  '],
            ]),
            42,
        );

        $this->assertSame(422, $this->statusOf($response));
        $this->assertSame('message_classification', $response->getPayload()['error']['details'][0]['field']);
    }

    public function testResultStoresCanonicalAndProposedType(): void
    {
        // Happy path bez SchemaValidatoru (passthrough): document.extracted_json
        // se uloží do canonical_json, doc_type do proposed_type, confidence
        // návrhu do confidence; INSERT nemá extracted_document_count a
        // response 201 nese jen analysis_ndx.
        $db = $this->dbForResult();

        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        $fluent->method('execute');

        $insertValues = null;
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('insert')->willReturnCallback(
            function (string $table, array $values) use (&$insertValues, $fluent) {
                if ($table === 'core_mail_message_analyses') {
                    $insertValues = $values;
                }
                return $fluent;
            },
        );
        $dibi->method('update')->willReturn($fluent);
        $dibi->method('getInsertId')->willReturn(123);
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $response = $ctrl->result(
            $this->analyzerAuth(),
            $this->request('POST', '/x', ['X-Claim-Token' => 'ct_abc'], [
                'model_name' => 'claude',
                'prompt_version' => 'v4',
                'overall_confidence' => 0.5,
                'message_classification' => ['primary_type' => 'invoiceReceived', 'confidence' => 0.97],
                'document' => [
                    'doc_type' => 'invoiceReceived',
                    'confidence' => 0.83,
                    'extracted_json' => ['docNumber' => 'FV-1'],
                ],
            ]),
            42,
        );

        $this->assertSame(201, $this->statusOf($response));
        $data = $response->getPayload()['data'];
        $this->assertSame(123, $data['analysis_ndx']);
        $this->assertArrayNotHasKey('extracted_document_ndxs', $data);

        $this->assertNotNull($insertValues);
        $this->assertSame('invoiceReceived', $insertValues['proposed_type']);
        $this->assertSame(0.83, $insertValues['confidence']); // document.confidence, ne overall
        $this->assertSame(['docNumber' => 'FV-1'], json_decode((string) $insertValues['canonical_json'], true));
        $this->assertArrayNotHasKey('extracted_document_count', $insertValues);
    }

    public function testResultPersistsContentTagColumn(): void
    {
        // Denormalizace `_resolve.contentTag.tag` z obohaceného canonicalu
        // do sloupce content_tag (tasks/content-tag-enrichment.md, D20).
        // Passthrough bez SchemaValidatoru — blok nese extracted_json.
        $db = $this->dbForResult();

        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        $fluent->method('execute');

        $insertValues = null;
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('insert')->willReturnCallback(
            function (string $table, array $values) use (&$insertValues, $fluent) {
                if ($table === 'core_mail_message_analyses') {
                    $insertValues = $values;
                }
                return $fluent;
            },
        );
        $dibi->method('update')->willReturn($fluent);
        $dibi->method('getInsertId')->willReturn(125);
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $response = $ctrl->result(
            $this->analyzerAuth(),
            $this->request('POST', '/x', ['X-Claim-Token' => 'ct_abc'], [
                'model_name' => 'claude',
                'prompt_version' => 'v4',
                'message_classification' => ['primary_type' => 'invoiceReceived', 'confidence' => 0.9],
                'document' => [
                    'doc_type' => 'invoiceReceived',
                    'confidence' => 0.8,
                    'extracted_json' => [
                        'docNumber' => 'FV-2',
                        '_resolve' => ['contentTag' => ['tag' => 'vehicle.fuel', 'tagSource' => 'rule']],
                    ],
                ],
            ]),
            42,
        );

        $this->assertSame(201, $this->statusOf($response));
        $this->assertNotNull($insertValues);
        $this->assertSame('vehicle.fuel', $insertValues['content_tag']);
    }

    public function testResultWithoutDocumentStoresOverallConfidence(): void
    {
        $db = $this->dbForResult();

        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        $fluent->method('execute');

        $insertValues = null;
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('insert')->willReturnCallback(
            function (string $table, array $values) use (&$insertValues, $fluent) {
                if ($table === 'core_mail_message_analyses') {
                    $insertValues = $values;
                }
                return $fluent;
            },
        );
        $dibi->method('update')->willReturn($fluent);
        $dibi->method('getInsertId')->willReturn(124);
        $db->method('getDibiConnection')->willReturn($dibi);

        $ctrl = $this->controller($db);
        $response = $ctrl->result(
            $this->analyzerAuth(),
            $this->request('POST', '/x', ['X-Claim-Token' => 'ct_abc'], [
                'model_name' => 'claude',
                'prompt_version' => 'v4',
                'overall_confidence' => 0.42,
                'message_classification' => ['primary_type' => 'other', 'confidence' => 0.9],
            ]),
            42,
        );

        $this->assertSame(201, $this->statusOf($response));
        $this->assertNotNull($insertValues);
        $this->assertNull($insertValues['canonical_json']);
        $this->assertNull($insertValues['proposed_type']);
        $this->assertNull($insertValues['content_tag']);
        $this->assertSame(0.42, $insertValues['confidence']);
    }
}
