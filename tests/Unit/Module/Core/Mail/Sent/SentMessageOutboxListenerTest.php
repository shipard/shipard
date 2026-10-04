<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Sent;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Mail\OutboxSourceListener;
use Shipard\Module\Core\Mail\Sent\SentMessageOutboxListener;
use Shipard\Module\Core\Mail\Sent\SentMessageStore;

class SentMessageOutboxListenerTest extends TestCase
{
    private function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-04 14:31:00');
    }

    public function testSentOutboxRowMarksMessageSent(): void
    {
        $store = $this->createMock(SentMessageStore::class);
        $store->expects($this->once())->method('markSent')->with(7, 31, $this->at());
        $store->expects($this->never())->method('markFailed');

        (new SentMessageOutboxListener($store))
            ->outboxStateChanged('sentMessage:7', 31, OutboxSourceListener::STATE_SENT, $this->at());
    }

    public function testTerminalFailureStoresLastError(): void
    {
        $store = $this->createMock(SentMessageStore::class);
        $store->expects($this->once())->method('markFailed')->with(7, 31, 'Mailbox unavailable', $this->at());

        (new SentMessageOutboxListener($store))->outboxStateChanged(
            'sentMessage:7',
            31,
            OutboxSourceListener::STATE_FAILED,
            $this->at(),
            'Mailbox unavailable',
        );
    }

    public function testRequeuedOutboxRowPutsMessageBackToQueue(): void
    {
        $store = $this->createMock(SentMessageStore::class);
        $store->expects($this->once())->method('markRequeued')->with(7, 31, $this->at());

        (new SentMessageOutboxListener($store))
            ->outboxStateChanged('sentMessage:7', 31, OutboxSourceListener::STATE_REQUEUED, $this->at());
    }

    public function testForeignSourceRefIsIgnored(): void
    {
        $store = $this->createMock(SentMessageStore::class);
        $store->expects($this->never())->method('markSent');

        (new SentMessageOutboxListener($store))
            ->outboxStateChanged('sentMessage:x', 31, OutboxSourceListener::STATE_SENT, $this->at());
    }
}
