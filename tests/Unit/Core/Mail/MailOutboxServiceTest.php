<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Mail;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Mail\Exception\MailValidationException;
use Shipard\Core\Mail\MailComposer;
use Shipard\Core\Mail\MailOutboxService;
use Shipard\Core\Mail\OutboundMessage;
use Shipard\Core\Mail\OutboxSourceListener;
use Shipard\Core\Mail\ResolvedTransport;
use Shipard\Core\Mail\TransportResolver;
use Shipard\Core\Settings\SettingsStore;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

class MailOutboxServiceTest extends TestCase
{
    private const NOW = '2026-07-13 10:00:00';

    private DataSourceConnection&MockObject $db;
    private TransportResolver&MockObject $resolver;
    private MailComposer&MockObject $composer;
    private SettingsStore&MockObject $settings;
    private MailOutboxService $service;

    protected function setUp(): void
    {
        $this->db       = $this->createMock(DataSourceConnection::class);
        $this->resolver = $this->createMock(TransportResolver::class);
        $this->composer = $this->createMock(MailComposer::class);
        $this->settings = $this->createMock(SettingsStore::class);
        $this->service  = new MailOutboxService($this->db, $this->resolver, $this->composer, $this->settings);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private function message(array $overrides = []): OutboundMessage
    {
        return new OutboundMessage(
            to: $overrides['to'] ?? 'user@example.com',
            subject: $overrides['subject'] ?? 'Pozvánka',
            sourceModule: $overrides['sourceModule'] ?? 'core.auth',
            from: array_key_exists('from', $overrides) ? $overrides['from'] : 'noreply@firma.cz',
            bodyText: array_key_exists('bodyText', $overrides) ? $overrides['bodyText'] : 'Ahoj.',
            priority: $overrides['priority'] ?? 0,
            cc: $overrides['cc'] ?? [],
            fromName: $overrides['fromName'] ?? null,
        );
    }

    /** Data řádku, který `enqueue` vložil do outboxu. */
    private function captureInsert(?array &$captured, int $id = 11): void
    {
        $this->db->method('insertRow')->willReturnCallback(
            function (string $table, array $data) use (&$captured, $id) {
                $captured = $data;
                return $id;
            },
        );
    }

    // ── enqueue ─────────────────────────────────────────────────────

    public function testEnqueueInsertsPendingRow(): void
    {
        $captured = null;
        $this->db->method('insertRow')->willReturnCallback(
            function (string $table, array $data) use (&$captured) {
                $captured = [$table, $data];
                return 11;
            },
        );

        $id = $this->service->enqueue($this->message(), $this->now());

        $this->assertSame(11, $id);
        [$table, $data] = $captured;
        $this->assertSame('core_mail_outbox', $table);
        $this->assertSame('pending', $data['state']);
        $this->assertSame('noreply@firma.cz', $data['email_from']);
        $this->assertSame(self::NOW, $data['next_attempt']);
        $this->assertSame(self::NOW, $data['created']);
        $this->assertSame(0, $data['attempt_count']);
        $this->assertNull($data['attachments']);
    }

    public function testEnqueueUsesDefaultFromSetting(): void
    {
        $this->settings->method('get')->with('mail.defaultFrom')->willReturn('podatelna@firma.cz');

        $captured = null;
        $this->db->method('insertRow')->willReturnCallback(
            function (string $table, array $data) use (&$captured) {
                $captured = $data;
                return 12;
            },
        );

        $this->service->enqueue($this->message(['from' => null]), $this->now());

        $this->assertSame('podatelna@firma.cz', $captured['email_from']);
    }

    public function testEnqueueWithoutFromAndDefaultThrows(): void
    {
        $this->settings->method('get')->willReturn(null);

        $this->expectException(MailValidationException::class);
        $this->expectExceptionMessageMatches('/mail\.defaultFrom/');

        $this->service->enqueue($this->message(['from' => null]), $this->now());
    }

    public function testEnqueueInvalidToThrows(): void
    {
        $this->expectException(MailValidationException::class);
        $this->expectExceptionMessageMatches('/Invalid to address/');

        $this->service->enqueue($this->message(['to' => 'not-an-email']), $this->now());
    }

    public function testEnqueueStoresRecipientListCopiesAndFromName(): void
    {
        $captured = null;
        $this->captureInsert($captured);

        $this->service->enqueue($this->message([
            'to'       => ['ucetni@odberatel.cz', 'Ucetni@Odberatel.cz', ' fakturace@odberatel.cz '],
            'cc'       => ['obchod@firma.cz'],
            'fromName' => "Firma s.r.o.\r\nBcc: nekdo@jinde.cz",
        ]), $this->now());

        // Duplicitní adresa (bez ohledu na velikost písmen) jen jednou.
        $this->assertSame('ucetni@odberatel.cz, fakturace@odberatel.cz', $captured['email_to']);
        $this->assertSame('obchod@firma.cz', $captured['email_cc']);
        // Konec řádku ve jméně by rozdělil hlavičku From.
        $this->assertSame('Firma s.r.o. Bcc: nekdo@jinde.cz', $captured['email_from_name']);
    }

    public function testEnqueueSingleRecipientKeepsCopiesAndNameEmpty(): void
    {
        $captured = null;
        $this->captureInsert($captured);

        $this->service->enqueue($this->message(), $this->now());

        $this->assertSame('user@example.com', $captured['email_to']);
        // Bez kopií a jména řádek nové sloupce nenese — projde i na zdroji
        // dat před `ds-upgrade`.
        $this->assertArrayNotHasKey('email_cc', $captured);
        $this->assertArrayNotHasKey('email_from_name', $captured);
    }

    public function testEnqueueInvalidAddressInListThrows(): void
    {
        $this->expectException(MailValidationException::class);
        $this->expectExceptionMessageMatches("/Invalid to address: 'spatne'/");

        $this->service->enqueue($this->message(['to' => ['ok@example.com', 'spatne']]), $this->now());
    }

    public function testEnqueueWithoutRecipientThrows(): void
    {
        $this->expectException(MailValidationException::class);
        $this->expectExceptionMessageMatches('/no to address/');

        $this->service->enqueue($this->message(['to' => []]), $this->now());
    }

    public function testEnqueueInvalidCcThrows(): void
    {
        $this->expectException(MailValidationException::class);
        $this->expectExceptionMessageMatches('/Invalid cc address/');

        $this->service->enqueue($this->message(['cc' => ['spatne']]), $this->now());
    }

    public function testEnqueueWithoutBodyThrows(): void
    {
        $this->expectException(MailValidationException::class);
        $this->expectExceptionMessageMatches('/no body/');

        $this->service->enqueue($this->message(['bodyText' => null]), $this->now());
    }

    // ── attemptSend ─────────────────────────────────────────────────

    /** @param array $row outbox řádek vrácený po claimu */
    private function primeClaim(array $row, int $affectedRows = 1): void
    {
        $this->db->method('getAffectedRows')->willReturn($affectedRows);
        $this->db->method('fetchRow')->willReturn($row);
    }

    private function outboxRow(array $overrides = []): array
    {
        return array_merge([
            'id'            => 5,
            'email_from'    => 'noreply@firma.cz',
            'email_to'      => 'user@example.com',
            'subject'       => 'Pozvánka',
            'body_text'     => 'Ahoj.',
            'body_html'     => null,
            'attachments'   => null,
            'attempt_count' => 0,
            'state'         => 'sending',
        ], $overrides);
    }

    public function testAttemptSendSuccess(): void
    {
        $this->primeClaim($this->outboxRow());

        $transport = $this->createMock(TransportInterface::class);
        $transport->expects($this->once())->method('send')->willReturn(null);
        $this->resolver->method('resolve')->willReturn(new ResolvedTransport($transport, 'sender:3'));
        $this->composer->method('compose')->willReturn(new Email());

        $logRows = [];
        $this->db->method('insertRow')->willReturnCallback(
            function (string $table, array $data) use (&$logRows) {
                $logRows[] = [$table, $data];
                return 1;
            },
        );
        $updates = [];
        $this->db->method('updateWhere')->willReturnCallback(
            function (string $table, array $data) use (&$updates) {
                $updates[] = $data;
            },
        );

        $this->assertTrue($this->service->attemptSend(5, $this->now()));

        $this->assertCount(1, $logRows);
        $this->assertSame('core_mail_outbox_log', $logRows[0][0]);
        $this->assertSame('ok', $logRows[0][1]['result']);
        $this->assertSame('sender:3', $logRows[0][1]['transport']);
        $this->assertSame(1, $logRows[0][1]['attempt']);

        $this->assertCount(1, $updates);
        $this->assertSame('sent', $updates[0]['state']);
        $this->assertSame(self::NOW, $updates[0]['sent_at']);
        $this->assertSame(1, $updates[0]['attempt_count']);
    }

    // ── posluchač výsledku transportu (#90 D43) ─────────────────────

    /** Zaregistruje posluchače a vrátí zachycená volání `[sourceRef, outboxId, state, error]`. */
    private function listen(string $prefix = 'sentMessage:', ?\Throwable $fails = null): \ArrayObject
    {
        $calls    = new \ArrayObject();
        $listener = new class ($calls, $fails) implements OutboxSourceListener {
            public function __construct(private readonly \ArrayObject $calls, private readonly ?\Throwable $fails) {}

            public function outboxStateChanged(
                string $sourceRef,
                int $outboxId,
                string $state,
                \DateTimeImmutable $at,
                ?string $error = null,
            ): void {
                $this->calls[] = [$sourceRef, $outboxId, $state, $error];
                if ($this->fails !== null) {
                    throw $this->fails;
                }
            }
        };
        $this->service->addSourceListener($prefix, $listener);
        return $calls;
    }

    private function primeTransport(?\Throwable $sendFails = null): void
    {
        $transport = $this->createMock(TransportInterface::class);
        if ($sendFails !== null) {
            $transport->method('send')->willThrowException($sendFails);
        }
        $this->resolver->method('resolve')->willReturn(new ResolvedTransport($transport, 'relay:587'));
        $this->composer->method('compose')->willReturn(new Email());
        $this->db->method('insertRow')->willReturn(1);
    }

    public function testListenerHearsSentMessage(): void
    {
        $this->primeClaim($this->outboxRow(['source_ref' => 'sentMessage:12']));
        $this->primeTransport();
        $calls = $this->listen();

        $this->assertTrue($this->service->attemptSend(5, $this->now()));

        $this->assertSame([['sentMessage:12', 5, OutboxSourceListener::STATE_SENT, null]], $calls->getArrayCopy());
    }

    public function testListenerDoesNotHearRetriedFailure(): void
    {
        // Mezistav (další pokus po chybě) původce zprávy nezajímá.
        $this->primeClaim($this->outboxRow(['source_ref' => 'sentMessage:12', 'attempt_count' => 0]));
        $this->primeTransport(new \RuntimeException('Connection refused'));
        $calls = $this->listen();

        $this->assertFalse($this->service->attemptSend(5, $this->now()));

        $this->assertCount(0, $calls);
    }

    public function testListenerHearsTerminalFailureWithError(): void
    {
        $this->primeClaim($this->outboxRow([
            'source_ref'    => 'sentMessage:12',
            'attempt_count' => MailOutboxService::MAX_ATTEMPTS - 1,
        ]));
        $this->primeTransport(new \RuntimeException('Mailbox unavailable'));
        $calls = $this->listen();

        $this->assertFalse($this->service->attemptSend(5, $this->now()));

        $this->assertSame(
            [['sentMessage:12', 5, OutboxSourceListener::STATE_FAILED, 'Mailbox unavailable']],
            $calls->getArrayCopy(),
        );
    }

    public function testListenerHearsOnlyItsOwnSourceRefPrefix(): void
    {
        $this->primeClaim($this->outboxRow(['source_ref' => 'invite:7']));
        $this->primeTransport();
        $calls = $this->listen('sentMessage:');

        $this->assertTrue($this->service->attemptSend(5, $this->now()));

        $this->assertCount(0, $calls);
    }

    public function testFailingListenerDoesNotBreakSentState(): void
    {
        $this->primeClaim($this->outboxRow(['source_ref' => 'sentMessage:12']));
        $this->primeTransport();
        $this->listen(fails: new \RuntimeException('listener down'));

        $updates = [];
        $this->db->method('updateWhere')->willReturnCallback(
            function (string $table, array $data) use (&$updates) {
                $updates[] = $data;
            },
        );

        // Fronta má stav zapsaný dřív, než se posluchač ozve.
        $this->assertTrue($this->service->attemptSend(5, $this->now()));
        $this->assertCount(1, $updates);
        $this->assertSame('sent', $updates[0]['state']);
    }

    public function testListenerHearsRequeuedMessageOnRetry(): void
    {
        $this->db->method('getAffectedRows')->willReturn(1);
        $this->db->method('fetchSingle')->willReturn('sentMessage:12');
        $calls = $this->listen();

        $this->assertTrue($this->service->retry(9, $this->now()));

        $this->assertSame([['sentMessage:12', 9, OutboxSourceListener::STATE_REQUEUED, null]], $calls->getArrayCopy());
    }

    public function testSecondClaimOfSameMessageFails(): void
    {
        // claim UPDATE nezasáhl žádný řádek → zpráva už není pending
        $this->db->method('getAffectedRows')->willReturn(0);
        $this->db->expects($this->never())->method('fetchRow');
        $this->resolver->expects($this->never())->method('resolve');

        $this->assertFalse($this->service->attemptSend(5, $this->now()));
    }

    public function testFailureSchedulesBackoff(): void
    {
        $this->primeClaim($this->outboxRow(['attempt_count' => 0]));

        $transport = $this->createMock(TransportInterface::class);
        $transport->method('send')->willThrowException(new \RuntimeException('Connection refused'));
        $this->resolver->method('resolve')->willReturn(new ResolvedTransport($transport, 'relay:587'));
        $this->composer->method('compose')->willReturn(new Email());

        $logRows = [];
        $this->db->method('insertRow')->willReturnCallback(
            function (string $table, array $data) use (&$logRows) {
                $logRows[] = $data;
                return 1;
            },
        );
        $updates = [];
        $this->db->method('updateWhere')->willReturnCallback(
            function (string $table, array $data) use (&$updates) {
                $updates[] = $data;
            },
        );

        $this->assertFalse($this->service->attemptSend(5, $this->now()));

        $this->assertSame('fail', $logRows[0]['result']);
        $this->assertSame('Connection refused', $logRows[0]['smtp_response']);

        $this->assertSame('pending', $updates[0]['state']);
        $this->assertSame(1, $updates[0]['attempt_count']);
        $this->assertSame('Connection refused', $updates[0]['last_error']);
        // 1. selhání → +60 s
        $this->assertSame('2026-07-13 10:01:00', $updates[0]['next_attempt']);
    }

    public function testBackoffSchedule(): void
    {
        // attempt_count před pokusem → očekávaný odklad po selhání
        $expected = [
            0 => '2026-07-13 10:01:00', // +60 s
            1 => '2026-07-13 10:05:00', // +5 min
            2 => '2026-07-13 10:30:00', // +30 min
            3 => '2026-07-13 12:00:00', // +2 h
            4 => '2026-07-13 16:00:00', // +6 h
        ];

        foreach ($expected as $attemptCount => $nextAttempt) {
            $db       = $this->createMock(DataSourceConnection::class);
            $resolver = $this->createMock(TransportResolver::class);
            $composer = $this->createMock(MailComposer::class);
            $service  = new MailOutboxService($db, $resolver, $composer, $this->createMock(SettingsStore::class));

            $db->method('getAffectedRows')->willReturn(1);
            $db->method('fetchRow')->willReturn($this->outboxRow(['attempt_count' => $attemptCount]));
            $resolver->method('resolve')->willThrowException(new \RuntimeException('down'));

            $updates = [];
            $db->method('updateWhere')->willReturnCallback(
                function (string $table, array $data) use (&$updates) {
                    $updates[] = $data;
                },
            );

            $service->attemptSend(5, $this->now());

            $this->assertSame('pending', $updates[0]['state'], "attempt_count={$attemptCount}");
            $this->assertSame($nextAttempt, $updates[0]['next_attempt'], "attempt_count={$attemptCount}");
        }
    }

    public function testSixthFailureIsTerminal(): void
    {
        $this->primeClaim($this->outboxRow(['attempt_count' => 5]));
        $this->resolver->method('resolve')->willThrowException(new \RuntimeException('still down'));

        $updates = [];
        $this->db->method('updateWhere')->willReturnCallback(
            function (string $table, array $data) use (&$updates) {
                $updates[] = $data;
            },
        );

        $this->assertFalse($this->service->attemptSend(5, $this->now()));

        $this->assertSame('failed', $updates[0]['state']);
        $this->assertSame(6, $updates[0]['attempt_count']);
        $this->assertSame('still down', $updates[0]['last_error']);
        $this->assertArrayNotHasKey('next_attempt', $updates[0]);
    }

    public function testResolutionFailureLogsUnresolvedTransport(): void
    {
        $this->primeClaim($this->outboxRow());
        $this->resolver->method('resolve')->willThrowException(new \RuntimeException('no relay configured'));

        $logRows = [];
        $this->db->method('insertRow')->willReturnCallback(
            function (string $table, array $data) use (&$logRows) {
                $logRows[] = $data;
                return 1;
            },
        );

        $this->service->attemptSend(5, $this->now());

        $this->assertSame('unresolved', $logRows[0]['transport']);
        $this->assertSame('fail', $logRows[0]['result']);
    }

    // ── processQueue ────────────────────────────────────────────────

    public function testProcessQueueRecoversStaleSendingAndOrders(): void
    {
        /** @var MailOutboxService&MockObject $service */
        $service = $this->getMockBuilder(MailOutboxService::class)
            ->setConstructorArgs([$this->db, $this->resolver, $this->composer, $this->settings])
            ->onlyMethods(['attemptSend'])
            ->getMock();

        $executed = [];
        $this->db->method('execute')->willReturnCallback(
            function (string $sql, ...$args) use (&$executed) {
                $executed[] = [$sql, $args];
            },
        );
        $this->db->method('getAffectedRows')->willReturn(2);

        $fetchAllSql = null;
        $this->db->method('fetchAll')->willReturnCallback(
            function (string $sql, ...$args) use (&$fetchAllSql) {
                $fetchAllSql = $sql;
                return [['id' => 1], ['id' => 2], ['id' => 3]];
            },
        );

        // 1 → sent, 2 → retried (pending), 3 → failed
        $service->method('attemptSend')->willReturnCallback(
            static fn (int $id) => $id === 1,
        );
        $this->db->method('fetchSingle')->willReturnOnConsecutiveCalls('pending', 'failed');

        $stats = $service->processQueue(50, $this->now());

        // recovery UPDATE se zaseklým sending starším 10 minut
        $this->assertStringContainsString("state = 'sending' AND claimed_at <", $executed[0][0]);
        $this->assertSame('2026-07-13 09:50:00', $executed[0][1][0]);

        $this->assertStringContainsString('ORDER BY priority DESC, created ASC', $fetchAllSql);

        $this->assertSame(
            ['requeued' => 2, 'processed' => 3, 'sent' => 1, 'retried' => 1, 'failed' => 1],
            $stats,
        );
    }

    // ── retry ───────────────────────────────────────────────────────

    public function testRetryRequeuesFailedMessage(): void
    {
        $executed = null;
        $this->db->method('execute')->willReturnCallback(
            function (string $sql, ...$args) use (&$executed) {
                $executed = [$sql, $args];
            },
        );
        $this->db->method('getAffectedRows')->willReturn(1);

        $this->assertTrue($this->service->retry(9, $this->now()));

        $this->assertStringContainsString("state = 'pending', attempt_count = 0", $executed[0]);
        $this->assertStringContainsString("WHERE id = %i AND state = 'failed'", $executed[0]);
        $this->assertSame([self::NOW, 9], $executed[1]);
    }

    public function testRetryOnNonFailedReturnsFalse(): void
    {
        $this->db->method('getAffectedRows')->willReturn(0);

        $this->assertFalse($this->service->retry(9, $this->now()));
    }

    // ── enqueueAndSend ──────────────────────────────────────────────

    public function testEnqueueAndSendElevatesPriorityAndSwallowsErrors(): void
    {
        /** @var MailOutboxService&MockObject $service */
        $service = $this->getMockBuilder(MailOutboxService::class)
            ->setConstructorArgs([$this->db, $this->resolver, $this->composer, $this->settings])
            ->onlyMethods(['attemptSend'])
            ->getMock();
        $service->method('attemptSend')->willThrowException(new \RuntimeException('infra down'));

        $captured = null;
        $this->db->method('insertRow')->willReturnCallback(
            function (string $table, array $data) use (&$captured) {
                $captured = $data;
                return 33;
            },
        );

        $id = $service->enqueueAndSend(
            $this->message(['priority' => 0, 'cc' => ['kopie@firma.cz'], 'fromName' => 'Firma s.r.o.']),
            $this->now(),
        );

        $this->assertSame(33, $id);
        $this->assertSame(MailOutboxService::PRIORITY_HIGH, $captured['priority']);
        // Zvýšení priority nesmí zahodit ostatní pole zprávy.
        $this->assertSame('kopie@firma.cz', $captured['email_cc']);
        $this->assertSame('Firma s.r.o.', $captured['email_from_name']);
    }

    public function testEnqueueAndSendPropagatesValidationErrors(): void
    {
        $this->settings->method('get')->willReturn(null);

        $this->expectException(MailValidationException::class);

        $this->service->enqueueAndSend($this->message(['from' => null]), $this->now());
    }
}
