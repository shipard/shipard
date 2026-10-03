<?php

declare(strict_types=1);

namespace Shipard\Core\Logging;

/**
 * Centralized application logger.
 *
 * Writes single-line JSON entries to /opt/shipard/log/shipard.log (default)
 * or wherever ServerConfig::getLogFile() points. Falls back to PHP error_log()
 * when the file isn't writable.
 *
 * Lifecycle:
 *   - Bootstrap (index.php) calls setLogPath, setLogLevel, setRequestContext.
 *   - After DataSourceResolver::resolve(), bootstrap calls setDsId.
 *   - Application code uses static methods: debug/info/warn/error/logException.
 *
 * The logger is intentionally static — there's only one log destination per
 * request, threading isn't a concern (PHP-FPM is single-threaded per request),
 * and DI plumbing through 50 controllers for something this universal would
 * be overkill.
 */
final class ErrorLogger
{
    public const LEVEL_DEBUG = 0;
    public const LEVEL_INFO  = 1;
    public const LEVEL_WARN  = 2;
    public const LEVEL_ERROR = 3;

    private const LEVEL_NAMES = [
        self::LEVEL_DEBUG => 'debug',
        self::LEVEL_INFO  => 'info',
        self::LEVEL_WARN  => 'warn',
        self::LEVEL_ERROR => 'error',
    ];

    private const LEVEL_VALUES = [
        'debug' => self::LEVEL_DEBUG,
        'info'  => self::LEVEL_INFO,
        'warn'  => self::LEVEL_WARN,
        'error' => self::LEVEL_ERROR,
    ];

    /** Default destination if nothing else is configured. */
    private const DEFAULT_LOG_PATH = '/opt/shipard/log/shipard.log';

    /**
     * Modes of the log directory and file the logger creates — the same
     * values PermissionSpec declares for log/ and shipard.log.
     */
    private const LOG_DIR_MODE = 0750;
    private const LOG_FILE_MODE = 0640;

    /** Maximum stack frames recorded in JSON exception entry. */
    private const TRACE_FRAME_LIMIT = 20;

    /** Maximum chain depth for getPrevious() exceptions. */
    private const PREVIOUS_DEPTH_LIMIT = 5;

    /** Threshold — entries with level < threshold are dropped. */
    private static int $threshold = self::LEVEL_DEBUG;

    private static ?string $logPath = null;
    private static ?string $dsId = null;
    private static ?string $requestContext = null;

    public static function setLogPath(?string $path): void
    {
        self::$logPath = $path;
    }

    public static function setLogLevel(string $level): void
    {
        $key = strtolower($level);
        self::$threshold = self::LEVEL_VALUES[$key] ?? self::LEVEL_DEBUG;
    }

    public static function setDsId(?string $dsId): void
    {
        self::$dsId = $dsId;
    }

    public static function setRequestContext(?string $context): void
    {
        self::$requestContext = $context;
    }

    public static function debug(string $msg, array $ctx = []): void
    {
        self::emit(self::LEVEL_DEBUG, $msg, $ctx);
    }

    public static function info(string $msg, array $ctx = []): void
    {
        self::emit(self::LEVEL_INFO, $msg, $ctx);
    }

    public static function warn(string $msg, array $ctx = []): void
    {
        self::emit(self::LEVEL_WARN, $msg, $ctx);
    }

    public static function error(string $msg, array $ctx = []): void
    {
        self::emit(self::LEVEL_ERROR, $msg, $ctx);
    }

    public static function logException(\Throwable $e, string $msg = ''): void
    {
        if (self::LEVEL_ERROR < self::$threshold) {
            return;
        }

        $primary = $msg !== '' ? $msg : (get_class($e) . ': ' . $e->getMessage());
        $entry = self::baseEntry(self::LEVEL_ERROR, $primary, []);
        $entry['exception'] = self::formatException($e);

        self::write($entry);
    }

    // ── Internal ────────────────────────────────────────────────────────────

    private static function emit(int $level, string $msg, array $ctx): void
    {
        if ($level < self::$threshold) {
            return;
        }
        self::write(self::baseEntry($level, $msg, $ctx));
    }

    /** @return array<string, mixed> */
    private static function baseEntry(int $level, string $msg, array $ctx): array
    {
        return [
            'ts'      => date('c'),
            'level'   => self::LEVEL_NAMES[$level] ?? 'info',
            'ds'      => self::$dsId,
            'request' => self::$requestContext,
            'msg'     => $msg,
            'ctx'     => (object) $ctx,
        ];
    }

    /** @return array<string, mixed> */
    private static function formatException(\Throwable $e, int $depth = 0): array
    {
        $traceLines = preg_split('/\r?\n/', $e->getTraceAsString()) ?: [];
        $traceLines = array_slice($traceLines, 0, self::TRACE_FRAME_LIMIT);

        $entry = [
            'class'   => get_class($e),
            'message' => $e->getMessage(),
            'at'      => self::shortenPath($e->getFile()) . ':' . $e->getLine(),
            'trace'   => $traceLines,
        ];

        $previous = $e->getPrevious();
        if ($previous !== null && $depth < self::PREVIOUS_DEPTH_LIMIT) {
            $entry['previous'] = self::formatException($previous, $depth + 1);
        }

        return $entry;
    }

    /**
     * Compact full filesystem paths to relative-from-project-root for shorter
     * log lines. /home/sebik/sw/shpd/src/Foo.php → src/Foo.php
     */
    private static function shortenPath(string $path): string
    {
        $projectRoot = dirname(__DIR__, 3);
        if (str_starts_with($path, $projectRoot . '/')) {
            return substr($path, strlen($projectRoot) + 1);
        }
        return $path;
    }

    /** @param array<string, mixed> $entry */
    private static function write(array $entry): void
    {
        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = json_encode([
                'ts'    => $entry['ts'] ?? date('c'),
                'level' => $entry['level'] ?? 'error',
                'msg'   => '[ErrorLogger: json_encode failed]',
                'ctx'   => (object) [],
            ]);
        }
        $line = $json . "\n";

        $path = self::$logPath ?? self::DEFAULT_LOG_PATH;
        $dir = dirname($path);

        // mkdir is subject to umask, hence the explicit chmod — only when the
        // directory was created here (false = another process was faster).
        if (!is_dir($dir) && @mkdir($dir, self::LOG_DIR_MODE, true)) {
            @chmod($dir, self::LOG_DIR_MODE);
        }
        self::createLogFile($path);

        $written = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            error_log('ErrorLogger fallback (cannot write to ' . $path . '): ' . trim($line));
            return;
        }

        error_log(rtrim($line, "\n"));
    }

    /**
     * Creates the log file with LOG_FILE_MODE when it does not exist yet.
     * Mode 'x' is an exclusive create: among concurrent FPM workers and CLI
     * processes exactly one succeeds and only that one sets the mode. An
     * existing file is left as it is — converging that is the job of
     * fix-permissions. Runs on every write so that a file recreated after
     * a rotation gets the mode too.
     */
    private static function createLogFile(string $path): void
    {
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            return;
        }
        fclose($handle);
        @chmod($path, self::LOG_FILE_MODE);
    }

    // ── Test helpers ────────────────────────────────────────────────────────

    /**
     * Reset internal state. Used by tests — never call from production code.
     */
    public static function resetForTesting(): void
    {
        self::$threshold = self::LEVEL_DEBUG;
        self::$logPath = null;
        self::$dsId = null;
        self::$requestContext = null;
    }
}
