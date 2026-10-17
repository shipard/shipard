<?php

declare(strict_types=1);

namespace Shipard\Core\DevUpdate;

use Shipard\Core\DevJobs\JobStore;

/**
 * Pojistky aktualizace (#129 D20, U4–U6) — počítají se při každém dotazu,
 * nikdy z cache: stav pracovního stromu a úloh se mění. Každá vrací kód
 * a parametry pro radu v bublině / v terminálu:
 *
 * | kód              | kdy                                                  |
 * |------------------|------------------------------------------------------|
 * | `notStable`      | aktuální větev není `stable` (`{branch}`)            |
 * | `dirty`          | necommitnuté změny sledovaných souborů               |
 * | `notFastForward` | lokální commity, které upstream nemá (`{ahead}`)      |
 * | `jobRunning`     | běží jakákoli úloha dashboardu (`{jobId, type}`)      |
 * | `missingTool`    | chybí git / composer / npm v PATH úlohy (`{tools}`)   |
 * | `notChecked`     | stav neznámý, selhal nebo neplatný (`{reason}`)       |
 *
 * `$ownJobId` = úloha `update`, která pojistky volá sama (`dev-update
 * --job`), aby se nepovažovala za cizí běžící úlohu.
 */
final class UpdateGuards
{
    public const string NOT_STABLE = 'notStable';
    public const string DIRTY = 'dirty';
    public const string NOT_FAST_FORWARD = 'notFastForward';
    public const string JOB_RUNNING = 'jobRunning';
    public const string MISSING_TOOL = 'missingTool';
    public const string NOT_CHECKED = 'notChecked';

    public function __construct(
        private readonly Git $git,
        private readonly JobStore $jobs,
        private readonly string $toolPath = UpdateEnvironment::DEFAULT_PATH,
    ) {}

    /**
     * @param array<string, mixed>|null $state stav z cache
     * @param bool $stale {@see UpdateStateStore::isStale()}
     * @param array{hash: ?string, branch: ?string}|null $head aktuální HEAD (ušetří dotaz na git)
     * @return list<array<string, mixed>> prázdné = aktualizace smí proběhnout
     */
    public function evaluate(?array $state, bool $stale, ?string $ownJobId = null, ?array $head = null): array
    {
        $blockers = [];

        $branch = $head !== null ? $head['branch'] : $this->git->branch();
        if ($branch !== Upstream::BRANCH) {
            $blockers[] = ['code' => self::NOT_STABLE, 'branch' => $branch];
        }

        // null = git selhal → fail-closed, pracovní strom neznáme.
        if ($this->git->isDirty() !== false) {
            $blockers[] = ['code' => self::DIRTY];
        }

        if (!$stale && $state !== null && (int) ($state['ahead'] ?? 0) > 0) {
            $blockers[] = ['code' => self::NOT_FAST_FORWARD, 'ahead' => (int) $state['ahead']];
        }

        foreach ($this->jobs->activeJobs() as $job) {
            if ($ownJobId !== null && (string) $job['id'] === $ownJobId) {
                continue;
            }
            $blockers[] = ['code' => self::JOB_RUNNING, 'jobId' => (string) $job['id'], 'type' => (string) $job['type']];
            break;
        }

        $missing = UpdateEnvironment::missingTools($this->toolPath);
        if ($missing !== []) {
            $blockers[] = ['code' => self::MISSING_TOOL, 'tools' => $missing];
        }

        if ($stale) {
            $blockers[] = ['code' => self::NOT_CHECKED, 'reason' => self::staleReason($state)];
        }

        return $blockers;
    }

    /** Rada k pojistce pro terminál (anglicky jako ostatní CLI); bublina má vlastní texty ve slovníku. @param array<string, mixed> $blocker */
    public static function advice(array $blocker): string
    {
        return match ($blocker['code'] ?? '') {
            self::NOT_STABLE => sprintf(
                "The checkout is on branch '%s', not '%s' — switch to %s (git switch %s) or update by hand.",
                (string) ($blocker['branch'] ?? '(detached HEAD)'),
                Upstream::BRANCH,
                Upstream::BRANCH,
                Upstream::BRANCH,
            ),
            self::DIRTY => 'Uncommitted changes in tracked files — commit or stash them (git stash) first.',
            self::NOT_FAST_FORWARD => sprintf(
                '%d local commit(s) that %s does not have — push them or reconcile by hand (git pull --rebase).',
                (int) ($blocker['ahead'] ?? 0),
                Upstream::BRANCH,
            ),
            self::JOB_RUNNING => sprintf(
                'Dashboard job %s (%s) is running — wait for it to finish.',
                (string) ($blocker['jobId'] ?? '?'),
                (string) ($blocker['type'] ?? '?'),
            ),
            self::MISSING_TOOL => sprintf(
                'Missing tool(s) in PATH: %s — install them (scripts/install-packages.sh).',
                implode(', ', (array) ($blocker['tools'] ?? [])),
            ),
            self::NOT_CHECKED => sprintf(
                'The GitHub check did not succeed (%s) — run shpd-server dev-update-check again.',
                (string) ($blocker['reason'] ?? 'unknown'),
            ),
            default => 'Update blocked: ' . (string) ($blocker['code'] ?? '?'),
        };
    }

    /** @param array<string, mixed>|null $state */
    private static function staleReason(?array $state): string
    {
        if ($state === null) {
            return 'missing';
        }
        if (($state['ok'] ?? false) !== true) {
            return 'failed';
        }
        return 'outdated';
    }
}
