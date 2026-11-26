<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Queue;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Economy\SalesRegistration\Engine\SendResult;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleOutcome;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleQueue;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleState;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;

/**
 * Fronta tržeb (#171 D5, D7, D24, U5, U6): zámek odesílatele, pokus zapsaný
 * před odesláním, `prvni_zaslani`, stavy a backoff, obnova zaseknutých,
 * jistič a Odeslat znovu.
 */
class SaleQueueTest extends TestCase
{
    use SalesRegistrationConfig;

    private const NOW = '2027-02-10 10:00:00';

    private MemorySaleStore $store;
    private ScriptedSender $sender;
    private SaleQueue $queue;
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/salesreg-queue-' . bin2hex(random_bytes(4)) . '.log';
        ErrorLogger::setLogPath($this->logFile);

        $this->store = new MemorySaleStore();
        $this->store->registrations[1] = ['id' => 1, 'type' => 'cz.eet2', 'mode' => 'production', 'docState' => 40, 'cert_key' => 'tajné'];
        $this->sender = new ScriptedSender();
        $this->queue = new SaleQueue($this->store, $this->sender, $this->salesRegistrationConfig());
    }

    protected function tearDown(): void
    {
        ErrorLogger::setLogPath(null);
        @unlink($this->logFile);
    }

    private function at(string $time = self::NOW): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time);
    }

    public function testConfirmedSaleStoresTheCodeAndTheAttempt(): void
    {
        $this->store->addSale(7);

        $attempt = $this->queue->attempt(7, $this->at());

        $this->assertNotNull($attempt);
        $this->assertSame(SaleState::CONFIRMED, $attempt->state);
        $sale = $this->store->sales[7];
        $this->assertSame(SaleState::CONFIRMED, $sale['state']);
        $this->assertSame('pok-1', $sale['confirmation_code']);
        $this->assertSame(self::NOW, $sale['confirmed_at']);
        $this->assertSame(1, $sale['attempt_count']);
        $this->assertNull($sale['next_attempt']);
        $this->assertNull($sale['claimed_at']);
        $this->assertSame('production', $sale['environment']);
        $this->assertSame(0, $sale['test']);

        $stored = $this->store->attempts[1];
        $this->assertSame(7, $stored['sale']);
        $this->assertSame('confirmed', $stored['status']);
        $this->assertSame(1, $stored['sent']);
        $this->assertSame(1, $stored['first_sending']);
        $this->assertSame(self::NOW, $stored['started_at']);
        $this->assertSame('tx-1', $stored['transaction_id']);
        $this->assertSame('<req/>', $stored['request_body']);
        $this->assertSame('<res/>', $stored['response_body']);
        $this->assertSame(120, $stored['duration_ms']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $stored['uuid']);
    }

    public function testMessageCarriesTheFixedDataOfTheSale(): void
    {
        $this->store->addSale(7, ['amount' => '-121.50', 'serial_number' => 'PR7-2', 'sold_at' => '2027-02-10 09:30:00']);

        $this->queue->attempt(7, $this->at());

        $message = $this->sender->messages[0];
        $this->assertSame('181', $message->unitId);
        $this->assertSame('cashreg-P1', $message->cashRegisterId);
        $this->assertSame('PR7-2', $message->serialNumber);
        $this->assertSame('-121.50', $message->amount);
        $this->assertSame('czk', $message->currency);
        $this->assertTrue($message->firstSending);
        $this->assertSame($this->store->attempts[1]['uuid'], $message->uuid);
        // Čas tržby: uložený v zóně serveru, do zprávy v zóně místa tržby (U10).
        $server = new \DateTimeImmutable('2027-02-10 09:30:00', new \DateTimeZone(date_default_timezone_get()));
        $this->assertSame($server->getTimestamp(), $message->soldAt->getTimestamp());
        $this->assertSame('Europe/Prague', $message->soldAt->getTimezone()->getName());
        $this->assertSame(1, $this->sender->registrations[0]['id']);
    }

    /** D24: tutéž tržbu nikdy nepošlou dva procesy současně. */
    public function testSaleClaimedByAnotherProcessIsNotSent(): void
    {
        $this->store->addSale(7, ['state' => SaleState::SENDING, 'claimed_at' => self::NOW]);
        $this->store->addSale(8, ['state' => SaleState::CONFIRMED]);

        $this->assertNull($this->queue->attempt(7, $this->at()));
        $this->assertNull($this->queue->attempt(8, $this->at()));
        $this->assertNull($this->queue->attempt(999, $this->at()));
        $this->assertSame([], $this->sender->messages);
        $this->assertSame([], $this->store->attempts);
    }

    public function testOutageGoesBackToPendingWithGrowingBackoff(): void
    {
        $this->store->addSale(7);
        $this->sender->script = [
            ScriptedSender::retryLater(), ScriptedSender::unknown(), ScriptedSender::retryLater(),
            ScriptedSender::retryLater(), ScriptedSender::retryLater(),
        ];

        $expected = ['2027-02-10 10:01:00', '2027-02-10 10:05:00', '2027-02-10 10:15:00', '2027-02-10 11:00:00', '2027-02-10 11:00:00'];
        foreach ($expected as $i => $next) {
            $attempt = $this->queue->attempt(7, $this->at());
            $this->assertSame(SaleState::PENDING, $attempt->state);
            $this->assertSame($next, $this->store->sales[7]['next_attempt'], 'pokus ' . ($i + 1));
            $this->assertSame($i + 1, $this->store->sales[7]['attempt_count']);
            $this->assertNull($this->store->sales[7]['claimed_at']);
        }
        $this->assertSame('Dočasná technická chyba', $this->store->sales[7]['last_error']);
    }

    /** `prvni_zaslani` je true jen do prvního pokusu, který zprávu skutečně odeslal. */
    public function testFirstSendingIsTrueUntilAMessageReallyLeft(): void
    {
        $this->store->addSale(7);
        $this->sender->script = [
            ScriptedSender::ourProblem(SendResult::PROBLEM_NO_CERTIFICATE), // neodešlo
            ScriptedSender::unknown(),                                       // odešlo, odpověď nepřišla
            ScriptedSender::confirmed(),
        ];

        foreach ([1, 2, 3] as $n) {
            $this->queue->attempt(7, $this->at());
        }

        $this->assertSame([true, true, false], array_map(static fn($m): bool => $m->firstSending, $this->sender->messages));
        $this->assertSame([1, 1, 0], array_column($this->store->attempts, 'first_sending'));
        $this->assertSame([0, 1, 1], array_column($this->store->attempts, 'sent'));
        $uuids = array_column($this->store->attempts, 'uuid');
        $this->assertCount(3, array_unique($uuids), 'opakování = nové UUID');
        $this->assertSame(SaleState::CONFIRMED, $this->store->sales[7]['state']);
        $this->assertSame(3, $this->store->sales[7]['attempt_count']);
    }

    public function testProblemOnOurSideIsRetriedAndRemembered(): void
    {
        $this->store->addSale(7);
        $this->sender->script = [ScriptedSender::ourProblem(SendResult::PROBLEM_NO_CERTIFICATE)];

        $attempt = $this->queue->attempt(7, $this->at());

        $this->assertSame(SaleState::PENDING, $attempt->state);
        $this->assertSame(SendResult::PROBLEM_NO_CERTIFICATE, $this->store->sales[7]['last_problem']);
        $this->assertSame('2027-02-10 10:01:00', $this->store->sales[7]['next_attempt']);
        $this->assertSame('rejected', $this->store->attempts[1]['status']);
        $this->assertSame(SendResult::PROBLEM_NO_CERTIFICATE, $this->store->attempts[1]['problem']);
    }

    public function testRejectedDroppedAndVerifiedAreFinal(): void
    {
        foreach ([
            7 => [ScriptedSender::rejectedByService(5, 'Neplatná data'), SaleState::FAILED],
            8 => [ScriptedSender::dropped(), SaleState::DROPPED],
            9 => [ScriptedSender::verified(), SaleState::VERIFIED],
        ] as $id => [$result, $state]) {
            $this->store->addSale($id);
            $this->sender->script = [$result];

            $this->queue->attempt($id, $this->at());

            $this->assertSame($state, $this->store->sales[$id]['state']);
            $this->assertNull($this->store->sales[$id]['next_attempt'], $state);
            $this->assertNull($this->store->sales[$id]['confirmation_code'], $state);
        }
        $this->assertSame('Neplatná data', $this->store->sales[7]['last_error']);
        $this->assertSame(5, $this->store->attempts[1]['error_code']);
        $this->assertSame(1, $this->store->attempts[3]['verification']);
    }

    public function testPlaygroundConfirmationIsMarkedAsTest(): void
    {
        $this->store->addSale(7);
        $this->sender->script = [ScriptedSender::confirmed('pok-ff', 'playground', true)];

        $this->queue->attempt(7, $this->at());

        $this->assertSame(1, $this->store->sales[7]['test'], 'potvrzení z playgroundu povinnost nesplňuje');
        $this->assertSame('playground', $this->store->sales[7]['environment']);
        $this->assertSame(1, $this->store->attempts[1]['test_identity']);
    }

    public function testRegistrationThatIsNotConfirmedIsAProblemOnOurSide(): void
    {
        $this->store->addSale(7);
        $this->store->registrations[1]['docState'] = 70;
        $this->store->addSale(8, ['registration' => 99]);

        foreach ([7, 8] as $id) {
            $attempt = $this->queue->attempt($id, $this->at());
            $this->assertSame(SaleState::PENDING, $attempt->state);
            $this->assertSame(SaleOutcome::PROBLEM_REGISTRATION, $this->store->sales[$id]['last_problem']);
        }
        $this->assertSame([], $this->sender->messages, 'nic se neposílá');
    }

    public function testExceptionWhileSendingNeverLeavesTheSaleStuck(): void
    {
        $this->store->addSale(7);
        $this->sender->script = [new \RuntimeException('odesílač spadl')];

        $attempt = $this->queue->attempt(7, $this->at());

        $this->assertSame(SaleState::PENDING, $attempt->state);
        $this->assertSame(SendResult::PROBLEM_INTERNAL, $this->store->sales[7]['last_problem']);
        $this->assertSame('rejected', $this->store->attempts[1]['status']);
    }

    public function testLogCarriesIdsAndCodesButNoMessageBodiesOrKeys(): void
    {
        $this->store->addSale(7);

        $this->queue->attempt(7, $this->at());

        $log = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('sales registration: sale confirmed', $log);
        $this->assertStringContainsString('"sale":7', $log);
        $this->assertStringContainsString('"durationMs":120', $log);
        $this->assertStringNotContainsString('<req/>', $log);
        $this->assertStringNotContainsString('<res/>', $log);
        $this->assertStringNotContainsString('tajné', $log);
    }

    // ── Běh cronu ───────────────────────────────────────────────────────────

    public function testProcessDueSendsOnlyDueSalesOldestFirstUpToTheLimit(): void
    {
        $this->store->addSale(1, ['next_attempt' => '2027-02-10 09:59:00']);
        $this->store->addSale(2, ['next_attempt' => '2027-02-10 09:00:00']);
        $this->store->addSale(3, ['next_attempt' => '2027-02-10 10:30:00']);            // ještě ne
        $this->store->addSale(4, ['state' => SaleState::FAILED, 'next_attempt' => null]); // jen ručně
        $this->store->addSale(5, ['next_attempt' => '2027-02-10 09:30:00']);

        $stats = $this->queue->processDue(2, $this->at());

        $this->assertSame(2, $stats['processed']);
        $this->assertSame(2, $stats['confirmed']);
        $this->assertSame(['PR2', 'PR5'], array_map(static fn($m): string => $m->serialNumber, $this->sender->messages));
        $this->assertSame(SaleState::PENDING, $this->store->sales[1]['state'], 'nad limit — příští běh');
        $this->assertSame(SaleState::FAILED, $this->store->sales[4]['state']);
    }

    /** Po výpadku se zbylé tržby téže registrace v běhu přeskočí. */
    public function testOutageSkipsTheRestOfTheRegistrationInThisRun(): void
    {
        $this->store->registrations[2] = ['id' => 2, 'type' => 'cz.eet2', 'docState' => 40];
        $this->store->addSale(1);
        $this->store->addSale(2);
        $this->store->addSale(3, ['registration' => 2]);
        $this->store->addSale(4);
        $this->sender->script = [ScriptedSender::retryLater(), ScriptedSender::confirmed()];

        $stats = $this->queue->processDue(20, $this->at());

        $this->assertSame(2, $stats['processed']);
        $this->assertSame(1, $stats['retried']);
        $this->assertSame(1, $stats['confirmed']);
        $this->assertSame(2, $stats['skipped']);
        $this->assertSame(SaleState::PENDING, $this->store->sales[2]['state']);
        $this->assertSame(0, $this->store->sales[2]['attempt_count'], 'přeskočená tržba pokus nespotřebovala');
        $this->assertSame(SaleState::CONFIRMED, $this->store->sales[3]['state'], 'jiná registrace jede dál');
    }

    public function testStaleSendingIsReturnedToPendingWithoutCountingAnAttempt(): void
    {
        $this->store->addSale(1, ['state' => SaleState::SENDING, 'claimed_at' => '2027-02-10 09:49:59', 'attempt_count' => 2]);
        $this->store->addSale(2, ['state' => SaleState::SENDING, 'claimed_at' => '2027-02-10 09:55:00']);

        $this->assertSame(1, $this->queue->recoverStale($this->at()));

        $this->assertSame(SaleState::PENDING, $this->store->sales[1]['state']);
        $this->assertSame(2, $this->store->sales[1]['attempt_count'], 'obnova počet pokusů nezvyšuje');
        $this->assertSame(self::NOW, $this->store->sales[1]['next_attempt']);
        $this->assertNull($this->store->sales[1]['claimed_at']);
        $this->assertSame(SaleState::SENDING, $this->store->sales[2]['state'], 'mladší než 10 minut — proces ještě může běžet');

        // Běh cronu obnovenou tržbu rovnou pošle.
        $stats = $this->queue->processDue(20, $this->at());
        $this->assertSame(0, $stats['requeued']);
        $this->assertSame(SaleState::CONFIRMED, $this->store->sales[1]['state']);
        $this->assertSame(3, $this->store->sales[1]['attempt_count']);
    }

    // ── Přerušený pokus (F2) ────────────────────────────────────────────────

    /** Proces spadl mezi odesláním a zápisem výsledku: pokus zůstal `sending`. */
    private function orphanedAttempt(int $saleId, string $claimedAt): int
    {
        $this->store->addSale($saleId, ['state' => SaleState::SENDING, 'claimed_at' => $claimedAt]);
        return $this->store->insertAttempt([
            'sale' => $saleId, 'uuid' => 'uuid-' . $saleId, 'started_at' => $claimedAt,
            'first_sending' => 1, 'status' => SaleQueue::ATTEMPT_SENDING,
        ]);
    }

    public function testRecoveryMarksTheOrphanedAttemptInterrupted(): void
    {
        $this->store->addSale(1);
        $this->sender->script = [ScriptedSender::retryLater()];
        $this->queue->attempt(1, $this->at('2027-02-10 09:40:00'));
        $this->store->sales[1] = ['state' => SaleState::SENDING, 'claimed_at' => '2027-02-10 09:45:00'] + $this->store->sales[1];
        $orphan = $this->store->insertAttempt([
            'sale' => 1, 'uuid' => 'uuid-1b', 'started_at' => '2027-02-10 09:45:00',
            'first_sending' => 0, 'status' => SaleQueue::ATTEMPT_SENDING,
        ]);
        $running = $this->orphanedAttempt(2, '2027-02-10 09:55:00');

        $this->assertSame(1, $this->queue->recoverStale($this->at()));

        $this->assertSame(SaleQueue::ATTEMPT_INTERRUPTED, $this->store->attempts[$orphan]['status']);
        $this->assertSame(0, $this->store->attempts[$orphan]['sent'], 'jestli zpráva odešla, nevíme');
        $this->assertSame('retryLater', $this->store->attempts[1]['status'], 'dokončený pokus obnova nemění');
        $this->assertSame(SaleQueue::ATTEMPT_SENDING, $this->store->attempts[$running]['status'], 'tržba mladší než 10 minut — proces ještě může běžet');
    }

    /** Metodika: `prvni_zaslani = false` u každého dalšího pokusu o zaslání téže tržby. */
    public function testAttemptAfterAnInterruptedOneIsNotTheFirstSending(): void
    {
        $orphan = $this->orphanedAttempt(1, '2027-02-10 09:45:00');

        $stats = $this->queue->processDue(20, $this->at());

        $this->assertSame(1, $stats['requeued']);
        $this->assertSame(1, $stats['confirmed']);
        $this->assertCount(1, $this->sender->messages);
        $this->assertFalse($this->sender->messages[0]->firstSending);
        $this->assertSame(
            [SaleQueue::ATTEMPT_INTERRUPTED, 'confirmed'],
            array_column($this->store->attempts, 'status'),
        );
        $this->assertSame([1, 0], array_column($this->store->attempts, 'first_sending'));
        $this->assertSame(0, $this->store->attempts[$orphan]['sent']);
        $this->assertSame(1, $this->store->sales[1]['attempt_count'], 'přerušený pokus se do počtu nezapočítal');
    }

    /** Zahozeno pojistkou ani problém na naší straně zprávu neodeslaly — dál je to první zaslání. */
    public function testDroppedAttemptAndProblemOnOurSideStillDoNotCountAsSent(): void
    {
        $this->store->addSale(7);
        $this->sender->script = [
            ScriptedSender::ourProblem(SendResult::PROBLEM_NO_CERTIFICATE),
            ScriptedSender::dropped(),
            ScriptedSender::confirmed(),
        ];

        $this->queue->attempt(7, $this->at());
        $this->queue->attempt(7, $this->at());
        // Pojistka serveru se vypnula a tržba se vrátila do fronty.
        $this->store->sales[7]['state'] = SaleState::PENDING;
        $this->queue->attempt(7, $this->at());

        $this->assertSame(['rejected', 'dropped', 'confirmed'], array_column($this->store->attempts, 'status'));
        $this->assertSame([true, true, true], array_map(static fn($m): bool => $m->firstSending, $this->sender->messages));
        $this->assertSame([1, 1, 1], array_column($this->store->attempts, 'first_sending'));
    }

    /** Přerušený pokus nemá výsledek — o jističi rozhoduje poslední dokončený pokus. */
    public function testInterruptedAttemptDoesNotCloseTheCircuit(): void
    {
        $this->store->addSale(1);
        $this->sender->script = [ScriptedSender::retryLater()];
        $this->queue->attempt(1, $this->at('2027-02-10 10:00:00'));
        $this->store->addSale(2);
        $this->store->insertAttempt([
            'sale' => 2, 'uuid' => 'uuid-2', 'started_at' => '2027-02-10 10:00:10',
            'first_sending' => 1, 'status' => SaleQueue::ATTEMPT_INTERRUPTED,
        ]);

        $this->assertTrue($this->queue->circuitOpen(1, $this->at('2027-02-10 10:00:30')));
    }

    // ── Jistič (U6) ─────────────────────────────────────────────────────────

    public function testCircuitIsOpenForAMinuteAfterAnOutageOfTheSameRegistration(): void
    {
        $this->store->registrations[2] = ['id' => 2, 'type' => 'cz.eet2', 'docState' => 40];
        $this->store->addSale(1);
        $this->store->addSale(2, ['registration' => 2]);
        $this->sender->script = [ScriptedSender::retryLater()];
        $this->queue->attempt(1, $this->at('2027-02-10 10:00:00'));

        $this->assertTrue($this->queue->circuitOpen(1, $this->at('2027-02-10 10:00:30')));
        $this->assertTrue($this->queue->circuitOpen(1, $this->at('2027-02-10 10:01:00')));
        $this->assertFalse($this->queue->circuitOpen(1, $this->at('2027-02-10 10:01:01')), 'po 60 s se zkouší znovu');
        $this->assertFalse($this->queue->circuitOpen(2, $this->at('2027-02-10 10:00:30')), 'jiná registrace');
    }

    public function testCircuitClosesWithTheNextSuccessfulAttempt(): void
    {
        $this->store->addSale(1);
        $this->store->addSale(2);
        $this->sender->script = [ScriptedSender::unknown(), ScriptedSender::confirmed()];
        $this->queue->attempt(1, $this->at('2027-02-10 10:00:00'));
        $this->assertTrue($this->queue->circuitOpen(1, $this->at('2027-02-10 10:00:10')));

        $this->queue->attempt(2, $this->at('2027-02-10 10:00:20'));

        $this->assertFalse($this->queue->circuitOpen(1, $this->at('2027-02-10 10:00:30')), 'rozhoduje poslední pokus');
    }

    public function testProblemOnOurSideDoesNotOpenTheCircuit(): void
    {
        $this->store->addSale(1);
        $this->sender->script = [ScriptedSender::ourProblem(SendResult::PROBLEM_NO_CERTIFICATE)];
        $this->queue->attempt(1, $this->at());

        $this->assertFalse($this->queue->circuitOpen(1, $this->at('2027-02-10 10:00:10')));
    }

    // ── Odeslat znovu (U12) ─────────────────────────────────────────────────

    public function testResendReturnsFailedAndPendingSalesToTheQueueNow(): void
    {
        $this->store->addSale(1, ['state' => SaleState::FAILED, 'next_attempt' => null, 'attempt_count' => 1]);
        $this->store->addSale(2, ['next_attempt' => '2027-02-10 11:00:00', 'attempt_count' => 5]);
        $this->store->addSale(3, ['state' => SaleState::CONFIRMED]);
        $this->store->addSale(4, ['state' => SaleState::DROPPED]);
        $this->store->addSale(5, ['state' => SaleState::SENDING]);

        $this->assertTrue($this->queue->resend(1, $this->at()));
        $this->assertTrue($this->queue->resend(2, $this->at()));
        foreach ([3, 4, 5, 999] as $id) {
            $this->assertFalse($this->queue->resend($id, $this->at()), (string) $id);
        }

        $this->assertSame(SaleState::PENDING, $this->store->sales[1]['state']);
        $this->assertSame(self::NOW, $this->store->sales[1]['next_attempt']);
        $this->assertSame(self::NOW, $this->store->sales[2]['next_attempt']);
        $this->assertSame(5, $this->store->sales[2]['attempt_count'], 'počet pokusů zůstává — je to historie');
        $this->assertSame(SaleState::CONFIRMED, $this->store->sales[3]['state']);
    }
}
