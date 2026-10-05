<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Sent;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Mail\MailSafetyResult;
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
        // Bez zásahu pojistky se stopa dřívějšího odeslání maže.
        $store->expects($this->once())->method('markSafety')->with(7, 31, null, null);
        $store->expects($this->never())->method('markFailed');

        (new SentMessageOutboxListener($store))
            ->outboxStateChanged('sentMessage:7', 31, OutboxSourceListener::STATE_SENT, $this->at());
    }

    public function testSentOutboxRowStoresSafetyTrace(): void
    {
        $email = new \Symfony\Component\Mime\Email();

        foreach ([
            [new MailSafetyResult(MailSafetyResult::ACTION_REDIRECTED, $email, 'testy@firma.example'), 'redirected', 'testy@firma.example'],
            [new MailSafetyResult(MailSafetyResult::ACTION_DROPPED, $email), 'dropped', null],
            [new MailSafetyResult(MailSafetyResult::ACTION_NONE, $email), null, null],
        ] as [$safety, $action, $target]) {
            $store = $this->createMock(SentMessageStore::class);
            // Zachycená zpráva se propíše jako odeslaná, jen se štítkem.
            $store->expects($this->once())->method('markSent')->with(7, 31, $this->at());
            $store->expects($this->once())->method('markSafety')->with(7, 31, $action, $target);

            (new SentMessageOutboxListener($store))->outboxStateChanged(
                'sentMessage:7',
                31,
                OutboxSourceListener::STATE_SENT,
                $this->at(),
                null,
                $safety,
            );
        }
    }

    public function testTerminalFailureStoresLastError(): void
    {
        $store = $this->createMock(SentMessageStore::class);
        $store->expects($this->once())->method('markFailed')->with(7, 31, 'Mailbox unavailable', $this->at());
        $store->expects($this->never())->method('markSafety');

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
