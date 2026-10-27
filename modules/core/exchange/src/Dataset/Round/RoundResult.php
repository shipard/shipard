<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Round;

/**
 * Souhrn kola (D133) — `result.json` úlohy, který orchestrátor píše
 * průběžně po každé změně (N2: živá tabulka v dashboardu, poslední snímek
 * přežije i SIGKILL). Tabulka kroků vrstva / sada / scénář / krok → stav,
 * doba, id dílčí úlohy, důvod, počty testů a první selhání, u evaluací
 * soubor běhu, shoda polí a cena. Exit 0 jen při samých `ok`.
 */
final class RoundResult
{
    public const string FORMAT = 'shpd.testRound.result.v1';

    public const string STATUS_RUNNING = 'running';
    public const string STATUS_OK = 'ok';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_CANCELLED = 'cancelled';

    public const string STEP_RUNNING = 'running';
    public const string STEP_OK = 'ok';
    public const string STEP_FAILED = 'failed';
    public const string STEP_SKIPPED = 'skipped';
    public const string STEP_CANCELLED = 'cancelled';

    public const int EXIT_OK = 0;
    public const int EXIT_PROBLEMS = 1;
    public const int EXIT_CANCELLED = 130;

    /** @var list<array<string, mixed>> */
    private array $steps = [];
    /** @var list<array<string, mixed>> */
    private array $dataSources = [];
    private string $status = self::STATUS_RUNNING;
    private ?string $finishedAt = null;

    /**
     * @param array<string, mixed>                                  $selection {@see RoundSelection::toArray()}
     * @param array{commit: ?string, branch: ?string, dirty: ?bool} $git
     */
    public function __construct(
        private readonly array $selection,
        private readonly array $git,
        private readonly string $startedAt,
        private readonly ?string $jobId,
    ) {}

    public function addDataSource(string $role, string $dataset, string $id, string $name, bool $created, ?string $jobId = null): void
    {
        $this->dataSources[] = ['role' => $role, 'dataset' => $dataset, 'id' => $id, 'name' => $name, 'created' => $created, 'jobId' => $jobId];
    }

    /** Krok začal; vrací index pro {@see finishStep()}. */
    public function startStep(string $layer, string $step, string $label, ?string $dataset, ?string $scenario, string $at): int
    {
        $this->steps[] = [
            'layer' => $layer,
            'dataset' => $dataset,
            'scenario' => $scenario,
            'step' => $step,
            'label' => $label,
            'status' => self::STEP_RUNNING,
            'startedAt' => $at,
            'finishedAt' => null,
            'durationMs' => null,
            'jobId' => null,
            'exitCode' => null,
            'reason' => null,
            'counts' => null,
            'firstFailure' => null,
            'eval' => null,
        ];
        return count($this->steps) - 1;
    }

    /** @param array<string, mixed> $extra `durationMs`, `jobId`, `exitCode`, `reason`, `counts`, `firstFailure`, `eval` */
    public function finishStep(int $index, string $status, string $at, array $extra = []): void
    {
        if (!isset($this->steps[$index])) {
            throw new \LogicException("No step #{$index}");
        }
        $this->steps[$index]['status'] = $status;
        $this->steps[$index]['finishedAt'] = $at;
        foreach (['durationMs', 'jobId', 'exitCode', 'reason', 'counts', 'firstFailure', 'eval'] as $key) {
            if (array_key_exists($key, $extra)) {
                $this->steps[$index][$key] = $extra[$key];
            }
        }
    }

    /** Krok, který neběžel (`skipped` / `failed` bez spuštění / `cancelled` před startem). */
    public function recordStep(string $layer, string $step, string $label, ?string $dataset, ?string $scenario, string $status, string $at, ?string $reason = null, ?string $jobId = null): void
    {
        $index = $this->startStep($layer, $step, $label, $dataset, $scenario, $at);
        $this->finishStep($index, $status, $at, ['durationMs' => 0, 'reason' => $reason, 'jobId' => $jobId]);
    }

    /** @return array<string, mixed>|null */
    public function step(int $index): ?array
    {
        return $this->steps[$index] ?? null;
    }

    /** Součet ceny dosud hotových evaluací (rozpočet dalšího `eval`, N10). */
    public function evalSpentUsd(): float
    {
        $spent = 0.0;
        foreach ($this->steps as $step) {
            $cost = $step['eval']['costUsd'] ?? null;
            if (is_numeric($cost)) {
                $spent += (float) $cost;
            }
        }
        return $spent;
    }

    public function finish(string $at, bool $cancelled): void
    {
        $this->finishedAt = $at;
        $summary = $this->summary();
        $this->status = $cancelled ? self::STATUS_CANCELLED : ($summary['failed'] + $summary['skipped'] + $summary['cancelled'] === 0 ? self::STATUS_OK : self::STATUS_FAILED);
    }

    public function status(): string
    {
        return $this->status;
    }

    /** @return array{ok: int, failed: int, skipped: int, cancelled: int, running: int} */
    public function summary(): array
    {
        $summary = [self::STEP_OK => 0, self::STEP_FAILED => 0, self::STEP_SKIPPED => 0, self::STEP_CANCELLED => 0, self::STEP_RUNNING => 0];
        foreach ($this->steps as $step) {
            $summary[$step['status']] = ($summary[$step['status']] ?? 0) + 1;
        }
        return $summary;
    }

    public function exitCode(): int
    {
        return match ($this->status) {
            self::STATUS_OK => self::EXIT_OK,
            self::STATUS_CANCELLED => self::EXIT_CANCELLED,
            default => self::EXIT_PROBLEMS,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'status' => $this->status,
            'exitCode' => $this->status === self::STATUS_RUNNING ? null : $this->exitCode(),
            'jobId' => $this->jobId,
            'git' => $this->git,
            'startedAt' => $this->startedAt,
            'finishedAt' => $this->finishedAt,
            'selection' => $this->selection,
            'dataSources' => $this->dataSources,
            'steps' => $this->steps,
            'summary' => $this->summary(),
        ];
    }
}
