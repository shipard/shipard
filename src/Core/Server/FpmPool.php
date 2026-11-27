<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

/**
 * PHP-FPM pool `shipard` — socket, na který míří nginx. Jedno pravidlo pro
 * `doctor` i `dev-https` (#183 U6): hodnota `listen` z konfigurace poolu,
 * tedy totéž, co `install-packages.sh` dosazuje do site souboru.
 */
final class FpmPool
{
    public const string CONFIG_GLOB = '/etc/php/*/fpm/pool.d/shipard.conf';

    /** Cesta socketu z první nalezené konfigurace poolu; null = pool není. */
    public static function shipardSocket(string $configGlob = self::CONFIG_GLOB): ?string
    {
        foreach (glob($configGlob) ?: [] as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }
            if (preg_match('/^\s*listen\s*=\s*(\S+)/m', $content, $m)) {
                return $m[1];
            }
        }
        return null;
    }
}
