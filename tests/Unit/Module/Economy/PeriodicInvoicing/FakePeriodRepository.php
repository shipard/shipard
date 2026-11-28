<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing;

use Shipard\Module\Economy\PeriodicInvoicing\PeriodRepository;

/** Evidence období v paměti, klíč = začátek období. */
class FakePeriodRepository extends PeriodRepository
{
    /** @var array<int, array<string, array<string, mixed>>> záznam → začátek → období */
    public array $periods = [];
    public int $locks = 0;
    /** @var array<string, int> začátek → doklad, který „mezitím“ vystavil jiný běh */
    public array $issuedUnderLock = [];
    /** @var array<int, array<string, mixed>> doklady podle id (docState, doc_type) — pro docInfo */
    public array $docs = [];

    public function find(int $id): ?array
    {
        foreach ($this->periods as $rows) {
            foreach ($rows as $period) {
                if ((int) $period['id'] === $id) {
                    return $period;
                }
            }
        }
        return null;
    }

    public function docInfo(array $docIds): array
    {
        $out = [];
        foreach ($docIds as $docId) {
            if (isset($this->docs[(int) $docId])) {
                $out[(int) $docId] = $this->docs[(int) $docId];
            }
        }
        return $out;
    }

    public function listFor(int $workOrderId): array
    {
        $rows = array_values($this->periods[$workOrderId] ?? []);
        usort($rows, static fn(array $a, array $b): int => strcmp($b['period_from'], $a['period_from']));
        return $rows;
    }

    public function ensurePlanned(int $workOrderId, array $periods, string $now): void
    {
        foreach ($periods as $period) {
            if (isset($this->periods[$workOrderId][$period->from])) {
                continue;
            }
            $this->periods[$workOrderId][$period->from] = [
                'id' => $this->nextId(), $this->recordColumn => $workOrderId, 'period_from' => $period->from, 'period_to' => $period->to,
                'state' => self::STATE_PLANNED, 'doc' => null, 'content_hash' => null, 'result' => null, 'message' => null, 'waiting_since' => null,
            ];
        }
        ksort($this->periods[$workOrderId]);
    }

    public function lockForUpdate(int $id): ?array
    {
        $this->locks++;
        foreach ($this->periods as $workOrderId => $rows) {
            foreach ($rows as $from => $period) {
                if ((int) $period['id'] === $id) {
                    if (isset($this->issuedUnderLock[$from])) {
                        $this->periods[$workOrderId][$from]['state'] = self::STATE_ISSUED;
                        $this->periods[$workOrderId][$from]['doc'] = $this->issuedUnderLock[$from];
                    }
                    return $this->periods[$workOrderId][$from];
                }
            }
        }
        return null;
    }

    private function nextId(): int
    {
        $max = 0;
        foreach ($this->periods as $rows) {
            foreach ($rows as $period) {
                $max = max($max, (int) $period['id']);
            }
        }
        return $max + 1;
    }

    public function markIssued(int $id, int $docId, string $contentHash, string $now): void
    {
        $this->set($id, ['state' => self::STATE_ISSUED, 'doc' => $docId, 'content_hash' => $contentHash, 'result' => null, 'message' => null, 'waiting_since' => null]);
    }

    public function markWaiting(int $id, int $docId, string $contentHash, ?string $message, string $now, ?string $waitingSince): void
    {
        $this->set($id, ['state' => self::STATE_WAITING, 'doc' => $docId, 'content_hash' => $contentHash, 'result' => self::RESULT_WAITING, 'message' => $message, 'waiting_since' => $waitingSince ?? $now]);
    }

    public function markPlanned(int $id, ?string $result, ?string $message, string $now): void
    {
        $this->set($id, ['state' => self::STATE_PLANNED, 'doc' => null, 'result' => $result, 'message' => $message]);
    }

    public function markResult(int $id, ?string $result, ?string $message, string $now): void
    {
        $this->set($id, ['result' => $result, 'message' => $message]);
    }

    public function unlinkDoc(int $id, string $now): void
    {
        $this->set($id, ['state' => self::STATE_PLANNED, 'doc' => null, 'content_hash' => null, 'result' => null, 'message' => null, 'waiting_since' => null]);
    }

    protected function execute(string $sql, mixed ...$args): void
    {
        throw new \LogicException('FakePeriodRepository nepíše do DB');
    }

    private function set(int $id, array $values): void
    {
        foreach ($this->periods as $workOrderId => $rows) {
            foreach ($rows as $from => $period) {
                if ((int) $period['id'] === $id) {
                    $this->periods[$workOrderId][$from] = array_merge($period, $values);
                }
            }
        }
    }
}
