<?php

declare(strict_types=1);

namespace Shipard\Core\DevUpdate;

/**
 * Kontrola zastaralosti checkoutu (#129 D16–D18): pod zámkem
 * `check.lock` fetch `stable` z pevné HTTPS URL do cílové reference
 * ({@see Upstream::targetRef()}), pak `behind` / `ahead`, nejstarší
 * chybějící commit, posledních COMMIT_LIMIT commitů a odkaz na porovnání.
 * Výsledek vždy zapíše do `state.json` ({@see UpdateStateStore}) — i při
 * selhání (`ok: false`, `errorCode` + `error` pro bublinu). Vrací null, když
 * kontrola už běží (zámek drží jiný proces).
 *
 * `$upstreamUrl` je jen testovací seam (lokální bare repozitář); nikdy
 * nepřichází z požadavku ani z konfigurace.
 */
final class UpdateChecker
{
    public const int COMMIT_LIMIT = 10;

    public const string ERROR_NOT_A_REPOSITORY = 'notARepository';
    public const string ERROR_TIMEOUT = 'timeout';
    public const string ERROR_FETCH_FAILED = 'fetchFailed';
    public const string ERROR_GIT_FAILED = 'gitFailed';

    public function __construct(
        private readonly Git $git,
        private readonly UpdateStateStore $store,
        private readonly string $upstreamUrl = Upstream::URL,
    ) {}

    /**
     * @return array<string, mixed>|null zapsaný stav; null = kontrola už běží
     * @throws \RuntimeException stav nejde zapsat
     */
    public function check(): ?array
    {
        $lock = $this->store->tryLock();
        if ($lock === null) {
            return null;
        }
        try {
            $state = $this->compute();
            $this->store->write($state);
            $this->store->clearRequested();
            return $state;
        } finally {
            $this->store->unlock($lock);
        }
    }

    /** @return array<string, mixed> */
    private function compute(): array
    {
        $checkedAt = UpdateStateStore::formatTime($this->store->now());
        $head = ['hash' => $this->git->headHash(), 'branch' => $this->git->branch()];
        $ref = Upstream::targetRef($this->git->originUrl());
        $base = [
            'ok' => false,
            'checkedAt' => $checkedAt,
            'errorCode' => null,
            'error' => null,
            'upstream' => ['url' => Upstream::URL, 'branch' => Upstream::BRANCH, 'ref' => $ref, 'hash' => null],
            'head' => $head,
            'behind' => null,
            'ahead' => null,
            'oldestMissingAt' => null,
            'commits' => [],
            'compareUrl' => $head['hash'] !== null ? Upstream::compareUrl($head['hash']) : null,
        ];
        if ($head['hash'] === null || !$this->git->isRepository()) {
            return self::failed($base, self::ERROR_NOT_A_REPOSITORY, 'Not a git checkout: ' . $this->git->root());
        }

        $fetch = $this->git->fetch($this->upstreamUrl, Upstream::refspec($ref));
        if ($fetch['timedOut']) {
            return self::failed($base, self::ERROR_TIMEOUT, sprintf('git fetch exceeded %d s', (int) UpdateEnvironment::FETCH_TIMEOUT_SECONDS));
        }
        if ($fetch['exitCode'] !== 0) {
            return self::failed($base, self::ERROR_FETCH_FAILED, self::firstLine($fetch['stderr']) ?? 'git fetch failed');
        }

        $upstreamHash = $this->git->refHash($ref);
        $behind = $this->git->revListCount('HEAD..' . $ref);
        $ahead = $this->git->revListCount($ref . '..HEAD');
        $commits = $this->git->log('HEAD..' . $ref, self::COMMIT_LIMIT);
        $dates = $this->git->commitDates('HEAD..' . $ref);
        if ($upstreamHash === null || $behind === null || $ahead === null || $commits === null || $dates === null) {
            return self::failed($base, self::ERROR_GIT_FAILED, 'git could not compare HEAD with ' . $ref);
        }

        $oldest = null;
        foreach ($dates as $date) {
            $ts = strtotime($date);
            if ($ts !== false && ($oldest === null || $ts < $oldest[0])) {
                $oldest = [$ts, $date];
            }
        }

        $base['ok'] = true;
        $base['upstream']['hash'] = $upstreamHash;
        $base['behind'] = $behind;
        $base['ahead'] = $ahead;
        $base['oldestMissingAt'] = $oldest[1] ?? null;
        $base['commits'] = $commits;
        return $base;
    }

    /**
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private static function failed(array $base, string $code, string $message): array
    {
        $base['ok'] = false;
        $base['errorCode'] = $code;
        $base['error'] = $message;
        return $base;
    }

    private static function firstLine(string $text): ?string
    {
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line !== '') {
                return $line;
            }
        }
        return null;
    }
}
