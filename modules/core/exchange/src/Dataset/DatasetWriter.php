<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset;

use Shipard\Core\Utils\Slug;

/**
 * Zápis datové sady do složky (+ volitelné zabalení do `.zip`).
 *
 * Determinismus: JSON s pevnými flagy a `\n` na konci, pořadí klíčů
 * přebírá z pole tak, jak ho exporter sestavil; názvy souborů
 * `NNNN-<slug>.jsonc`; zip přidává soubory v seřazeném pořadí.
 * Cílem je, aby dump → seed → dump dal byte-shodnou složku
 * (modulo `created` v manifestu).
 */
final class DatasetWriter
{
    private const JSON_FLAGS = JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_THROW_ON_ERROR;

    private function __construct(
        private readonly string $rootDir,
    ) {}

    /**
     * Připraví cílovou složku. Existující neprázdná složka je chyba, pokud
     * `$overwrite` nepovolí smazání předchozího obsahu sady (manifest a
     * známé sekce — cizí soubory se nemažou).
     */
    public static function create(string $dir, bool $overwrite = false): self
    {
        $root = rtrim($dir, '/');
        if ($root === '') {
            throw DatasetException::invalidPath($dir);
        }

        if (is_dir($root)) {
            $entries = array_filter(scandir($root) ?: [], static fn (string $e): bool => $e !== '.' && $e !== '..');
            if ($entries !== []) {
                if (!$overwrite) {
                    throw DatasetException::targetNotEmpty($root);
                }
                foreach (DatasetManifest::SECTIONS as $section) {
                    DatasetReader::removeTree($root . '/' . $section);
                }
                if (is_file($root . '/' . DatasetReader::MANIFEST_FILE)) {
                    unlink($root . '/' . DatasetReader::MANIFEST_FILE);
                }
            }
        } elseif (file_exists($root)) {
            throw DatasetException::invalidPath("{$dir} is not a directory");
        } elseif (!mkdir($root, 0755, true)) {
            throw new DatasetException("Cannot create dataset directory '{$root}'");
        }

        return new self($root);
    }

    public function getRootDir(): string
    {
        return $this->rootDir;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function writeJsonc(string $relPath, array $data): void
    {
        $this->writeRaw($relPath, self::encode($data));
    }

    public function writeManifest(DatasetManifest $manifest): void
    {
        $this->writeJsonc(DatasetReader::MANIFEST_FILE, $manifest->toArray());
    }

    public function copyFile(string $sourcePath, string $relPath): void
    {
        if (!is_file($sourcePath)) {
            throw DatasetException::notFound("source file '{$sourcePath}'");
        }
        $target = $this->targetPath($relPath);
        if (!copy($sourcePath, $target)) {
            throw new DatasetException("Cannot copy '{$sourcePath}' to '{$target}'");
        }
    }

    public function writeRaw(string $relPath, string $contents): void
    {
        $target = $this->targetPath($relPath);
        if (file_put_contents($target, $contents) === false) {
            throw new DatasetException("Cannot write '{$target}'");
        }
    }

    /**
     * Zabalí celou složku sady do zipu; záznamy v seřazeném pořadí,
     * relativně ke kořenu sady (manifest v rootu archivu).
     */
    public function zip(string $zipPath): void
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->rootDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($this->rootDir) + 1);
            }
        }
        sort($files, SORT_STRING);

        $zip = new \ZipArchive();
        $result = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($result !== true) {
            throw DatasetException::invalidArchive("cannot create '{$zipPath}' (code {$result})");
        }
        try {
            foreach ($files as $rel) {
                if (!$zip->addFile($this->rootDir . '/' . $rel, $rel)) {
                    throw DatasetException::invalidArchive("cannot add '{$rel}'");
                }
            }
        } finally {
            $zip->close();
        }
    }

    // ── statické pomocníky ──────────────────────────────────────────────────

    /**
     * Deterministické JSON (pretty, UTF-8 bez escapování, `\n` na konci).
     *
     * @param array<string, mixed>|\stdClass $data
     */
    public static function encode(array|\stdClass $data): string
    {
        return json_encode($data, self::JSON_FLAGS) . "\n";
    }

    /**
     * `0001-<slug>.jsonc`
     */
    public static function fileName(int $ordinal, string $slug, string $ext = '.jsonc'): string
    {
        if ($ordinal < 0) {
            throw new \InvalidArgumentException('ordinal must be >= 0');
        }
        $s = self::slug($slug);
        return sprintf('%04d-%s%s', $ordinal, $s, $ext);
    }

    /**
     * ASCII slug: transliterace diakritiky, lowercase, `[^a-z0-9]+` → `-`,
     * max 60 znaků, prázdný vstup → `record`.
     */
    public static function slug(string $text): string
    {
        return Slug::make($text);
    }

    private function targetPath(string $relPath): string
    {
        $rel = DatasetReader::normalizeRelative($relPath);
        if ($rel === '') {
            throw DatasetException::invalidPath($relPath);
        }
        $target = $this->rootDir . '/' . $rel;
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new DatasetException("Cannot create directory '{$dir}'");
        }
        return $target;
    }
}
