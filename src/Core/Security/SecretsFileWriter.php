<?php

declare(strict_types=1);

namespace Shipard\Core\Security;

use SensitiveParameter;
use Shipard\Core\Server\FileOwnership;

/**
 * Atomický zápis souboru do {ds_path}/secrets/ se správným vlastníkem.
 *
 * Jediné místo, kterým se secrets soubory zapisují (secrets.key,
 * oidc-op.key, ai-gw-anthropic.key; serverový dev-ai-<platforma>.key přes
 * {@see writeTo()}). Zápis: adresář 0700, tmp soubor 0600,
 * fwrite + fflush + fsync, rename do místa.
 *
 * Vlastnictví: CLI commandy typicky běží pod rootem, runtime (PHP-FPM) pod
 * vlastníkem DS adresáře — soubor zapsaný rootem by runtime nepřečetl
 * (viz tasks/hosting-fix-secrets-owner-and-gw-errors.md). Cílový vlastník
 * = vlastník DS root adresáře: běží-li proces jako root, soubor i secrets/
 * se na něj chownují; jinak se při nesouladu vrátí warning s přesným
 * chown příkazem. Warningy zobrazuje volající command.
 */
final class SecretsFileWriter
{
    private function __construct()
    {
    }

    /**
     * Write $content to {dsPath}/secrets/{filename} (0600, atomic) and align
     * ownership with the DS root directory. Returns human-readable warnings
     * (empty list = fully aligned).
     *
     * @return list<string>
     * @throws \RuntimeException on any write failure
     */
    public static function write(
        string $dsPath,
        string $filename,
        #[SensitiveParameter] string $content,
    ): array {
        return self::writeTo(DsSecretCipher::secretsDirPath($dsPath), $filename, $content, $dsPath);
    }

    /**
     * Totéž do libovolného adresáře secrets (serverový `/etc/shipard/secrets`,
     * tasks/testing-phase4.md D40): vlastník podle `$ownerReference`
     * (soubor nebo adresář, který patří cílovému uživateli).
     *
     * @return list<string>
     * @throws \RuntimeException on any write failure
     */
    public static function writeTo(
        string $secretsDir,
        string $filename,
        #[SensitiveParameter] string $content,
        string $ownerReference,
    ): array {
        $targetFile = $secretsDir . '/' . $filename;
        $tmpFile = $targetFile . '.tmp';

        if (!is_dir($secretsDir)) {
            if (!@mkdir($secretsDir, 0700, true) && !is_dir($secretsDir)) {
                throw new \RuntimeException("Failed to create {$secretsDir}");
            }
        }
        @chmod($secretsDir, 0700);

        if (is_file($tmpFile)) {
            @unlink($tmpFile);
        }

        $fp = @fopen($tmpFile, 'wb');
        if ($fp === false) {
            throw new \RuntimeException("Failed to open {$tmpFile} for writing");
        }
        @chmod($tmpFile, 0600);

        try {
            $written = fwrite($fp, $content);
            if ($written !== strlen($content)) {
                throw new \RuntimeException(sprintf(
                    'Short write to %s (%d/%d bytes)',
                    $tmpFile, (int) $written, strlen($content),
                ));
            }
            if (!fflush($fp)) {
                throw new \RuntimeException("fflush failed on {$tmpFile}");
            }
            if (!fsync($fp)) {
                throw new \RuntimeException("fsync failed on {$tmpFile}");
            }
        } finally {
            fclose($fp);
        }

        if (!@rename($tmpFile, $targetFile)) {
            @unlink($tmpFile);
            throw new \RuntimeException("Failed to rename {$tmpFile} to {$targetFile}");
        }
        @chmod($targetFile, 0600);

        return self::alignOwnership($ownerReference, [$secretsDir, $targetFile]);
    }

    /**
     * Align (root) or report (non-root) ownership of $paths against the
     * owner of `$reference` (DS root directory, or `/opt/shipard` for
     * server-level secrets).
     *
     * @param list<string> $paths
     * @return list<string>
     */
    public static function alignOwnership(string $reference, array $paths): array
    {
        $dsPath = $reference;
        $dsUid = @fileowner($dsPath);
        $dsGid = @filegroup($dsPath);
        if ($dsUid === false || $dsGid === false) {
            return ["Cannot determine owner of {$dsPath} — verify secrets file ownership manually."];
        }

        $isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
        $warnings = [];

        foreach ($paths as $path) {
            clearstatcache(false, $path);
            $uid = @fileowner($path);
            $gid = @filegroup($path);
            if ($uid === $dsUid && $gid === $dsGid) {
                continue;
            }

            if ($isRoot) {
                if (@chown($path, $dsUid) && @chgrp($path, $dsGid)) {
                    continue;
                }
                $warnings[] = sprintf(
                    'Failed to chown %s to %s — fix manually: chown %s %s',
                    $path, self::ownerSpec($dsUid, $dsGid), self::ownerSpec($dsUid, $dsGid), $path,
                );
                continue;
            }

            $warnings[] = sprintf(
                '%s is owned by %s but the data source runs as %s — the runtime cannot read it. Fix: sudo chown %s %s',
                $path,
                self::ownerSpec($uid === false ? -1 : $uid, $gid === false ? -1 : $gid),
                self::ownerSpec($dsUid, $dsGid),
                self::ownerSpec($dsUid, $dsGid),
                $path,
            );
        }

        return $warnings;
    }

    /** Formats "user:group" for chown, falling back to numeric ids. */
    private static function ownerSpec(int $uid, int $gid): string
    {
        return FileOwnership::ownerSpec($uid, $gid);
    }
}
