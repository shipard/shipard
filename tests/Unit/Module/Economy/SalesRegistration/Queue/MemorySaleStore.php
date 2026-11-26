<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Queue;

use Dibi\Connection;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleQueue;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleState;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleStore;

/** Úložiště fronty v paměti — stejná pravidla jako SQL v {@see SaleStore}. */
final class MemorySaleStore extends SaleStore
{
    /** @var array<int, array<string, mixed>> */
    public array $sales = [];

    /** @var array<int, array<string, mixed>> */
    public array $attempts = [];

    /** @var array<int, array<string, mixed>> */
    public array $registrations = [];

    public function __construct()
    {
        parent::__construct((new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor());
    }

    /** @param array<string, mixed> $override */
    public function addSale(int $id, array $override = []): void
    {
        $this->sales[$id] = $override + [
            'id' => $id, 'doc_head' => 100 + $id, 'doc_sequence' => 1, 'registration' => 1, 'unit' => 2,
            'unit_id' => '181', 'cash_register_id' => 'cashreg-P1', 'serial_number' => 'PR' . $id,
            'sold_at' => '2027-02-10 09:30:00', 'amount' => '121.00', 'currency' => 'czk',
            'created' => '2027-02-10 09:30:01', 'state' => SaleState::PENDING, 'attempt_count' => 0,
            'next_attempt' => '2027-02-10 09:30:01', 'claimed_at' => null, 'confirmation_code' => null,
            'confirmed_at' => null, 'environment' => null, 'test' => 0, 'last_problem' => null, 'last_error' => null,
        ];
    }

    public function claim(int $saleId, string $stamp): bool
    {
        if (($this->sales[$saleId]['state'] ?? null) !== SaleState::PENDING) {
            return false;
        }
        $this->sales[$saleId]['state'] = SaleState::SENDING;
        $this->sales[$saleId]['claimed_at'] = $stamp;
        return true;
    }

    public function sale(int $saleId): ?array
    {
        return $this->sales[$saleId] ?? null;
    }

    public function registration(int $registrationId): ?array
    {
        return $this->registrations[$registrationId] ?? null;
    }

    public function hasSentAttempt(int $saleId): bool
    {
        foreach ($this->attempts as $attempt) {
            if ($attempt['sale'] === $saleId && !empty($attempt['sent'])) {
                return true;
            }
        }
        return false;
    }

    public function insertAttempt(array $data): int
    {
        $id = count($this->attempts) + 1;
        $this->attempts[$id] = $data + ['id' => $id, 'sent' => 0];
        return $id;
    }

    public function updateAttempt(int $attemptId, array $data): void
    {
        $this->attempts[$attemptId] = $data + $this->attempts[$attemptId];
    }

    public function finish(int $saleId, array $data): void
    {
        if (($this->sales[$saleId]['state'] ?? null) === SaleState::SENDING) {
            $this->sales[$saleId] = $data + $this->sales[$saleId];
        }
    }

    public function due(int $limit, string $stamp): array
    {
        $due = array_filter(
            $this->sales,
            static fn(array $sale): bool => $sale['state'] === SaleState::PENDING && $sale['next_attempt'] <= $stamp,
        );
        usort($due, static fn(array $a, array $b): int => [$a['next_attempt'], $a['id']] <=> [$b['next_attempt'], $b['id']]);
        return array_map(
            static fn(array $sale): array => ['id' => $sale['id'], 'registration' => $sale['registration']],
            array_slice($due, 0, $limit),
        );
    }

    public function recoverStale(string $stamp, string $staleBefore): int
    {
        $count = 0;
        foreach ($this->sales as $id => $sale) {
            if ($sale['state'] === SaleState::SENDING && ($sale['claimed_at'] === null || $sale['claimed_at'] < $staleBefore)) {
                $this->sales[$id] = ['state' => SaleState::PENDING, 'claimed_at' => null, 'next_attempt' => $stamp] + $sale;
                $count++;
            }
        }
        return $count;
    }

    public function lastAttemptStatus(int $registrationId, string $since): ?string
    {
        $last = null;
        foreach ($this->attempts as $attempt) {
            if ($this->sales[$attempt['sale']]['registration'] === $registrationId
                && $attempt['started_at'] >= $since && $attempt['status'] !== SaleQueue::ATTEMPT_SENDING
                && ($last === null || [$attempt['started_at'], $attempt['id']] >= [$last['started_at'], $last['id']])
            ) {
                $last = $attempt;
            }
        }
        return $last['status'] ?? null;
    }

    public function requeue(int $saleId, string $stamp): bool
    {
        if (!in_array($this->sales[$saleId]['state'] ?? null, SaleState::RESENDABLE, true)) {
            return false;
        }
        $this->sales[$saleId] = ['state' => SaleState::PENDING, 'next_attempt' => $stamp, 'claimed_at' => null] + $this->sales[$saleId];
        return true;
    }
}
