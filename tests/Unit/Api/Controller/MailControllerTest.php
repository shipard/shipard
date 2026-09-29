<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\MailController;
use Shipard\Api\Request;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Module\Core\Mail\Preprocess\PreprocessRunner;

/**
 * Unit testy pro auth-gate a validační větve MailControlleru.
 * Happy path (multipart upload) pokrývají integrační testy.
 */
class MailControllerTest extends TestCase
{
    private function request(array $headers = []): Request
    {
        $server = ['HTTP_HOST' => 'test', 'REMOTE_ADDR' => '127.0.0.1'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        return Request::fromArray('POST', '/_mail/incoming', [], '', $server);
    }

    private function controller(DataSourceConnection $db): MailController
    {
        return new MailController($db, '/tmp/shpd_mail_test', [], new DocumentRegistry());
    }

    public function testAnonymousRequestReturns401(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $response = $ctrl->receiveIncoming(AuthContext::anonymous(), $this->request());

        $this->assertSame(401, $this->statusOf($response));
        $this->assertSame('UNAUTHORIZED', $response->getPayload()['error']['code']);
    }

    public function testSessionTokenIsRejected(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $auth = new AuthContext(true, 1, 'session', 'shpd_st_xxx');
        $response = $ctrl->receiveIncoming($auth, $this->request());

        $this->assertSame(401, $this->statusOf($response));
    }

    public function testWrongUserReturns403(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => 'admin']);

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 5, 'api_key', 'shpd_ak_xxx');

        $response = $ctrl->receiveIncoming($auth, $this->request());

        $this->assertSame(403, $this->statusOf($response));
        $this->assertSame('FORBIDDEN', $response->getPayload()['error']['code']);
    }

    public function testIdempotencyReplayReturnsCachedResponse(): void
    {
        $cachedPayload = json_encode([
            'success' => true,
            'data' => [
                'ndx' => 123,
                'message_id' => 'MSG-20260418-0001',
                'idempotent_replay' => false,
            ],
        ]);

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_mail_router'],            // auth user lookup
            ['message' => 123, 'response_body' => $cachedPayload],  // idempotency lookup
        );

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_xxx');
        $request = $this->request(['X-Idempotency-Key' => 'abc123']);

        $response = $ctrl->receiveIncoming($auth, $request);

        $this->assertSame(201, $this->statusOf($response));
        $payload = $response->getPayload();
        $this->assertTrue($payload['success']);
        $this->assertTrue($payload['data']['idempotent_replay']);
        $this->assertSame(123, $payload['data']['ndx']);
        $this->assertSame('MSG-20260418-0001', $payload['data']['message_id']);
    }

    public function testMissingReceivedAtReturns422(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => '_mail_router']);

        // Empty $_POST → validation fails on received_at
        $_POST = [];
        $_FILES = [];

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_xxx');

        $response = $ctrl->receiveIncoming($auth, $this->request());

        $this->assertSame(422, $this->statusOf($response));
        $this->assertSame('VALIDATION_ERROR', $response->getPayload()['error']['code']);
        $this->assertStringContainsString('received_at', $response->getPayload()['error']['message']);
    }

    public function testInvalidSenderEmailReturns422(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['login' => '_mail_router']);

        $_POST = [
            'received_at' => '2026-04-18T14:32:00+02:00',
            'sender_email' => 'not-an-email',
        ];
        $_FILES = [];

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_xxx');

        $response = $ctrl->receiveIncoming($auth, $this->request());

        $this->assertSame(422, $this->statusOf($response));
        $payload = $response->getPayload();
        $this->assertSame('sender_email', $payload['error']['details'][0]['field']);
    }

    public function testUnknownMailboxReturns422(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_mail_router'],  // auth
            null,                         // mailbox lookup miss
        );

        $_POST = [
            'mailbox' => 'unknown',
            'received_at' => '2026-04-18T14:32:00+02:00',
            'sender_email' => 'a@b.cz',
            'subject' => 'Hi',
        ];
        $_FILES = [];

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_xxx');

        $response = $ctrl->receiveIncoming($auth, $this->request());

        $this->assertSame(422, $this->statusOf($response));
        $this->assertStringContainsString("'unknown'", $response->getPayload()['error']['message']);
    }

    public function testEmptyMailboxWithNoDefaultReturns422(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_mail_router'],  // auth
            null,                         // default mailbox lookup miss
        );

        $_POST = [
            'mailbox' => '',
            'received_at' => '2026-04-18T14:32:00+02:00',
            'sender_email' => 'a@b.cz',
            'subject' => 'Hi',
        ];
        $_FILES = [];

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_xxx');

        $response = $ctrl->receiveIncoming($auth, $this->request());

        $this->assertSame(422, $this->statusOf($response));
        $this->assertStringContainsString('no default mailbox', $response->getPayload()['error']['message']);
    }

    public function testMissingRawSourceReturns422(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['login' => '_mail_router'],     // auth
            ['id' => 5],                     // default mailbox
        );

        $_POST = [
            'received_at' => '2026-04-18T14:32:00+02:00',
            'sender_email' => 'a@b.cz',
            'subject' => 'Hi',
        ];
        $_FILES = [];

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_xxx');

        $response = $ctrl->receiveIncoming($auth, $this->request());

        $this->assertSame(422, $this->statusOf($response));
        $this->assertSame('raw_source', $response->getPayload()['error']['details'][0]['field']);
    }

    // -------------------------------------------------------------------------
    // POST /_mail/import — importMessage()
    // -------------------------------------------------------------------------

    private function jsonRequest(array $body): Request
    {
        $server = ['HTTP_HOST' => 'test', 'REMOTE_ADDR' => '127.0.0.1'];
        return Request::fromArray('POST', '/_mail/import', [], (string) json_encode($body), $server);
    }

    public function testImportAnonymousReturns401(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $response = $ctrl->importMessage(AuthContext::anonymous(), $this->jsonRequest(['subject' => 'x']));

        $this->assertSame(401, $this->statusOf($response));
        $this->assertSame('UNAUTHORIZED', $response->getPayload()['error']['code']);
    }

    public function testImportSessionTokenReturns401(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $auth = new AuthContext(true, 1, 'session', 'shpd_st_xxx');
        $response = $ctrl->importMessage($auth, $this->jsonRequest(['subject' => 'x']));

        $this->assertSame(401, $this->statusOf($response));
    }

    public function testImportAcceptsAnyApiKeyUser(): void
    {
        // Na rozdíl od /_mail/incoming není import omezen na _mail_router:
        // libovolný api_key projde gate a spadne až na mailbox resolve.
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null); // default mailbox miss → 422, ne 403

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 99, 'api_key', 'shpd_ak_importer');

        $response = $ctrl->importMessage($auth, $this->jsonRequest(['sender_email' => 'a@b.cz']));

        $this->assertSame(422, $this->statusOf($response));
        $this->assertSame('VALIDATION_ERROR', $response->getPayload()['error']['code']);
    }

    public function testImportEmptyBodyReturns400(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_xxx');
        $request = Request::fromArray('POST', '/_mail/import', [], '', ['HTTP_HOST' => 'test']);

        $response = $ctrl->importMessage($auth, $request);

        $this->assertSame(400, $this->statusOf($response));
        $this->assertSame('BAD_REQUEST', $response->getPayload()['error']['code']);
    }

    public function testImportUnknownMailboxReturns422(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null); // mailbox lookup miss

        $ctrl = $this->controller($db);
        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_xxx');

        $response = $ctrl->importMessage($auth, $this->jsonRequest([
            'mailbox' => 'nonexistent',
            'sender_email' => 'a@b.cz',
            'subject' => 'Hi',
        ]));

        $this->assertSame(422, $this->statusOf($response));
        $this->assertStringContainsString("'nonexistent'", $response->getPayload()['error']['message']);
    }

    // Pozn.: validace povinných polí / formátu sender_email žije v
    // IncomingMessageDocument, ne v controlleru (na rozdíl od /_mail/incoming).
    // Unit úroveň s prázdným DocumentRegistry ji nezachytí — pokrývá ji
    // MailImportEndpointTest::testImportInvalidSenderEmailReturns422.

    // ── partner a titulek z cílového dokladu (mail-import-partner-title) ────
    //
    // Bez dsConfig staví controller composer bez configu, takže titulek
    // vyjde bez labelu typu — jazyk labelu pokrývá MessageTitleComposerTest,
    // reálnou cestu s dokladem MailImportEndpointTest.

    /**
     * Connection pro happy path importu: mailbox → docs_core_heads → zpráva.
     * Vložený řádek se zaznamená do `$inserted`.
     *
     * @param array<string, mixed>|null $doc řádek cílového dokladu (null = není)
     * @param array<string, mixed>|null $inserted out param
     */
    private function importDb(?array $doc, ?array &$inserted): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static function (mixed ...$args) use ($doc): ?array {
                $sql = (string) $args[0];
                if (str_contains($sql, 'core_mail_mailboxes')) {
                    return ['id' => 7];
                }
                if (in_array('docs_core_heads', array_filter($args, 'is_string'), true)) {
                    return $doc;
                }
                return ['message_id' => 'MSG-20260910-0001'];
            },
        );

        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('insert')->willReturnCallback(
            function (string $table, array $data) use (&$inserted): \Dibi\Fluent {
                $inserted = $data;
                $fluent = $this->createMock(\Dibi\Fluent::class);
                $fluent->method('__call')->willReturn($fluent);
                return $fluent;
            },
        );
        $dibi->method('getInsertId')->willReturn(101);
        $db->method('getDibiConnection')->willReturn($dibi);

        return $db;
    }

    /** @return array<string, mixed> */
    private function targetDocRow(): array
    {
        return [
            'doc_type' => 'invni',
            'doc_number' => '2019-0123',
            'total_amount' => '13105.00',
            'doc_currency' => 'CZK',
            'partner' => 55,
            'partner_full_name' => 'Dodavatel s.r.o.',
        ];
    }

    public function testImportLinkedMessageTakesPartnerAndTitleFromDocument(): void
    {
        $inserted = null;
        $ctrl = $this->controller($this->importDb($this->targetDocRow(), $inserted));
        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_importer');

        $response = $ctrl->importMessage($auth, $this->jsonRequest([
            'subject' => 'FW: doklad',
            'sender_email' => 'scanner@example.com',
            'received_at' => '2026-04-18T14:32:00+02:00',
            'target_table_id' => 'docs_core_heads',
            'target_row' => 4711,
        ]));

        $this->assertSame(201, $this->statusOf($response));
        $this->assertSame(55, $inserted['partner_person']);
        $this->assertSame('Dodavatel s.r.o.', $inserted['partner_name']);
        $this->assertSame('2019-0123 — Dodavatel s.r.o., 13 105 CZK', $inserted['ai_title']);
    }

    public function testImportDocumentFactsBeatPayloadPartner(): void
    {
        // D8: u navázané zprávy je autorita doklad, ne payload.
        $inserted = null;
        $ctrl = $this->controller($this->importDb($this->targetDocRow(), $inserted));
        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_importer');

        $ctrl->importMessage($auth, $this->jsonRequest([
            'subject' => 'FW: doklad',
            'sender_email' => 'scanner@example.com',
            'received_at' => '2026-04-18T14:32:00+02:00',
            'target_table_id' => 'docs_core_heads',
            'target_row' => 4711,
            'partner_person' => 99,
            'partner_name' => 'Někdo jiný s.r.o.',
        ]));

        $this->assertSame(55, $inserted['partner_person']);
        $this->assertSame('Dodavatel s.r.o.', $inserted['partner_name']);
    }

    public function testImportUnlinkedMessageKeepsPayloadPartnerWithoutTitle(): void
    {
        // D5 + D6: partnera dodá runner, titulek nevzniká (není z čeho).
        $inserted = null;
        $ctrl = $this->controller($this->importDb(null, $inserted));
        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_importer');

        $ctrl->importMessage($auth, $this->jsonRequest([
            'subject' => 'Dotaz k objednávce',
            'sender_email' => 'obchod@example.com',
            'received_at' => '2026-04-18T14:32:00+02:00',
            'partner_person' => 99,
            'partner_name' => '  Odběratel   a.s. ',
        ]));

        $this->assertSame(99, $inserted['partner_person']);
        $this->assertSame('Odběratel a.s.', $inserted['partner_name'], 'normalizace bílých znaků');
        $this->assertNull($inserted['ai_title'] ?? null);
    }

    public function testImportWithoutPartnerFieldsInsertsNulls(): void
    {
        // Regrese mail-phase4: payload bez nových polí se chová jako dřív.
        $inserted = null;
        $ctrl = $this->controller($this->importDb(null, $inserted));
        $auth = new AuthContext(true, 2, 'api_key', 'shpd_ak_importer');

        $ctrl->importMessage($auth, $this->jsonRequest([
            'subject' => 'Newsletter',
            'sender_email' => 'news@example.com',
            'received_at' => '2026-04-18T14:32:00+02:00',
            'partner_person' => 0,
        ]));

        $this->assertNull($inserted['partner_person']);
        $this->assertNull($inserted['partner_name']);
    }

    // ── ISDOC odložení do runneru (tasks/mail-isdoc-content-tags.md #81) ───
    //
    // Plný multipart flow pokrývají integrační testy; tady se testuje lazy
    // gate a odložení: factory se volá právě tehdy, když je mezi přílohami
    // kandidát; platný ISDOC → podmíněný UPDATE (stav 10, log s triggerem)
    // + spawn; detect false / prohraný závod s analyzerem → nic.
    // Orchestrace samotného importu: IsdocImportServiceTest.

    /** @var list<array<int, mixed>> Zachycené argumenty $db->execute(). */
    private array $executes = [];

    private function invokeDeferIsdocImport(MailController $ctrl, array $attachments): void
    {
        $ref = new \ReflectionClass($ctrl);
        $ref->getMethod('deferIsdocImport')->invoke($ctrl, 42, $attachments);
    }

    private function deferDb(int $affectedRows): DataSourceConnection
    {
        $this->executes = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('execute')->willReturnCallback(function (...$args): void {
            $this->executes[] = $args;
        });
        $db->method('getAffectedRows')->willReturn($affectedRows);
        return $db;
    }

    private function detectingService(bool $result): \Shipard\Module\Core\Mail\IsdocImportService
    {
        $service = $this->createMock(\Shipard\Module\Core\Mail\IsdocImportService::class);
        $service->expects($this->once())->method('detect')->with(42)->willReturn($result);
        $service->expects($this->never())->method('tryImport'); // import běží jen v runneru
        return $service;
    }

    /** @param list<int> $spawned */
    private function deferController(DataSourceConnection $db, \Closure $factory, array &$spawned): MailController
    {
        return new MailController(
            $db, '/tmp/shpd_mail_test', [], new DocumentRegistry(), null, null,
            $factory,
            static function (int $messageId) use (&$spawned): void {
                $spawned[] = $messageId;
            },
        );
    }

    public function testValidIsdocIsDeferredToRunnerAndSpawned(): void
    {
        $factoryCalls = 0;
        $spawned = [];
        $service = $this->detectingService(true);
        $ctrl = $this->deferController($this->deferDb(1), function () use (&$factoryCalls, $service) {
            $factoryCalls++;
            return $service;
        }, $spawned);

        $this->invokeDeferIsdocImport($ctrl, [
            ['id' => 1, 'name' => 'faktura.pdf', 'mime_type' => 'application/pdf'],
            ['id' => 2, 'name' => 'faktura.isdoc', 'mime_type' => 'application/xml'],
        ]);

        $this->assertSame(1, $factoryCalls);
        $this->assertCount(1, $this->executes);
        $update = $this->executes[0];
        $this->assertStringContainsString('UPDATE %n SET preprocess_state = %i', (string) $update[0]);
        $this->assertStringContainsString('AND preprocess_state = %i AND analysis_state IN %in', (string) $update[0]);
        $this->assertSame(PreprocessRunner::STATE_PENDING, $update[2]);
        $log = json_decode((string) $update[3], true);
        $this->assertSame(PreprocessRunner::TRIGGER_ISDOC, $log['trigger']);
        $this->assertSame([], $log['plan']);
        $this->assertSame(42, $update[5]);
        $this->assertSame(PreprocessRunner::STATE_NONE, $update[6]); // jen dosud neodložená zpráva
        $this->assertSame([0, 10], $update[7]);                       // DS bez AI (0) i ve frontě (10)
        $this->assertSame([42], $spawned);
    }

    public function testDetectFalseLeavesMessageInAiQueue(): void
    {
        $spawned = [];
        $service = $this->detectingService(false);
        $ctrl = $this->deferController($this->deferDb(1), static fn() => $service, $spawned);

        $this->invokeDeferIsdocImport($ctrl, [
            ['id' => 2, 'name' => 'faktura.isdoc', 'mime_type' => 'application/xml'],
        ]);

        $this->assertSame([], $this->executes);
        $this->assertSame([], $spawned);
    }

    public function testLostRaceWithAnalyzerDoesNotSpawn(): void
    {
        // Analyzer si zprávu claimnul mezi commitem a odložením
        // (analysis_state 20) → podmíněný UPDATE 0 řádků → žádný spawn;
        // zpráva se nesmí zaseknout za gate AI fronty.
        $spawned = [];
        $service = $this->detectingService(true);
        $ctrl = $this->deferController($this->deferDb(0), static fn() => $service, $spawned);

        $this->invokeDeferIsdocImport($ctrl, [
            ['id' => 2, 'name' => 'faktura.isdoc', 'mime_type' => 'application/xml'],
        ]);

        $this->assertCount(1, $this->executes);
        $this->assertSame([], $spawned);
    }

    public function testIsdocFactoryNotInvokedWithoutCandidate(): void
    {
        // PDF je kandidát vždy (nosič embedded ISDOC, PDF/A-3) — bez kandidáta
        // znamená jen přílohy mimo ISDOC/XML/PDF (obrázky apod.).
        $factoryCalls = 0;
        $spawned = [];
        $ctrl = $this->deferController($this->deferDb(1), function () use (&$factoryCalls) {
            $factoryCalls++;
            return $this->createMock(\Shipard\Module\Core\Mail\IsdocImportService::class);
        }, $spawned);

        $this->invokeDeferIsdocImport($ctrl, [
            ['id' => 1, 'name' => 'scan.jpg', 'mime_type' => 'image/jpeg'],
        ]);

        $this->assertSame(0, $factoryCalls);
        $this->assertSame([], $this->executes);
        $this->assertSame([], $spawned);
    }

    public function testIsdocDeferralSwallowsFactoryFailure(): void
    {
        // Odložení nikdy nesmí shodit příjem pošty — i výbuch wiringu se polkne.
        $spawned = [];
        $ctrl = $this->deferController(
            $this->deferDb(1),
            static fn() => throw new \RuntimeException('wiring failed'),
            $spawned,
        );

        $this->invokeDeferIsdocImport($ctrl, [
            ['id' => 2, 'name' => 'faktura.isdoc', 'mime_type' => 'application/xml'],
        ]);

        $this->assertSame([], $spawned);
        $this->addToAssertionCount(1); // žádná výjimka nepropadla
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_FILES = [];
    }

    private function statusOf(\Shipard\Api\Response $response): int
    {
        $ref = new \ReflectionClass($response);
        $prop = $ref->getProperty('status');
        return (int) $prop->getValue($response);
    }
}
