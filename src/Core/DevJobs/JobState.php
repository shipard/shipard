<?php

declare(strict_types=1);

namespace Shipard\Core\DevJobs;

/**
 * Průběh úlohy (`state.json`) — píše ho **jen obal** `dev-job-run`
 * ({@see \Shipard\Command\Server\DevJobRunCommand}): PID, stav, časy, výsledek
 * každého kroku. API do něj zapisuje jen ve dvou případech, kdy obal
 * prokazatelně neběží: spawn obalu selhal, nebo po vynuceném ukončení
 * (`force`) se uvolnil `wrapper.lock`. Stav `running` / `cancelling` tu
 * neznamená živý proces — živost ověřuje {@see JobStore::isWrapperAlive()}.
 */
final class JobState
{
    public const string STEP_PENDING = 'pending';
    public const string STEP_RUNNING = 'running';
    public const string STEP_FINISHED = 'finished';
    public const string STEP_FAILED = 'failed';
    public const string STEP_CANCELLED = 'cancelled';
    public const string STEP_SKIPPED = 'skipped';

    public ?int $pid = null;
    public string $status = JobStatus::RUNNING;
    public ?string $startedAt = null;
    public ?string $finishedAt = null;
    public ?string $cancelRequestedAt = null;
    public bool $forced = false;
    public ?string $error = null;
    /** @var list<array{status: string, exitCode: ?int, termSignal: ?int, startedAt: ?string, finishedAt: ?string}> */
    public array $steps = [];

    public static function pending(int $stepCount): self
    {
        $state = new self();
        for ($i = 0; $i < $stepCount; $i++) {
            $state->steps[] = self::emptyStep();
        }
        return $state;
    }

    /** @return array{status: string, exitCode: ?int, termSignal: ?int, startedAt: ?string, finishedAt: ?string} */
    public static function emptyStep(): array
    {
        return ['status' => self::STEP_PENDING, 'exitCode' => null, 'termSignal' => null, 'startedAt' => null, 'finishedAt' => null];
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $state = new self();
        $state->pid = isset($raw['pid']) && is_numeric($raw['pid']) ? (int) $raw['pid'] : null;
        $status = (string) ($raw['status'] ?? JobStatus::RUNNING);
        $state->status = JobStatus::isValid($status) ? $status : JobStatus::FAILED;
        $state->startedAt = isset($raw['startedAt']) ? (string) $raw['startedAt'] : null;
        $state->finishedAt = isset($raw['finishedAt']) ? (string) $raw['finishedAt'] : null;
        $state->cancelRequestedAt = isset($raw['cancelRequestedAt']) ? (string) $raw['cancelRequestedAt'] : null;
        $state->forced = (bool) ($raw['forced'] ?? false);
        $state->error = isset($raw['error']) ? (string) $raw['error'] : null;
        foreach ((array) ($raw['steps'] ?? []) as $step) {
            $step = is_array($step) ? $step : [];
            $state->steps[] = [
                'status' => (string) ($step['status'] ?? self::STEP_PENDING),
                'exitCode' => isset($step['exitCode']) && is_numeric($step['exitCode']) ? (int) $step['exitCode'] : null,
                'termSignal' => isset($step['termSignal']) && is_numeric($step['termSignal']) ? (int) $step['termSignal'] : null,
                'startedAt' => isset($step['startedAt']) ? (string) $step['startedAt'] : null,
                'finishedAt' => isset($step['finishedAt']) ? (string) $step['finishedAt'] : null,
            ];
        }
        return $state;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'pid' => $this->pid,
            'status' => $this->status,
            'startedAt' => $this->startedAt,
            'finishedAt' => $this->finishedAt,
            'cancelRequestedAt' => $this->cancelRequestedAt,
            'forced' => $this->forced,
            'error' => $this->error,
            'steps' => $this->steps,
        ];
    }

    /** Exit kód posledního kroku, který doběhl s kódem (zrušený krok kód nemá). */
    public function exitCode(): ?int
    {
        for ($i = count($this->steps) - 1; $i >= 0; $i--) {
            if ($this->steps[$i]['exitCode'] !== null) {
                return $this->steps[$i]['exitCode'];
            }
        }
        return null;
    }

    /** Běžící kroky → `cancelled`, čekající → `skipped` (konec úlohy bez dalších kroků). */
    public function closeOpenSteps(string $runningStatus, ?string $finishedAt): void
    {
        foreach ($this->steps as $i => $step) {
            if ($step['status'] === self::STEP_RUNNING) {
                $this->steps[$i]['status'] = $runningStatus;
                $this->steps[$i]['finishedAt'] = $finishedAt;
            } elseif ($step['status'] === self::STEP_PENDING) {
                $this->steps[$i]['status'] = self::STEP_SKIPPED;
            }
        }
    }
}
