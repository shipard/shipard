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
 * Unit testy pro AnalysisController — akce nad analyzovanou zprávou
 * (reanalyze, apply, reject, unapply): auth gate, validace, branch logiku.
 * Plnou SQL cestu pokrývají integration testy proti reálné DB; zápis
 * výsledku a klasifikaci kryje AnalysisResultWriterTest (pull protokol
 * zrušen #85 D20).
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

    private function userAuth(): AuthContext
    {
        return new AuthContext(true, 100, 'session', 'shpd_st_xxx');
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
}
