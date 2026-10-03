<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Logging;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Logging\ErrorLogger;

class ErrorLoggerTest extends TestCase
{
    private string $tempLog;

    /** Log directory of the permission tests — does not exist until a test creates it. */
    private string $tempDir;

    private int $umask;

    protected function setUp(): void
    {
        ErrorLogger::resetForTesting();
        $this->tempLog = sys_get_temp_dir() . '/shipard-test-log-' . uniqid() . '.log';
        $this->tempDir = sys_get_temp_dir() . '/shipard-test-logdir-' . uniqid();
        $this->umask = umask();
        ErrorLogger::setLogPath($this->tempLog);
    }

    protected function tearDown(): void
    {
        umask($this->umask);
        if (file_exists($this->tempLog)) {
            unlink($this->tempLog);
        }
        if (is_dir($this->tempDir)) {
            @chmod($this->tempDir, 0700);
            array_map('unlink', glob($this->tempDir . '/*') ?: []);
            rmdir($this->tempDir);
        }
        ErrorLogger::resetForTesting();
    }

    public function testInfoEntryIsValidJson(): void
    {
        ErrorLogger::info('Hello', ['key' => 'value']);
        $entry = $this->readFirstEntry();

        self::assertSame('info', $entry['level']);
        self::assertSame('Hello', $entry['msg']);
        self::assertSame(['key' => 'value'], (array) $entry['ctx']);
        self::assertNull($entry['ds']);
        self::assertNull($entry['request']);
        self::assertArrayHasKey('ts', $entry);
    }

    public function testEmptyContextSerializesAsObject(): void
    {
        ErrorLogger::info('no ctx');
        $raw = file_get_contents($this->tempLog);
        self::assertNotFalse($raw);
        // ctx must be an empty object {}, not array []
        self::assertStringContainsString('"ctx":{}', $raw);
    }

    public function testThresholdFiltersBelowLevel(): void
    {
        ErrorLogger::setLogLevel('warn');
        ErrorLogger::debug('dropped');
        ErrorLogger::info('dropped');
        ErrorLogger::warn('kept');
        ErrorLogger::error('kept');

        $entries = $this->readAllEntries();
        self::assertCount(2, $entries);
        self::assertSame('warn', $entries[0]['level']);
        self::assertSame('error', $entries[1]['level']);
    }

    public function testUnknownLevelFallsBackToDebug(): void
    {
        ErrorLogger::setLogLevel('nonsense');
        ErrorLogger::debug('kept');
        $entries = $this->readAllEntries();
        self::assertCount(1, $entries);
    }

    public function testDsIdAndRequestContextArePropagated(): void
    {
        ErrorLogger::setDsId('test-ds');
        ErrorLogger::setRequestContext('GET /test');
        ErrorLogger::warn('ctx test');

        $entry = $this->readFirstEntry();
        self::assertSame('test-ds', $entry['ds']);
        self::assertSame('GET /test', $entry['request']);
    }

    public function testLogExceptionRecordsClassMessageTrace(): void
    {
        $exception = new \RuntimeException('boom');
        ErrorLogger::logException($exception);

        $entry = $this->readFirstEntry();
        self::assertSame('error', $entry['level']);
        self::assertArrayHasKey('exception', $entry);
        self::assertSame('RuntimeException', $entry['exception']['class']);
        self::assertSame('boom', $entry['exception']['message']);
        self::assertNotEmpty($entry['exception']['trace']);
        self::assertSame('RuntimeException: boom', $entry['msg']);
    }

    public function testLogExceptionWithExplicitMessage(): void
    {
        $exception = new \RuntimeException('inner detail');
        ErrorLogger::logException($exception, 'Operation X failed');

        $entry = $this->readFirstEntry();
        self::assertSame('Operation X failed', $entry['msg']);
        self::assertSame('inner detail', $entry['exception']['message']);
    }

    public function testLogExceptionRespectsThreshold(): void
    {
        // Threshold above ERROR — nothing in this codebase, but defensive
        $reflection = new \ReflectionClass(ErrorLogger::class);
        $threshold = $reflection->getProperty('threshold');
        $threshold->setValue(null, 99);

        ErrorLogger::logException(new \RuntimeException('boom'));
        self::assertSame([], $this->readAllEntries());
    }

    public function testChainedExceptionsRecordedAsPrevious(): void
    {
        $inner = new \LogicException('root cause');
        $outer = new \RuntimeException('wrapper', 0, $inner);

        ErrorLogger::logException($outer);
        $entry = $this->readFirstEntry();

        self::assertSame('wrapper', $entry['exception']['message']);
        self::assertArrayHasKey('previous', $entry['exception']);
        self::assertSame('root cause', $entry['exception']['previous']['message']);
        self::assertSame('LogicException', $entry['exception']['previous']['class']);
    }

    public function testTraceIsTruncatedTo20Frames(): void
    {
        // Build a deep call stack synthetically
        $exception = self::recursiveThrow(40);
        ErrorLogger::logException($exception);

        $entry = $this->readFirstEntry();
        self::assertLessThanOrEqual(20, count($entry['exception']['trace']));
    }

    public function testFallbackToErrorLogWhenFileNotWritable(): void
    {
        ErrorLogger::setLogPath('/proc/cannot-write-here.log');
        // Should not throw — falls back to error_log()
        ErrorLogger::warn('survives unwritable path');

        // No exception thrown is the assertion here
        self::assertTrue(true);
    }

    /** @return array<string, array{int}> */
    public static function umasks(): array
    {
        return [
            'default 022' => [0022],
            'strict 077'  => [0077],
            'open 000'    => [0000],
        ];
    }

    #[DataProvider('umasks')]
    public function testNewLogDirAndFileGetContractModes(int $umask): void
    {
        umask($umask);
        $path = $this->tempDir . '/shipard.log';
        ErrorLogger::setLogPath($path);

        ErrorLogger::info('first entry');

        self::assertSame(0750, $this->modeOf($this->tempDir));
        self::assertSame(0640, $this->modeOf($path));
    }

    public function testExistingLogDirModeIsLeftAlone(): void
    {
        umask(0022);
        mkdir($this->tempDir, 0700);
        $path = $this->tempDir . '/shipard.log';
        ErrorLogger::setLogPath($path);

        ErrorLogger::info('first entry');

        self::assertSame(0700, $this->modeOf($this->tempDir));
        self::assertSame(0640, $this->modeOf($path));
    }

    public function testExistingLogFileIsLeftAlone(): void
    {
        umask(0022);
        mkdir($this->tempDir, 0750);
        $path = $this->tempDir . '/shipard.log';
        file_put_contents($path, "older entry\n");
        chmod($path, 0600);
        ErrorLogger::setLogPath($path);

        ErrorLogger::info('appended');

        self::assertSame(0600, $this->modeOf($path));
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(2, $lines);
        self::assertSame('older entry', $lines[0]);
        self::assertSame('appended', json_decode($lines[1], true)['msg']);
    }

    public function testConcurrentProcessesCreateLogWithoutErrors(): void
    {
        umask(0022);
        $path = $this->tempDir . '/shipard.log';
        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
        // Every child waits for the same instant so that the directory and
        // the file are created by processes racing each other.
        $code = 'require $argv[1];'
            . ' @time_sleep_until((float) $argv[3]);'
            . ' \Shipard\Core\Logging\ErrorLogger::setLogPath($argv[2]);'
            . ' \Shipard\Core\Logging\ErrorLogger::info("child " . getmypid());';
        $startAt = sprintf('%.4F', microtime(true) + 0.5);
        $count = 8;

        $children = [];
        for ($i = 0; $i < $count; $i++) {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, '-r', $code, '--', $autoload, $path, $startAt],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            $children[] = [$process, $pipes];
        }

        foreach ($children as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            self::assertSame(0, proc_close($process), (string) $stderr);
            self::assertSame('', $stdout);
            // stderr carries the error_log() copy of the entry — and nothing else
            self::assertStringNotContainsString('ErrorLogger fallback', (string) $stderr);
            self::assertStringNotContainsString('Warning', (string) $stderr);
        }

        self::assertSame(0750, $this->modeOf($this->tempDir));
        self::assertSame(0640, $this->modeOf($path));
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount($count, $lines);
        foreach ($lines as $line) {
            self::assertStringStartsWith('child ', json_decode($line, true)['msg'] ?? '');
        }
    }

    public function testEntryIsSingleLineJson(): void
    {
        ErrorLogger::info('multi\nline\nin msg', ['nested' => ['a' => 1]]);
        $raw = file_get_contents($this->tempLog);
        self::assertNotFalse($raw);
        // Exactly one newline (the trailing one); no embedded line breaks in JSON
        self::assertSame(1, substr_count($raw, "\n"));
    }

    public function testTimestampIsIso8601(): void
    {
        ErrorLogger::info('time');
        $entry = $this->readFirstEntry();
        // ISO 8601 with timezone, e.g. 2026-05-07T12:34:56+02:00
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}$/',
            $entry['ts'],
        );
    }

    private function modeOf(string $path): int
    {
        clearstatcache(true, $path);
        return fileperms($path) & 0777;
    }

    /** @return array<string, mixed> */
    private function readFirstEntry(): array
    {
        $entries = $this->readAllEntries();
        self::assertNotEmpty($entries, 'log file is empty');
        return $entries[0];
    }

    /** @return list<array<string, mixed>> */
    private function readAllEntries(): array
    {
        if (!file_exists($this->tempLog)) {
            return [];
        }
        $lines = file($this->tempLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }
        return array_map(
            fn(string $line): array => json_decode($line, true) ?: [],
            $lines,
        );
    }

    private static function recursiveThrow(int $depth): \Throwable
    {
        if ($depth <= 0) {
            return new \RuntimeException('deep');
        }
        try {
            throw self::recursiveThrow($depth - 1);
        } catch (\Throwable $e) {
            return $e;
        }
    }
}
