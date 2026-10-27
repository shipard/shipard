<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Round;

use Shipard\Core\Ai\ServerAiConnection;
use Shipard\Core\DevJobs\JobStore;

/**
 * Pojistky před spuštěním kola (exit 2, nic se nespustí): jen režim
 * `development` (fail-closed — neurčitelný režim = odmítnutí) a adresář
 * úloh zapisovatelný a vlastněný uživatelem, pod kterým příkaz běží (N16:
 * `/opt/shipard/run` je owner-only, pool php-fpm běží pod vývojářem — kolo
 * založené pod `sudo` by dashboard nemohl zrušit). Odhad ceny evaluací
 * a strop řeší {@see RoundEstimate}.
 */
final class RoundPreflight
{
    private function __construct()
    {
    }

    public static function modeProblem(?string $serverMode): ?string
    {
        if ($serverMode === ServerAiConnection::MODE_DEVELOPMENT) {
            return null;
        }
        return 'Testovací kolo běží jen na serveru v režimu development (server.json: mode = ' . ($serverMode ?? 'nejde určit') . ')';
    }

    /** @param \Closure(string): (int|false) $owner test seam; výchozí `fileowner` */
    public static function jobsDirProblem(JobStore $jobs, int $euid, ?\Closure $owner = null): ?string
    {
        $dir = $jobs->jobsDir();
        if (!$jobs->ensureDir()) {
            return "Adresář úloh {$dir} nejde založit";
        }
        if (!is_writable($dir)) {
            return "Adresář úloh {$dir} nejde zapsat — spusť test-round pod uživatelem poolu php-fpm (shpd-server doctor)";
        }
        $owner ??= static fn (string $path): int|false => @fileowner($path);
        $uid = $owner($dir);
        if ($uid !== false && $uid !== $euid) {
            return "Adresář úloh {$dir} patří uživateli {$uid}, příkaz běží jako {$euid} — spusť test-round pod uživatelem poolu php-fpm, jinak dashboard kolo neuvidí ani nezruší (shpd-server doctor)";
        }
        return null;
    }
}
