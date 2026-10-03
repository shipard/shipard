<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Attachments;

use Shipard\Core\Server\PermissionSpec;

/**
 * Low-level file operations for attachment storage.
 *
 * Handles file sanitization, path building, storing files on disk,
 * and computing checksums.
 */
class FileStorage
{
    private const HASH_LENGTH = 5;
    private const HASH_CHARSET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    /**
     * Store an uploaded file into the attachment directory structure.
     *
     * @param string $dsPath     Data source directory (e.g. /opt/shipard/data-sources/{ds-id})
     * @param string $tableName  Target table name (e.g. base_persons_persons)
     * @param string $originalName  Original filename from the upload
     * @param string $tmpPath    Path to the temporary uploaded file
     * @return FileInfo  Information about the stored file
     */
    public function store(string $dsPath, string $tableName, string $originalName, string $tmpPath): FileInfo
    {
        [$relativeDir, $fullDir] = $this->buildTargetDir($dsPath, $tableName);

        // Sanitize and generate unique filename
        $sanitized = $this->sanitizeFileName($originalName);
        $fileName = $this->buildHashedFileName($sanitized);

        // Compute checksum before moving
        $checksum = hash_file('sha256', $tmpPath);

        // Move file to target location
        $targetPath = $fullDir . '/' . $fileName;
        if (!rename($tmpPath, $targetPath)) {
            // rename may fail across filesystems — fall back to copy+delete
            if (!copy($tmpPath, $targetPath)) {
                throw new \RuntimeException("Failed to store file: {$targetPath}");
            }
            unlink($tmpPath);
        }

        // Get file size after storing
        $fileSize = filesize($targetPath);

        return new FileInfo(
            filePath: $relativeDir,
            fileName: $fileName,
            fileSize: (int) $fileSize,
            checksum: $checksum,
        );
    }

    /**
     * Copy an already-stored attachment file to the target table's directory.
     *
     * Unlike store(), the source file stays untouched (D8 — kopie, ne přesun).
     * The new filename gets a fresh 5-char hash; the previous hash suffix
     * (`base-abcde.ext`) is stripped first so hashes do not accumulate.
     *
     * @param string $dsPath          Data source directory
     * @param string $tableName       Target table name (e.g. base_registry_documents)
     * @param string $sourceFullPath  Absolute path to the existing stored file
     * @param string $sourceFileName  Stored filename of the source (with its hash)
     */
    public function copy(string $dsPath, string $tableName, string $sourceFullPath, string $sourceFileName): FileInfo
    {
        if (!is_file($sourceFullPath)) {
            throw new \RuntimeException("Source attachment file not found: {$sourceFullPath}");
        }

        [$relativeDir, $fullDir] = $this->buildTargetDir($dsPath, $tableName);

        $fileName = $this->buildHashedFileName($this->stripHashSuffix($sourceFileName));

        $checksum = hash_file('sha256', $sourceFullPath);

        $targetPath = $fullDir . '/' . $fileName;
        if (!copy($sourceFullPath, $targetPath)) {
            throw new \RuntimeException("Failed to copy file: {$sourceFullPath} → {$targetPath}");
        }

        return new FileInfo(
            filePath: $relativeDir,
            fileName: $fileName,
            fileSize: (int) filesize($targetPath),
            checksum: $checksum,
        );
    }

    /**
     * Build (and create) the dated target directory for a table.
     *
     * @return array{0: string, 1: string}  [relativeDir, fullDir]
     */
    private function buildTargetDir(string $dsPath, string $tableName): array
    {
        $now = new \DateTimeImmutable();

        // Build relative directory path: YYYY/MM/DD/table_name
        $relativeDir = sprintf(
            '%s/%s/%s/%s',
            $now->format('Y'),
            $now->format('m'),
            $now->format('d'),
            $tableName,
        );

        // Full directory on disk
        $fullDir = $dsPath . '/att/' . $relativeDir;
        if (!PermissionSpec::ensureDsDir($fullDir)) {
            throw new \RuntimeException(
                "Cannot create attachment directory: {$fullDir}. "
                . "Check that the 'att/' directory exists in the data source and is writable by the web server. "
                . "Run 'shpd-ds ds-upgrade' to create it."
            );
        }

        return [$relativeDir, $fullDir];
    }

    /** Append a fresh `-hash` before the extension: `base.ext` → `base-abcde.ext`. */
    private function buildHashedFileName(string $sanitized): string
    {
        $ext = $this->extractExtension($sanitized);
        $base = $ext !== '' ? substr($sanitized, 0, -(strlen($ext) + 1)) : $sanitized;
        $hash = $this->generateHash();
        return $base . '-' . $hash . ($ext !== '' ? '.' . $ext : '');
    }

    /** Strip a trailing `-abcde` hash suffix from the basename, if present. */
    private function stripHashSuffix(string $fileName): string
    {
        $ext = $this->extractExtension($fileName);
        $base = $ext !== '' ? substr($fileName, 0, -(strlen($ext) + 1)) : $fileName;
        $base = (string) preg_replace(
            '/-[' . self::HASH_CHARSET . ']{' . self::HASH_LENGTH . '}$/',
            '',
            $base,
        );
        return $base . ($ext !== '' ? '.' . $ext : '');
    }

    /**
     * Build the full filesystem path for an attachment.
     */
    public function getFullPath(string $dsPath, string $filePath, string $fileName): string
    {
        return $dsPath . '/att/' . $filePath . '/' . $fileName;
    }

    /**
     * Sanitize a filename for safe storage on disk.
     *
     * Rules:
     * - Spaces → hyphens
     * - Remove dangerous characters (/, \, .., NUL)
     * - Multiple consecutive hyphens → single hyphen
     * - Trim leading/trailing hyphens and dots
     * - Preserve Czech/Slovak diacritics (filesystem is UTF-8)
     */
    public function sanitizeFileName(string $name): string
    {
        // Remove null bytes
        $name = str_replace("\0", '', $name);

        // Remove path separators and parent directory references
        $name = str_replace(['/', '\\'], '', $name);
        $name = str_replace('..', '', $name);

        // Spaces → hyphens
        $name = str_replace(' ', '-', $name);

        // Multiple hyphens → single
        $name = (string) preg_replace('/-{2,}/', '-', $name);

        // Trim hyphens and dots from edges
        $name = trim($name, '-.');

        // Fallback for empty name
        if ($name === '') {
            $name = 'attachment';
        }

        return $name;
    }

    /**
     * Generate a random 5-character hash for filename uniqueness.
     */
    public function generateHash(): string
    {
        $max = strlen(self::HASH_CHARSET) - 1;
        $hash = '';
        for ($i = 0; $i < self::HASH_LENGTH; $i++) {
            $hash .= self::HASH_CHARSET[random_int(0, $max)];
        }
        return $hash;
    }

    /**
     * Extract file extension (lowercase, without dot).
     */
    private function extractExtension(string $filename): string
    {
        $dotPos = strrpos($filename, '.');
        if ($dotPos === false || $dotPos === 0) {
            return '';
        }
        return strtolower(substr($filename, $dotPos + 1));
    }
}
