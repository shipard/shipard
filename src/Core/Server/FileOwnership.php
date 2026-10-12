<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

/**
 * Přenese vlastníka a skupinu vzorového souboru nebo adresáře na jiný
 * soubor (`tasks/ds-state-file-ownership.md`, #56, #115).
 *
 * Zápisy do adresáře zdroje dat běží i pod rootem (`sudo shpd-ds ds-state`,
 * `shpd-server ds-cron`, `cron-install`). Soubor založený rootem v adresáři
 * `0750` bez setgid patří `root:root` a PHP-FPM ani dispatcher periodických
 * úloh (shipard-user) ho nepřečtou. Zapisovatel proto před `rename` srovná
 * vlastníka dočasného souboru podle souboru, který zdroji dat prokazatelně
 * patří (`config/main.json`).
 *
 * Mění jen to, co se liší — běžný běh pod shipard-userem `chown` nevolá.
 * Selhání je výjimka, nikdy tichý úspěch: soubor, který by zdroj dat
 * zavřel, se nesmí zapsat potichu. Chráněné metody jsou šev pro testy bez
 * roota (vzor `CronInstallCommand::applyOwnership`).
 */
class FileOwnership
{
    /**
     * Nastaví vlastníka a skupinu `$path` podle `$reference`, jen když se liší.
     *
     * @throws \RuntimeException když vzor nebo cíl nejde přečíst, nebo
     *   `chown` / `chgrp` selže (typicky běh pod jiným uživatelem než root)
     */
    public function matchTo(string $path, string $reference): void
    {
        $want = $this->ownerOf($reference);
        if ($want === null) {
            throw new \RuntimeException("Cannot determine owner of {$reference} to apply it to {$path}");
        }
        $have = $this->ownerOf($path);
        if ($have === null) {
            throw new \RuntimeException("Cannot determine owner of {$path}");
        }

        if ($have['uid'] !== $want['uid'] && !$this->chown($path, $want['uid'])) {
            throw new \RuntimeException($this->failure('owner', $path, $have, $want));
        }
        if ($have['gid'] !== $want['gid'] && !$this->chgrp($path, $want['gid'])) {
            throw new \RuntimeException($this->failure('group', $path, $have, $want));
        }
    }

    /**
     * @return array{uid: int, gid: int}|null null = soubor neexistuje nebo stat selhal
     */
    protected function ownerOf(string $path): ?array
    {
        clearstatcache(false, $path);
        $stat = @stat($path);
        if ($stat === false) {
            return null;
        }
        return ['uid' => (int) $stat['uid'], 'gid' => (int) $stat['gid']];
    }

    protected function chown(string $path, int $uid): bool
    {
        return @chown($path, $uid);
    }

    protected function chgrp(string $path, int $gid): bool
    {
        return @chgrp($path, $gid);
    }

    /**
     * @param array{uid: int, gid: int} $have
     * @param array{uid: int, gid: int} $want
     */
    private function failure(string $what, string $path, array $have, array $want): string
    {
        return sprintf(
            'Failed to set %s of %s to %s (file is %s, running as %s). Fix: chown %s %s',
            $what,
            $path,
            self::ownerSpec($want['uid'], $want['gid']),
            self::ownerSpec($have['uid'], $have['gid']),
            self::currentUser(),
            self::ownerSpec($want['uid'], $want['gid']),
            $path,
        );
    }

    /** `user:group` pro chown, při neznámém id číselně. */
    public static function ownerSpec(int $uid, int $gid): string
    {
        $user = function_exists('posix_getpwuid') ? (posix_getpwuid($uid)['name'] ?? null) : null;
        $group = function_exists('posix_getgrgid') ? (posix_getgrgid($gid)['name'] ?? null) : null;
        return ($user ?? (string) $uid) . ':' . ($group ?? (string) $gid);
    }

    private static function currentUser(): string
    {
        if (!function_exists('posix_geteuid')) {
            return 'unknown';
        }
        $uid = posix_geteuid();
        return posix_getpwuid($uid)['name'] ?? (string) $uid;
    }
}
