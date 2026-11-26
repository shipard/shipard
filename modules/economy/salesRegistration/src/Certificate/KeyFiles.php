<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Certificate;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Server\FileOwnership;

/**
 * Dočasné soubory klíče a certifikátu pro jedno odeslání (#171 U1).
 *
 * Knihovna EET čte klíč jen ze souboru. Dešifrovaný klíč se proto zapíše do
 * privátního adresáře zdroje dat `tmp/` (adresář `0700`, soubory `0600` —
 * nikdy systémový `/tmp`), předá se volanému kódu a smaže ve `finally`,
 * i když volaný kód skončí výjimkou. Soubor žije jen po dobu načtení klíče.
 *
 * Kdyby proces skončil natvrdo (SIGKILL) mezi zápisem a smazáním, soubor
 * by zůstal — každé další použití proto nejdřív uklidí vlastní soubory
 * starší než {@see STALE_AFTER_SECONDS}.
 */
class KeyFiles
{
    public const string DIRECTORY = 'tmp';
    public const string PREFIX = 'salesreg-';
    public const int STALE_AFTER_SECONDS = 600;

    public function __construct(
        private readonly string $dataSourceDir,
        private readonly ?FileOwnership $ownership = null,
    ) {
    }

    public static function forConfig(DataSourceConfig $config): self
    {
        return new self($config->getDataSourceDir());
    }

    public function directory(): string
    {
        return rtrim($this->dataSourceDir, '/') . '/' . self::DIRECTORY;
    }

    /**
     * Zapíše klíč a certifikát, zavolá `$callback($keyPath, $certificatePath)`
     * a soubory smaže — vždy.
     *
     * @template T
     * @param callable(string, string): T $callback
     * @return T
     *
     * @throws \RuntimeException když adresář nebo soubor nejde bezpečně založit
     */
    public function with(
        #[\SensitiveParameter]
        string $keyPem,
        string $certificatePem,
        callable $callback,
    ): mixed {
        $dir = $this->ensureDirectory();
        $this->removeStale($dir);

        $base = $dir . '/' . self::PREFIX . bin2hex(random_bytes(12));
        $keyPath = $base . '.key';
        $certificatePath = $base . '.pem';
        try {
            $this->writePrivate($keyPath, $keyPem);
            $this->writePrivate($certificatePath, $certificatePem);
            return $callback($keyPath, $certificatePath);
        } finally {
            $this->remove($keyPath);
            $this->remove($certificatePath);
        }
    }

    private function ensureDirectory(): string
    {
        $dir = $this->directory();
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0700) && !is_dir($dir)) {
                throw new \RuntimeException("Cannot create private temporary directory {$dir}");
            }
            @chmod($dir, 0700);
            // Běh pod rootem (CLI, cron): adresář musí patřit zdroji dat,
            // jinak do něj PHP-FPM příště nezapíše.
            ($this->ownership ?? new FileOwnership())->matchTo($dir, FileOwnership::dataSourceReference($this->dataSourceDir));
        }
        if ((fileperms($dir) & 0077) !== 0) {
            throw new \RuntimeException("Private temporary directory {$dir} is accessible to group or others — run fix-permissions");
        }
        return $dir;
    }

    private function writePrivate(
        string $path,
        #[\SensitiveParameter]
        string $content,
    ): void {
        $previousUmask = umask(0077);
        try {
            // `x` = soubor nesmí existovat; s umask 0077 vzniká rovnou 0600.
            $handle = @fopen($path, 'x');
        } finally {
            umask($previousUmask);
        }
        if ($handle === false) {
            throw new \RuntimeException("Cannot create private temporary file {$path}");
        }
        try {
            @chmod($path, 0600);
            if (fwrite($handle, $content) !== strlen($content)) {
                throw new \RuntimeException("Cannot write private temporary file {$path}");
            }
        } finally {
            fclose($handle);
        }
    }

    private function remove(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Úklid po procesu, který skončil natvrdo — jen vlastní soubory, jen staré. */
    private function removeStale(string $dir): void
    {
        $limit = time() - self::STALE_AFTER_SECONDS;
        foreach (glob($dir . '/' . self::PREFIX . '*') ?: [] as $file) {
            $modified = @filemtime($file);
            if (is_file($file) && $modified !== false && $modified < $limit) {
                @unlink($file);
            }
        }
    }
}
