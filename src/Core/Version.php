<?php

declare(strict_types=1);

namespace Shipard\Core;

/**
 * Centrální verze aplikace. Semver bumpuje se ručně při milnících;
 * denní přesnost nese HEAD gitu doplněný za běhu — hash, větev a datum
 * commitu (#129 D9).
 */
final class Version
{
    public const VERSION = '0.1.1';

    /** @var array{hash: string, fullHash: string, branch: ?string, commitDate: string}|null */
    private static ?array $gitInfo = null;
    private static bool $gitInfoResolved = false;

    /** "0.1.1 (abc1234)"; bez dostupného gitu jen "0.1.1". */
    public static function full(): string
    {
        $hash = self::gitHash();
        return $hash === null ? self::VERSION : self::VERSION . ' (' . $hash . ')';
    }

    /** Short hash HEAD repozitáře; null bez gitu. Zkratka za `gitInfo()['hash']`. */
    public static function gitHash(): ?string
    {
        return self::gitInfo()['hash'] ?? null;
    }

    /**
     * HEAD repozitáře: `{hash, fullHash, branch, commitDate}` — krátký a plný
     * hash, větev (odpojená HEAD → null) a datum commitu ISO 8601. Null, když
     * git binárka nebo `.git` chybí, nebo když git odmítne — PHP-FPM běží pod
     * jiným uživatelem než vlastník checkoutu („dubious ownership“);
     * `safe.directory` se záměrně nenastavuje. Výsledek (včetně negativního)
     * se cachuje po dobu procesu.
     *
     * @return array{hash: string, fullHash: string, branch: ?string, commitDate: string}|null
     */
    public static function gitInfo(): ?array
    {
        if (!self::$gitInfoResolved) {
            self::$gitInfoResolved = true;
            self::$gitInfo = self::readGitInfo(dirname(__DIR__, 2));
        }

        return self::$gitInfo;
    }

    /**
     * Totéž bez cache nad zadaným adresářem: `git log -1` (hash, plný hash,
     * datum commitu) a `git rev-parse --abbrev-ref HEAD` (větev), parsování
     * v {@see parseGitInfo()}.
     *
     * @return array{hash: string, fullHash: string, branch: ?string, commitDate: string}|null
     */
    public static function readGitInfo(string $root): ?array
    {
        $log = self::git($root, 'log -1 --format=%h%x09%H%x09%cI');
        if ($log === null) {
            return null;
        }

        return self::parseGitInfo($log, self::git($root, 'rev-parse --abbrev-ref HEAD') ?? '');
    }

    /**
     * Čisté parsování výstupu gitu: `$logLine` = "<krátký hash>\t<plný hash>\t<datum ISO 8601>",
     * `$branchLine` = název větve, nebo `HEAD` u odpojené HEAD (→ `branch: null`).
     * Cokoli nevalidního → null.
     *
     * @return array{hash: string, fullHash: string, branch: ?string, commitDate: string}|null
     */
    public static function parseGitInfo(string $logLine, string $branchLine): ?array
    {
        $parts = explode("\t", trim($logLine));
        if (count($parts) !== 3) {
            return null;
        }
        [$hash, $fullHash, $commitDate] = array_map('trim', $parts);
        if (preg_match('/^[0-9a-f]{7,40}$/', $hash) !== 1
            || preg_match('/^[0-9a-f]{40}$/', $fullHash) !== 1
            || !str_starts_with($fullHash, $hash)
            || \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $commitDate) === false
        ) {
            return null;
        }
        $branch = trim($branchLine);

        return [
            'hash'       => $hash,
            'fullHash'   => $fullHash,
            'branch'     => $branch === '' || $branch === 'HEAD' ? null : $branch,
            'commitDate' => $commitDate,
        ];
    }

    /** První řádek výstupu gitu; null při nenulovém exit kódu nebo chybějící binárce. */
    private static function git(string $root, string $args): ?string
    {
        $lines    = [];
        $exitCode = 1;
        @exec('git -C ' . escapeshellarg($root) . ' ' . $args . ' 2>/dev/null', $lines, $exitCode);

        return $exitCode === 0 ? trim((string) ($lines[0] ?? '')) : null;
    }
}
