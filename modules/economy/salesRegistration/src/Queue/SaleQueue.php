<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Queue;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Economy\SalesRegistration\Engine\SalesMessage;
use Shipard\Module\Economy\SalesRegistration\Engine\SendResult;
use Shipard\Module\Economy\SalesRegistration\Engine\SendStatus;
use Shipard\Module\Economy\SalesRegistration\Evaluation\Money;
use Shipard\Module\Economy\SalesRegistration\RegistrationDocument;
use Shipard\Module\Economy\SalesRegistration\Safety\SalesRegistrationServerConfig;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationType;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;
use Shipard\Module\Economy\SalesRegistration\Sender\SalesRegistrationSender;

/**
 * Fronta tržeb (#171 D5, D7, D24, U5, U6) — jediná cesta odeslání pro
 * handler dokladu, cron i akci Odeslat znovu. Vzor `MailOutboxService`.
 *
 * Jeden pokus ({@see self::attempt()}):
 *
 *  1. **zámek odesílatele** (D24): atomický `UPDATE … WHERE id = ? AND
 *     state = 'pending'` → `sending` + `claimed_at`. Kdo zámek nedostane,
 *     tržbu neposílá — tutéž tržbu tak nikdy nepošlou dva procesy současně;
 *  2. **pokus se zapíše před odesláním** (UUID, čas, stav `sending`) — pád
 *     procesu uprostřed zanechá stopu, že zpráva odejít mohla;
 *  3. odeslání přes {@see SalesRegistrationSender} s daty tržby: opakování
 *     = nové UUID, původní čas tržby, `prvni_zaslani` jen dokud žádný pokus
 *     zprávu skutečně neodeslal;
 *  4. výsledek do pokusu a stav tržby podle {@see SaleOutcome}.
 *
 * Čas dostává parametrem z PHP (žádné `NOW()` v SQL) — ve stejné zóně, v jaké
 * se ukládá čas přechodu dokladu. `sending` starší 10 minut znamená pád
 * procesu: tržba se vrací na `pending` bez zvýšení počtu pokusů.
 */
class SaleQueue
{
    /** Stav pokusu zapsaného před odesláním. */
    public const string ATTEMPT_SENDING = 'sending';

    public const int STALE_SENDING_SEC = 600;

    /** Okno jističe (U6): výpadek v posledních N sekundách = handler neposílá hned. */
    public const int CIRCUIT_SEC = 60;

    /** Kolik tržeb na jeden běh cronu — zpráva čeká na odpověď až 2,5 s. */
    public const int DEFAULT_LIMIT = 20;

    private const int ERROR_TEXT_LENGTH = 500;

    public function __construct(
        private readonly SaleStore $store,
        private readonly SalesRegistrationSender $sender,
        private readonly ?ConfigRuntime $config = null,
    ) {
    }

    public static function create(
        Connection $db,
        ?ConfigRuntime $config,
        DataSourceConfig $dsConfig,
        SalesRegistrationServerConfig $safety,
    ): self {
        return new self(new SaleStore($db), new SalesRegistrationSender($config, $dsConfig, $safety), $config);
    }

    /**
     * Jeden pokus o odeslání tržby. Null = tržbu si nevzal (není `pending`,
     * nebo ji právě posílá jiný proces).
     */
    public function attempt(int $saleId, ?\DateTimeImmutable $now = null): ?AttemptResult
    {
        $now ??= new \DateTimeImmutable();
        $stamp = $now->format('Y-m-d H:i:s');

        if (!$this->store->claim($saleId, $stamp)) {
            return null;
        }
        $sale = $this->store->sale($saleId);
        if ($sale === null) {
            return null;
        }
        $firstSending = !$this->store->hasSentAttempt($saleId);
        $uuid = SalesMessage::newUuid();
        $attemptId = $this->store->insertAttempt([
            'sale'          => $saleId,
            'uuid'          => $uuid,
            'started_at'    => $stamp,
            'first_sending' => $firstSending ? 1 : 0,
            'status'        => self::ATTEMPT_SENDING,
        ]);

        $result = $this->send($sale, $uuid, $firstSending);

        $this->store->updateAttempt($attemptId, [
            'status'            => $result->status->value,
            'sent'              => $result->sent ? 1 : 0,
            'verification'      => $result->verification ? 1 : 0,
            'environment'       => $result->environment,
            'test_identity'     => $result->testIdentity ? 1 : 0,
            'problem'           => $result->problem,
            'error_code'        => $result->errorCode,
            'error_text'        => self::shorten($result->errorText),
            'warnings'          => $result->warnings !== [] ? json_encode($result->warnings, JSON_UNESCAPED_UNICODE) : null,
            'confirmation_code' => $result->confirmationCode,
            'transaction_id'    => $result->transactionId,
            'request_body'      => $result->requestBody,
            'response_body'     => $result->responseBody,
            'duration_ms'       => $result->durationMs,
        ]);

        $outcome = SaleOutcome::of($result);
        $attemptCount = ((int) $sale['attempt_count']) + 1;
        $nextAttempt = $outcome->retry
            ? $now->modify('+' . SaleOutcome::backoffSeconds($attemptCount) . ' seconds')->format('Y-m-d H:i:s')
            : null;
        $update = [
            'state'         => $outcome->state,
            'attempt_count' => $attemptCount,
            'next_attempt'  => $nextAttempt,
            'claimed_at'    => null,
            'environment'   => $result->environment,
            'last_problem'  => $result->problem,
            'last_error'    => self::shorten($result->errorText),
        ];
        if ($outcome->state === SaleState::CONFIRMED) {
            $update['confirmation_code'] = $result->confirmationCode;
            $update['confirmed_at'] = $stamp;
            $update['test'] = ($result->test || $result->testIdentity
                || $result->environment === SalesRegistrationType::ENV_PLAYGROUND) ? 1 : 0;
        }
        $this->store->finish($saleId, $update);

        ErrorLogger::info('sales registration: sale ' . $outcome->state, [
            'sale'         => $saleId,
            'attempt'      => $attemptCount,
            'doc'          => (int) $sale['doc_head'],
            'registration' => (int) $sale['registration'],
            'uuid'         => $uuid,
            'firstSending' => $firstSending,
            'nextAttempt'  => $nextAttempt,
        ] + $result->logContext());

        return new AttemptResult($saleId, $attemptId, $outcome->state, $result, $nextAttempt);
    }

    /**
     * Zpracuje splatné tržby (`pending`, `next_attempt` ≤ teď), nejstarší
     * první. Po výpadku služby zbylé tržby téže registrace v tomto běhu
     * přeskočí — každá by čekala na stejný timeout.
     *
     * @return array{requeued: int, processed: int, confirmed: int, verified: int, dropped: int, failed: int, retried: int, skipped: int}
     */
    public function processDue(int $limit = self::DEFAULT_LIMIT, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $stats = [
            'requeued' => $this->recoverStale($now),
            'processed' => 0, 'confirmed' => 0, 'verified' => 0, 'dropped' => 0, 'failed' => 0, 'retried' => 0, 'skipped' => 0,
        ];

        $due = $this->store->due($limit, $now->format('Y-m-d H:i:s'));
        $outage = [];
        foreach ($due as $row) {
            $registration = $row['registration'];
            if (isset($outage[$registration])) {
                $stats['skipped']++;
                continue;
            }
            $attempt = $this->attempt($row['id'], $now);
            if ($attempt === null) {
                continue;
            }
            $stats['processed']++;
            $stats[match ($attempt->state) {
                SaleState::CONFIRMED => 'confirmed',
                SaleState::VERIFIED  => 'verified',
                SaleState::DROPPED   => 'dropped',
                SaleState::FAILED    => 'failed',
                default              => 'retried',
            }]++;
            if (SaleOutcome::isOutage($attempt->result)) {
                $outage[$registration] = true;
            }
        }
        return $stats;
    }

    /** Vrátí zaseknuté `sending` (pád procesu) na `pending`; počet pokusů se nezvyšuje. */
    public function recoverStale(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        return $this->store->recoverStale(
            $now->format('Y-m-d H:i:s'),
            $now->modify('-' . self::STALE_SENDING_SEC . ' seconds')->format('Y-m-d H:i:s'),
        );
    }

    /**
     * Jistič (U6): skončil poslední pokus téže registrace v posledních 60 s
     * výpadkem? Handler dokladu pak neposílá hned — při výpadku služby by
     * každé uložení prodejky čekalo na timeout — a tržbu nechá cronu.
     */
    public function circuitOpen(int $registrationId, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        $status = $this->store->lastAttemptStatus(
            $registrationId,
            $now->modify('-' . self::CIRCUIT_SEC . ' seconds')->format('Y-m-d H:i:s'),
        );
        return in_array($status, [SendStatus::RetryLater->value, SendStatus::Unknown->value], true);
    }

    /**
     * Odeslat znovu (U12): tržba `failed` nebo `pending` jde zpět do fronty
     * s okamžitým termínem. Vrací false, když tržba v takovém stavu není.
     */
    public function resend(int $saleId, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        return $this->store->requeue($saleId, $now->format('Y-m-d H:i:s'));
    }

    /**
     * Sestaví zprávu z dat tržby a předá ji odesílači. Nikdy nevyhazuje —
     * co se pokazí před odesláním, je problém na naší straně.
     *
     * @param array<string, mixed> $sale
     */
    private function send(array $sale, string $uuid, bool $firstSending): SendResult
    {
        try {
            $registration = $this->store->registration((int) $sale['registration']);
            if ($registration === null || (int) $registration['docState'] !== RegistrationDocument::STATE_CONFIRMED) {
                return SendResult::notSent(SendStatus::Rejected, SaleOutcome::PROBLEM_REGISTRATION);
            }
            $type = SalesRegistrationTypes::fromConfig($this->config)->get((string) $registration['type']);

            $message = new SalesMessage(
                uuid: $uuid,
                unitId: (string) $sale['unit_id'],
                cashRegisterId: (string) $sale['cash_register_id'],
                serialNumber: (string) $sale['serial_number'],
                soldAt: self::soldAt($sale['sold_at'], $type),
                amount: Money::decimal(Money::cents($sale['amount'])),
                currency: (string) $sale['currency'],
                firstSending: $firstSending,
            );
            return $this->sender->send($registration, $message);
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'sales registration: sale ' . (int) $sale['id'] . ' could not be sent');
            return SendResult::notSent(SendStatus::Rejected, SendResult::PROBLEM_INTERNAL, $e->getMessage());
        }
    }

    /**
     * Čas tržby je uložený v zóně serveru (jako čas přechodu dokladu); do
     * zprávy jde v zóně místa tržby podle typu (U10).
     */
    private static function soldAt(mixed $value, ?SalesRegistrationType $type): \DateTimeImmutable
    {
        $server = new \DateTimeZone(date_default_timezone_get());
        $soldAt = $value instanceof \DateTimeInterface
            ? new \DateTimeImmutable($value->format('Y-m-d H:i:s'), $server)
            : new \DateTimeImmutable((string) $value, $server);
        return $type !== null ? $soldAt->setTimezone($type->saleTimeZone()) : $soldAt;
    }

    private static function shorten(?string $text): ?string
    {
        return $text === null ? null : mb_substr($text, 0, self::ERROR_TEXT_LENGTH);
    }
}
