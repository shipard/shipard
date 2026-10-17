<?php

declare(strict_types=1);

namespace Shipard\Core\DevUpdate;

/**
 * Se čím se checkout porovnává (#129 D16, D17): vždy větev `stable`
 * veřejného repozitáře přes anonymní HTTPS — bez ohledu na `origin`
 * (SSH s heslem, cizí fork). URL a větev jsou napevno; jediný přepis je
 * testovací seam v {@see UpdateChecker} (lokální bare repozitář).
 *
 * Cílová reference fetche: ukazuje-li `origin` na `shipard/shipard`, plní se
 * `refs/remotes/origin/stable` (po aktualizaci `git status` hlásí „up to date
 * with origin/stable“); jinak `refs/shipard/upstream/stable`, aby se
 * nepřepsal tracking fork. Čisté funkce, testy v UpstreamTest.
 */
final class Upstream
{
    public const string URL = 'https://github.com/shipard/shipard.git';
    public const string BRANCH = 'stable';
    public const string COMPARE_URL = 'https://github.com/shipard/shipard/compare/';
    public const string COMMIT_URL = 'https://github.com/shipard/shipard/commit/';

    public const string REF_ORIGIN = 'refs/remotes/origin/' . self::BRANCH;
    public const string REF_FORK = 'refs/shipard/upstream/' . self::BRANCH;

    private function __construct()
    {
    }

    /** `https://github.com/shipard/shipard(.git)`, `git@github.com:shipard/shipard(.git)`, `ssh://git@github.com/shipard/shipard(.git)`. */
    public static function isCanonicalOrigin(?string $url): bool
    {
        if ($url === null) {
            return false;
        }
        return preg_match('#^(?:https://github\.com/|git@github\.com:|ssh://git@github\.com/)shipard/shipard(?:\.git)?/?$#i', trim($url)) === 1;
    }

    /** Reference, do které fetch zapíše `stable` upstreamu, podle fetch URL `origin` (null = bez origin). */
    public static function targetRef(?string $originUrl): string
    {
        return self::isCanonicalOrigin($originUrl) ? self::REF_ORIGIN : self::REF_FORK;
    }

    /** Refspec fetche: `+refs/heads/stable:<cílová reference>` — bez tagů, bez jiných větví. */
    public static function refspec(string $targetRef): string
    {
        return '+refs/heads/' . self::BRANCH . ':' . $targetRef;
    }

    /** Porovnání HEAD…stable na GitHubu pro bublinu („a M dalších“). */
    public static function compareUrl(string $headHash): string
    {
        return self::COMPARE_URL . $headHash . '...' . self::BRANCH;
    }
}
