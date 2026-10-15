<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

/**
 * Read-modify-write `config/main.json` zdroje dat (atomicky tmp + rename,
 * chmod 0600, vlastník podle adresáře `config/`). Čistou transformaci dělá
 * volající ({@see MainConfigPatcher}); tady jen soubor.
 *
 * Ruční běh pod rootem (`sudo shpd-server hosting-sync`, `sudo shpd-ds
 * dataset-seed`) by jinak nechal `main.json` jako `root:root` a PHP-FPM ani
 * cron by zdroj dat nepřečetly. Vzorem vlastníka je adresář `config/`, ne
 * přepisovaný `main.json` (ten může nést špatného vlastníka z dřívějšího
 * běhu a chybu by jen přenesl dál); `FileOwnership::dataSourceReference()`
 * se tu proto nepoužije. Selhání = výjimka, původní soubor zůstává
 * (tasks/hosting-sync-main-json-owner.md).
 */
final class MainConfigFile
{
    private function __construct()
    {
    }

    public static function path(string $dsDir): string
    {
        return rtrim($dsDir, '/') . '/config/main.json';
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $patch
     * @throws \RuntimeException
     */
    public static function patch(string $dsDir, callable $patch, ?FileOwnership $ownership = null): void
    {
        $file = self::path($dsDir);
        $content = @file_get_contents($file);
        if ($content === false) {
            throw new \RuntimeException("cannot read {$file}");
        }
        $config = json_decode($content, true);
        if (!is_array($config)) {
            throw new \RuntimeException("invalid JSON in {$file}");
        }

        $config = $patch($config);

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException("cannot write {$tmp}");
        }
        @chmod($tmp, 0600);
        try {
            ($ownership ?? new FileOwnership())->matchTo($tmp, dirname($file));
        } catch (\RuntimeException $e) {
            @unlink($tmp);
            throw $e;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("cannot replace {$file}");
        }
    }
}
