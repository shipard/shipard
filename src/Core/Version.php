<?php

declare(strict_types=1);

namespace Shipard\Core;

use Shipard\Core\DevUpdate\Git;

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
     * hash, větev (odpojená HEAD → null) a datum commitu ISO 8601. Git běží
     * přes {@see Git}: argv bez shellu, binárka z pevného `PATH`
     * ({@see \Shipard\Core\DevUpdate\UpdateEnvironment}), ne z prostředí
     * procesu — proto funguje i pod PHP-FPM s `clear_env`, např. v úloze
     * dashboardu, která točí video (#103 D135). Null, když git binárka nebo
     * `.git` chybí, nebo když git odmítne — PHP-FPM běží pod jiným uživatelem
     * než vlastník checkoutu („dubious ownership“); `safe.directory` se
     * záměrně nenastavuje. Výsledek (včetně negativního) se cachuje po dobu
     * procesu.
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
     * Totéž bez cache nad zadaným adresářem; `$git` je vstřik pro testy
     * (výchozí {@see Git} nad `$root` s pevným prostředím). Čtyři dotazy:
     * plný hash, krátký hash, datum commitu a větev. Cokoli nevalidního —
     * krátký hash není prefixem plného, datum mimo ISO 8601, chybějící
     * hodnota — dává null; selhání samotné větve (odpojená HEAD) jen
     * `branch: null`.
     *
     * @return array{hash: string, fullHash: string, branch: ?string, commitDate: string}|null
     */
    public static function readGitInfo(string $root, ?Git $git = null): ?array
    {
        $git ??= new Git($root);
        $fullHash = $git->headHash();
        if ($fullHash === null) {
            return null;
        }
        $hash = $git->shortHash();
        $commitDate = $git->commitDate();
        if ($hash === null || $commitDate === null || !str_starts_with($fullHash, $hash)) {
            return null;
        }

        return [
            'hash'       => $hash,
            'fullHash'   => $fullHash,
            'branch'     => $git->branch(),
            'commitDate' => $commitDate,
        ];
    }
}
