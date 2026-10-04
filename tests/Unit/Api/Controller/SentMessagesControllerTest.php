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
use Shipard\Module\Core\Mail\Sent\SentMessageStore;
use Shipard\Module\Core\Mail\Sent\SentMessageTransport;
use Shipard\Module\Core\Mail\Sent\SentMessageTransportInfo;

/** `POST /_sent-messages/{id}/resend` — Odeslat znovu (#90 D44). */
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
}
