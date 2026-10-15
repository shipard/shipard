<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

use SensitiveParameter;
use Shipard\Core\Ai\Exception\DevAiKeyException;
use Shipard\Core\Security\SecretsFileWriter;

/**
 * Serverový API klíč pro vývojové zdroje dat (tasks/testing-phase4.md D40):
 * `<configDir>/secrets/dev-ai-<platforma>.key` (0600) vedle
 * `/etc/shipard/server.json` — nikdy v DB ani v `server.json`. Plní CLI
 * `shpd-server dev-ai-key --set-key`, čte {@see ServerAiConnection}.
 * Stejné zacházení jako klíč organizace AI gateway ({@see \Shipard\Core\Hosting\AiGwKeyStore}).
 */
final class DevAiKeyStore
{
    public const string SECRETS_DIR = 'secrets';
    public const string FILE_PREFIX = 'dev-ai-';
    public const string FILE_SUFFIX = '.key';

    /** Vlastník nově zapsaného klíče se řídí tímto adresářem (shipard uživatel v obou režimech). */
    public const string OWNER_REFERENCE = '/opt/shipard';

    private function __construct()
    {
    }

    public static function secretsDir(string $configDir): string
    {
        return rtrim($configDir, '/') . '/' . self::SECRETS_DIR;
    }

    public static function fileName(string $platform): string
    {
        return self::FILE_PREFIX . $platform . self::FILE_SUFFIX;
    }

    public static function keyFilePath(string $configDir, string $platform): string
    {
        return self::secretsDir($configDir) . '/' . self::fileName($platform);
    }

    public static function exists(string $configDir, string $platform): bool
    {
        return is_file(self::keyFilePath($configDir, $platform));
    }

    /**
     * Klíč ze souboru — ověří práva 0600 a čitelnost; prázdný soubor je chyba.
     *
     * @throws DevAiKeyException
     */
    public static function read(string $configDir, string $platform): string
    {
        $file = self::keyFilePath($configDir, $platform);
        if (!is_file($file)) {
            throw new DevAiKeyException(
                DevAiKeyException::MISSING,
                "Server AI key missing at {$file}. Run 'sudo shpd-server dev-ai-key --set-key'.",
            );
        }
        clearstatcache(false, $file);
        $perms = fileperms($file) & 0777;
        if ($perms !== 0600) {
            throw new DevAiKeyException(
                DevAiKeyException::INSECURE,
                sprintf('Server AI key at %s has insecure permissions %04o (must be 0600). Fix: chmod 0600 %s', $file, $perms, $file),
            );
        }
        $key = @file_get_contents($file);
        if ($key === false) {
            throw new DevAiKeyException(
                DevAiKeyException::UNREADABLE,
                "Server AI key at {$file} cannot be read (owner " . self::ownerName($file) . ', running as '
                . self::processUser() . "). Fix: chown <shipard-user> {$file}",
            );
        }
        $key = trim($key);
        if ($key === '') {
            throw new DevAiKeyException(
                DevAiKeyException::UNREADABLE,
                "Server AI key at {$file} is empty. Re-run 'sudo shpd-server dev-ai-key --set-key'.",
            );
        }
        return $key;
    }

    /**
     * Atomický zápis (adresář 0700, soubor 0600) se srovnáním vlastníka
     * podle `$ownerReference` (výchozí {@see OWNER_REFERENCE}; bez něj
     * `$configDir`). Přepsání = rotace. Vrací varování o vlastníkovi.
     *
     * @return list<string>
     * @throws \RuntimeException
     */
    public static function write(
        string $configDir,
        string $platform,
        #[SensitiveParameter] string $key,
        ?string $ownerReference = null,
    ): array {
        $key = trim($key);
        if ($key === '') {
            throw new \InvalidArgumentException('DevAiKeyStore: key must not be empty.');
        }
        $ownerReference ??= is_dir(self::OWNER_REFERENCE) ? self::OWNER_REFERENCE : $configDir;
        return SecretsFileWriter::writeTo(self::secretsDir($configDir), self::fileName($platform), $key, $ownerReference);
    }

    private static function ownerName(string $file): string
    {
        $uid = @fileowner($file);
        if ($uid === false) {
            return 'unknown';
        }
        return function_exists('posix_getpwuid') ? (posix_getpwuid($uid)['name'] ?? (string) $uid) : (string) $uid;
    }

    private static function processUser(): string
    {
        if (!function_exists('posix_geteuid')) {
            return 'unknown';
        }
        $uid = posix_geteuid();
        return posix_getpwuid($uid)['name'] ?? (string) $uid;
    }
}
