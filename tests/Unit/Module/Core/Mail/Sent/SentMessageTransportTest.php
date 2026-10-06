<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Sent;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Mail\MailOutboxService;
use Shipard\Core\Mail\OutboundMessage;
use Shipard\Module\Core\Mail\Sent\SentMessageException;
use Shipard\Module\Core\Mail\Sent\SentMessageStore;
use Shipard\Module\Core\Mail\Sent\SentMessageTransport;

class SentMessageTransportTest extends TestCase
{
    private SentMessageStore&MockObject $store;
    private MailOutboxService&MockObject $outbox;
    /** @var list<OutboundMessage> */
    private array $enqueued = [];

    protected function setUp(): void
    {
        $this->store  = $this->createMock(SentMessageStore::class);
        $this->outbox = $this->createMock(MailOutboxService::class);
        $this->outbox->method('enqueue')->willReturnCallback(function (OutboundMessage $message): int {
            $this->enqueued[] = $message;
            return 30 + count($this->enqueued);
        });
        $this->store->method('attachmentIds')->willReturn([101, 102]);
    }

    private function transport(): SentMessageTransport
    {
        return new SentMessageTransport(
            $this->store,
            $this->outbox,
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-04 14:30:00'),
        );
    }

    /** @return array<string, mixed> */
    private function message(array $overrides = []): array
    {
        return $overrides + [
            'id'               => 7,
            'channel'          => 'email',
            'subject'          => 'Faktura – daňový doklad 2260011 — Naše firma s.r.o.',
            'body_text'        => "Dobrý den,\nv příloze posíláme fakturu.",
            'email_from'       => 'fakturace@firma.example',
            'email_from_name'  => 'Naše firma s.r.o.',
            'email_to'         => 'ucetni@odberatel.example, jana@odberatel.example',
            'email_cc'         => 'obchod@firma.example',
            'recipient_person' => 12,
            'created_by'       => 3,
            'docState'         => 40,
            'transport_state'  => 'sent',
        ];
    }

    public function testImportedMessageCannotBeResent(): void
    {
        // Zpráva ze starého systému (#104 D5): odmítnutá hned po kontrole
        // existence — před stavem i frontou, nic se nezařadí.
        $this->store->method('get')->willReturn($this->message(['send_trigger' => 'import', 'docState' => 70]));
        $this->store->expects($this->never())->method('markQueued');

        try {
            $this->transport()->resend(7);
            $this->fail('Expected SentMessageException');
        } catch (SentMessageException $e) {
            $this->assertSame(SentMessageException::IMPORTED, $e->errorCode);
        }
        $this->assertSame([], $this->enqueued);
    }

    public function testDispatchBuildsOutboundMessageFromTheStoredMessage(): void
    {
        $this->store->method('get')->willReturn($this->message());
        $this->store->expects($this->once())->method('markQueued')->with(7, 31);
        $this->outbox->expects($this->once())->method('attemptSend')->with(31)->willReturn(true);

        $outboxId = $this->transport()->dispatch(7, true);

        $this->assertSame(31, $outboxId);
        $message = $this->enqueued[0];
        $this->assertSame(['ucetni@odberatel.example', 'jana@odberatel.example'], $message->to);
        $this->assertSame(['obchod@firma.example'], $message->cc);
        $this->assertSame('fakturace@firma.example', $message->from);
        $this->assertSame('Naše firma s.r.o.', $message->fromName);
        $this->assertSame("Dobrý den,\nv příloze posíláme fakturu.", $message->bodyText);
        // Přílohy = přílohy zprávy, ne záznamu.
        $this->assertSame([101, 102], $message->attachments);
        $this->assertSame(12, $message->recipientPersonId);
        $this->assertSame('core.mail', $message->sourceModule);
        $this->assertSame('sentMessage:7', $message->sourceRef);
        $this->assertSame(MailOutboxService::PRIORITY_HIGH, $message->priority);
    }

    public function testDispatchWithoutSendNowOnlyQueues(): void
    {
        $this->store->method('get')->willReturn($this->message());
        $this->outbox->expects($this->never())->method('attemptSend');

        $this->transport()->dispatch(7, false);

        $this->assertSame(0, $this->enqueued[0]->priority);
    }

    public function testFailedImmediateAttemptLeavesMessageInQueue(): void
    {
        $this->store->method('get')->willReturn($this->message());
        $this->outbox->method('attemptSend')->willThrowException(new \RuntimeException('infra down'));

        // Selhání pokusu nepropaguje — zprávu převezme fronta.
        $this->assertSame(31, $this->transport()->dispatch(7, true));
    }

    public function testResendCreatesNewOutboxRowWithSameContentAndNoNewMessage(): void
    {
        $this->store->method('get')->willReturn($this->message(['transport_state' => 'sent']));
        $this->store->expects($this->never())->method('create');
        $this->outbox->method('attemptSend')->willReturn(true);

        $transport = $this->transport();
        $first     = $transport->resend(7);
        $second    = $transport->resend(7);

        $this->assertSame([31, 32], [$first, $second]);
        $this->assertEquals($this->enqueued[0], $this->enqueued[1], 'stejní příjemci, stejné přílohy');
    }

    public function testResendOfFailedMessageIsAllowed(): void
    {
        $this->store->method('get')->willReturn($this->message(['transport_state' => 'failed']));
        $this->outbox->method('attemptSend')->willReturn(false);

        $this->assertSame(31, $this->transport()->resend(7));
    }

    public function testResendOfQueuedMessageIsRejected(): void
    {
        $this->store->method('get')->willReturn($this->message(['transport_state' => 'queued']));

        try {
            $this->transport()->resend(7);
            $this->fail('Zpráva ve frontě nejde odeslat znovu');
        } catch (SentMessageException $e) {
            $this->assertSame(SentMessageException::ALREADY_QUEUED, $e->errorCode);
        }
        $this->assertSame([], $this->enqueued);
    }

    public function testResendOfArchivedOrDeletedMessageIsRejected(): void
    {
        foreach ([70, 90] as $docState) {
            $store = $this->createMock(SentMessageStore::class);
            $store->method('get')->willReturn($this->message(['docState' => $docState]));
            $transport = new SentMessageTransport($store, $this->outbox);

            try {
                $transport->resend(7);
                $this->fail("Zpráva ve stavu {$docState} nejde odeslat znovu");
            } catch (SentMessageException $e) {
                $this->assertSame(SentMessageException::INVALID_STATE, $e->errorCode);
            }
        }
        $this->assertSame([], $this->enqueued);
    }

    public function testUnknownMessageIsNotFound(): void
    {
        $this->store->method('get')->willReturn(null);

        try {
            $this->transport()->resend(99);
            $this->fail('Neznámá zpráva');
        } catch (SentMessageException $e) {
            $this->assertSame(SentMessageException::NOT_FOUND, $e->errorCode);
        }
    }

    public function testChannelOtherThanEmailHasNoTransport(): void
    {
        $this->store->method('get')->willReturn($this->message(['channel' => 'databox']));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches("/channel 'databox' has no transport/");

        $this->transport()->dispatch(7, false);
    }
}
