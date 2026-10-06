<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\SentMessagesController;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Mail\Sent\SentMessageException;
use Shipard\Module\Core\Mail\Sent\SentMessageImportException;
use Shipard\Module\Core\Mail\Sent\SentMessageImportResult;
use Shipard\Module\Core\Mail\Sent\SentMessageImportService;
use Shipard\Module\Core\Mail\Sent\SentMessageStore;
use Shipard\Module\Core\Mail\Sent\SentMessageTransport;
use Shipard\Module\Core\Mail\Sent\SentMessageTransportInfo;

/**
 * `POST /_sent-messages/{id}/resend` — Odeslat znovu (#90 D44) a
 * `POST /_mail/sent/import` — import ze starého systému (#104 D1).
 */
class SentMessagesControllerTest extends TestCase
{
    private function statusOf(Response $response): int
    {
        return (int) (new \ReflectionClass($response))->getProperty('status')->getValue($response);
    }

    private function controller(SentMessageTransport $transport, array $message = []): SentMessagesController
    {
        $store = $this->createMock(SentMessageStore::class);
        $store->method('get')->willReturn($message + [
            'id' => 7, 'docState' => 40, 'transport_state' => 'sent', 'sent_at' => '2026-10-04 14:31:00', 'send_count' => 2,
        ]);

        return new SentMessagesController(
            $store,
            $transport,
            new SentMessageTransportInfo($this->createMock(DataSourceConnection::class)),
        );
    }

    private function user(): AuthContext
    {
        return new AuthContext(true, 3, 'session', 'shpd_st_x');
    }

    public function testResendReturnsFreshTransportState(): void
    {
        $transport = $this->createMock(SentMessageTransport::class);
        $transport->expects($this->once())->method('resend')->with(7, true, 3)->willReturn(31);

        $response = $this->controller($transport)->resend(7, $this->user(), []);
        $data     = $response->getPayload()['data'];

        $this->assertSame(200, $this->statusOf($response));
        $this->assertSame('sent', $data['transportState']);
        $this->assertSame(2, $data['transport']['sendCount']);
        $this->assertTrue($data['transport']['canResend']);
        $this->assertSame('04.10.2026 14:31', $data['transport']['sentAt']);
    }

    public function testMessageLeftInQueueReportsQueuedAndCannotBeResentYet(): void
    {
        $transport = $this->createMock(SentMessageTransport::class);

        // Transport selhal — zprávu převzala fronta.
        $data = $this->controller($transport, ['transport_state' => 'queued'])
            ->resend(7, $this->user(), [])->getPayload()['data'];

        $this->assertSame('queued', $data['transportState']);
        $this->assertFalse($data['transport']['canResend']);
    }

    public function testDomainErrorsMapToStatusCodes(): void
    {
        foreach ([
            [SentMessageException::ALREADY_QUEUED, 409],
            [SentMessageException::INVALID_STATE, 409],
            [SentMessageException::IMPORTED, 409],
            [SentMessageException::NOT_FOUND, 404],
        ] as [$code, $status]) {
            $transport = $this->createMock(SentMessageTransport::class);
            $transport->method('resend')->willThrowException(new SentMessageException($code, 'nope'));

            $response = $this->controller($transport)->resend(7, $this->user(), []);

            $this->assertSame($status, $this->statusOf($response), $code);
            $this->assertSame($code, $response->getPayload()['error']['code']);
        }
    }

    public function testTableGuardAppliesBeforeAnythingIsQueued(): void
    {
        $transport = $this->createMock(SentMessageTransport::class);
        $transport->expects($this->never())->method('resend');

        $adminOnly = TableDefinition::fromArray([
            'tableId'   => 455,
            'name'      => 'Sent messages',
            'adminOnly' => true,
            'columns'   => [['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true]],
        ]);

        $response = $this->controller($transport)->resend(7, $this->user(), ['core_mail_sent_messages' => $adminOnly]);

        $this->assertSame(403, $this->statusOf($response));
    }

    // ── import (#104 D1) ───────────────────────────────────────────────────

    private function importController(?SentMessageImportService $importer): SentMessagesController
    {
        return new SentMessagesController(
            $this->createMock(SentMessageStore::class),
            $this->createMock(SentMessageTransport::class),
            new SentMessageTransportInfo($this->createMock(DataSourceConnection::class)),
            $importer,
        );
    }

    private function apiKey(): AuthContext
    {
        return new AuthContext(true, 9, 'api_key', 'shpd_ak_x');
    }

    /** @return list<array{name: string, tmp_name: string}> */
    private function files(): array
    {
        return [['name' => 'faktura.pdf', 'tmp_name' => '/tmp/php1'], ['name' => 'faktura.isdoc', 'tmp_name' => '/tmp/php2']];
    }

    public function testImportRequiresApiKey(): void
    {
        $importer = $this->createMock(SentMessageImportService::class);
        $importer->expects($this->never())->method('import');

        foreach ([AuthContext::anonymous(), $this->user()] as $auth) {
            $response = $this->importController($importer)->import($auth, ['payload' => '{"import_ref":"x"}'], []);
            $this->assertSame(401, $this->statusOf($response));
            $this->assertSame('UNAUTHORIZED', $response->getPayload()['error']['code']);
        }
    }

    public function testImportRejectsMissingOrNonObjectPayload(): void
    {
        $importer = $this->createMock(SentMessageImportService::class);
        $importer->expects($this->never())->method('import');

        foreach ([[], ['payload' => ''], ['payload' => 'not json'], ['payload' => '[1,2]'], ['payload' => '"text"']] as $form) {
            $response = $this->importController($importer)->import($this->apiKey(), $form, []);
            $this->assertSame(400, $this->statusOf($response), json_encode($form));
            $this->assertSame('BAD_REQUEST', $response->getPayload()['error']['code']);
        }
    }

    public function testImportCreatesMessageWithAttachments(): void
    {
        $importer = $this->createMock(SentMessageImportService::class);
        $importer->expects($this->once())->method('import')
            ->with(['import_ref' => 'oldShipard:4711', 'email_to' => ['a@b.example']], $this->files(), 9)
            ->willReturn(new SentMessageImportResult(71, true, 2));

        $response = $this->importController($importer)->import(
            $this->apiKey(),
            ['payload' => '{"import_ref":"oldShipard:4711","email_to":["a@b.example"]}'],
            $this->files(),
        );

        $this->assertSame(201, $this->statusOf($response));
        $this->assertSame(['id' => 71, 'created' => true, 'attachments' => 2], $response->getPayload()['data']);
    }

    public function testImportOfKnownMessageReturnsExistingId(): void
    {
        $importer = $this->createMock(SentMessageImportService::class);
        $importer->method('import')->willReturn(new SentMessageImportResult(12, false));

        $response = $this->importController($importer)->import($this->apiKey(), ['payload' => '{"import_ref":"oldShipard:4711"}'], $this->files());

        $this->assertSame(200, $this->statusOf($response));
        $this->assertSame(['id' => 12, 'created' => false], $response->getPayload()['data']);
    }

    public function testImportContractViolationIs422WithFieldAndCode(): void
    {
        $importer = $this->createMock(SentMessageImportService::class);
        $importer->method('import')->willThrowException(
            new SentMessageImportException('email_from', 'invalid_email', "'x' is not a valid e-mail address"),
        );

        $response = $this->importController($importer)->import($this->apiKey(), ['payload' => '{"import_ref":"oldShipard:4711"}'], []);
        $error    = $response->getPayload()['error'];

        $this->assertSame(422, $this->statusOf($response));
        $this->assertSame('VALIDATION_ERROR', $error['code']);
        $this->assertSame([['field' => 'email_from', 'code' => 'invalid_email']], $error['details']);
    }
}
