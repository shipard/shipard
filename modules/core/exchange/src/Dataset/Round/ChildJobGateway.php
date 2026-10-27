<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Round;

use Shipard\Core\DevJobs\JobConflict;
use Shipard\Core\DevJobs\JobLauncher;
use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobStore;

/**
 * Dílčí úlohy kola z pohledu orchestrátoru: spustit ({@see JobLauncher}),
 * sledovat (`JobStore::viewOf`), zrušit a přečíst `result.json`. Čtyři
 * closure = testovací seam bez souborů a procesů.
 */
final class ChildJobGateway
{
    /**
     * @param \Closure(JobRecord): array<string, mixed>  $launch  vrací pohled; vyhazuje {@see JobConflict} / \RuntimeException
     * @param \Closure(string): (array<string, mixed>|null) $view
     * @param \Closure(string): void                    $cancel
     * @param \Closure(string): (array<string, mixed>|null) $result
     */
    public function __construct(
        private readonly \Closure $launch,
        private readonly \Closure $view,
        private readonly \Closure $cancel,
        private readonly \Closure $result,
    ) {}

    public static function real(JobStore $store, JobLauncher $launcher): self
    {
        return new self(
            static fn (JobRecord $record): array => $launcher->launch($record),
            static fn (string $id): ?array => $store->viewOf($id),
            static function (string $id) use ($launcher): void {
                $launcher->cancel($id);
            },
            static fn (string $id): ?array => $store->result($id),
        );
    }

    /** @return array<string, mixed> @throws JobConflict @throws \RuntimeException */
    public function launch(JobRecord $record): array
    {
        return ($this->launch)($record);
    }

    /** @return array<string, mixed>|null */
    public function view(string $id): ?array
    {
        return ($this->view)($id);
    }

    public function cancel(string $id): void
    {
        ($this->cancel)($id);
    }

    /** @return array<string, mixed>|null */
    public function result(string $id): ?array
    {
        return ($this->result)($id);
    }
}
