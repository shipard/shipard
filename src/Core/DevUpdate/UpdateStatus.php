<?php

declare(strict_types=1);

namespace Shipard\Core\DevUpdate;

use Shipard\Core\DevJobs\JobStore;

/**
 * Jeden pohled na aktualizace pro API i CLI (#129 D18–D20): stav z cache,
 * zda je neplatný, zda kontrola běží, aktuální HEAD a pojistky spočítané
 * teď. Nikdy nečeká na síť — fetch dělá jen {@see UpdateChecker}.
 */
final class UpdateStatus
{
    public function __construct(
        private readonly Git $git,
        private readonly UpdateStateStore $store,
        private readonly JobStore $jobs,
        private readonly string $toolPath = UpdateEnvironment::DEFAULT_PATH,
    ) {}

    public function git(): Git
    {
        return $this->git;
    }

    public function store(): UpdateStateStore
    {
        return $this->store;
    }

    public function jobs(): JobStore
    {
        return $this->jobs;
    }

    /**
     * @return array{state: ?array<string, mixed>, stale: bool, checking: bool, head: array{hash: ?string, branch: ?string}, blockers: list<array<string, mixed>>, canUpdate: bool}
     */
    public function snapshot(?string $ownJobId = null): array
    {
        $head = ['hash' => $this->git->headHash(), 'branch' => $this->git->branch()];
        $state = $this->store->read();
        $stale = $this->store->isStale($state, $head['hash']);
        $blockers = (new UpdateGuards($this->git, $this->jobs, $this->toolPath))->evaluate($state, $stale, $ownJobId, $head);
        return [
            'state' => $state,
            'stale' => $stale,
            'checking' => $this->store->isChecking(),
            'head' => $head,
            'blockers' => $blockers,
            'canUpdate' => $blockers === [],
        ];
    }
}
