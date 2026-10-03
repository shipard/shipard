<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Attachments;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Attachments\FileStorage;

class FileStorageTest extends TestCase
{
    private FileStorage $storage;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->storage = new FileStorage();
        $this->tempDir = sys_get_temp_dir() . '/shpd_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tempDir);
    }

    // --- sanitizeFileName ---------------------------------------------------

    public function testSanitizeReplacesSpaces(): void
    {
        $this->assertSame('hello-world.pdf', $this->storage->sanitizeFileName('hello world.pdf'));
    }

    public function testSanitizeRemovesSlashes(): void
    {
        $this->assertSame('file.pdf', $this->storage->sanitizeFileName('../../../file.pdf'));
    }

    public function testSanitizeRemovesBackslashes(): void
    {
        // Backslashes are stripped as individual characters, not treated as path separators
        $this->assertSame('pathtofile.pdf', $this->storage->sanitizeFileName('path\\to\\file.pdf'));
    }

    public function testSanitizeRemovesNullBytes(): void
    {
        $this->assertSame('file.pdf', $this->storage->sanitizeFileName("file\0.pdf"));
    }

    public function testSanitizeCollapsesMultipleHyphens(): void
    {
        $this->assertSame('a-b.txt', $this->storage->sanitizeFileName('a---b.txt'));
    }

    public function testSanitizeTrimsEdgeHyphensAndDots(): void
    {
        $this->assertSame('file.txt', $this->storage->sanitizeFileName('-file.txt-'));
    }

    public function testSanitizePreservesDiacritics(): void
    {
        $this->assertSame('příloha-č.1.pdf', $this->storage->sanitizeFileName('příloha č.1.pdf'));
    }

    public function testSanitizeFallbackForEmpty(): void
    {
        $this->assertSame('attachment', $this->storage->sanitizeFileName(''));
    }

    public function testSanitizeFallbackForOnlyDots(): void
    {
        $this->assertSame('attachment', $this->storage->sanitizeFileName('....'));
    }

    // --- generateHash -------------------------------------------------------

    public function testGenerateHashLength(): void
    {
        $hash = $this->storage->generateHash();
        $this->assertSame(5, strlen($hash));
    }

    public function testGenerateHashCharset(): void
    {
        $hash = $this->storage->generateHash();
        $this->assertMatchesRegularExpression('/^[a-z0-9]{5}$/', $hash);
    }

    public function testGenerateHashUniqueness(): void
    {
        $hashes = [];
        for ($i = 0; $i < 100; $i++) {
            $hashes[] = $this->storage->generateHash();
        }
        // With 36^5 = 60M+ possibilities, 100 should be unique
        $this->assertSame(100, count(array_unique($hashes)));
    }

    // --- store --------------------------------------------------------------

    public function testStoreCreatesDirectoryStructure(): void
    {
        // Create a temporary source file
        $tmpFile = $this->tempDir . '/upload.pdf';
        file_put_contents($tmpFile, 'PDF content');

        $fileInfo = $this->storage->store($this->tempDir, 'base_persons_persons', 'test.pdf', $tmpFile);

        $now = new \DateTimeImmutable();
        $expectedDir = $this->tempDir . '/att/' . $now->format('Y/m/d') . '/base_persons_persons';
        $this->assertDirectoryExists($expectedDir);
    }

    public function testStoreCreatesDirectoriesWithSpecMode(): void
    {
        $tmpFile = $this->tempDir . '/upload.pdf';
        file_put_contents($tmpFile, 'PDF content');

        $this->storage->store($this->tempDir, 'base_persons_persons', 'test.pdf', $tmpFile);

        $now = new \DateTimeImmutable();
        $dir = $this->tempDir;
        clearstatcache();
        foreach (['att', $now->format('Y'), $now->format('m'), $now->format('d'), 'base_persons_persons'] as $level) {
            $dir .= '/' . $level;
            $this->assertSame(0750, fileperms($dir) & 0777, "mode of '{$dir}'");
        }
    }

    public function testStoreMovesFile(): void
    {
        $tmpFile = $this->tempDir . '/upload.pdf';
        file_put_contents($tmpFile, 'PDF content here');

        $fileInfo = $this->storage->store($this->tempDir, 'test_table', 'invoice.pdf', $tmpFile);

        // Original temp file should no longer exist
        $this->assertFileDoesNotExist($tmpFile);

        // File should exist at target location
        $fullPath = $this->tempDir . '/att/' . $fileInfo->filePath . '/' . $fileInfo->fileName;
        $this->assertFileExists($fullPath);
        $this->assertSame('PDF content here', file_get_contents($fullPath));
    }

    public function testStoreReturnsCorrectChecksum(): void
    {
        $content = 'test content for checksum';
        $tmpFile = $this->tempDir . '/upload.txt';
        file_put_contents($tmpFile, $content);

        $expectedChecksum = hash('sha256', $content);

        $fileInfo = $this->storage->store($this->tempDir, 'test_table', 'test.txt', $tmpFile);

        $this->assertSame($expectedChecksum, $fileInfo->checksum);
    }

    public function testStoreReturnsCorrectFileSize(): void
    {
        $content = str_repeat('x', 1024);
        $tmpFile = $this->tempDir . '/upload.bin';
        file_put_contents($tmpFile, $content);

        $fileInfo = $this->storage->store($this->tempDir, 'test_table', 'data.bin', $tmpFile);

        $this->assertSame(1024, $fileInfo->fileSize);
    }

    public function testStoreAddsHashSuffix(): void
    {
        $tmpFile = $this->tempDir . '/upload.pdf';
        file_put_contents($tmpFile, 'content');

        $fileInfo = $this->storage->store($this->tempDir, 'test_table', 'faktura.pdf', $tmpFile);

        // Filename should match pattern: faktura-{5char}.pdf
        $this->assertMatchesRegularExpression('/^faktura-[a-z0-9]{5}\.pdf$/', $fileInfo->fileName);
    }

    public function testStoreFilePathContainsDateAndTable(): void
    {
        $tmpFile = $this->tempDir . '/upload.txt';
        file_put_contents($tmpFile, 'content');

        $fileInfo = $this->storage->store($this->tempDir, 'economy_docs_heads', 'doc.txt', $tmpFile);

        $now = new \DateTimeImmutable();
        $expected = $now->format('Y/m/d') . '/economy_docs_heads';
        $this->assertSame($expected, $fileInfo->filePath);
    }

    public function testStoreHandlesFileWithoutExtension(): void
    {
        $tmpFile = $this->tempDir . '/upload';
        file_put_contents($tmpFile, 'content');

        $fileInfo = $this->storage->store($this->tempDir, 'test_table', 'README', $tmpFile);

        $this->assertMatchesRegularExpression('/^README-[a-z0-9]{5}$/', $fileInfo->fileName);
    }

    // --- getFullPath --------------------------------------------------------

    public function testGetFullPath(): void
    {
        $result = $this->storage->getFullPath('/opt/data/ds1', '2026/04/15/test_table', 'file-abc12.pdf');
        $this->assertSame('/opt/data/ds1/att/2026/04/15/test_table/file-abc12.pdf', $result);
    }

    // --- helpers ------------------------------------------------------------

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
