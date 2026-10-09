<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Analysis;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Mail\Analysis\AnalysisSlots;

/**
 * Sloty souběhu (D15): nejvýš `maxConcurrent` držených zámků, uvolnění,
 * `freeCount()`, limit 0 = vypnuto, založení run adresáře, nezapisovatelný
 * adresář = bez slotu + chyba v logu (nikdy tiché vypnutí).
 */
final class AnalysisSlotsTest extends TestCase
{
    private string $runDir;
    private string $logFile;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/shpd_slots_' . bin2hex(random_bytes(6));
        $this->runDir = $base . '/run';
        $this->logFile = $base . '/test.log';
        mkdir($this->runDir, 0750, true);
        ErrorLogger::setLogPath($this->logFile);
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        @chmod($this->runDir, 0750);
        foreach (glob($this->runDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->runDir);
        @unlink($this->logFile);
        @rmdir(dirname($this->runDir));
    }

    public function testAcquiresUpToLimitAndReleases(): void
    {
        $slots = new AnalysisSlots(2, $this->runDir);

        $first = $slots->tryAcquire();
        $second = $slots->tryAcquire();
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(1, $first->number);
        $this->assertSame(2, $second->number);
        $this->assertNull($slots->tryAcquire());
        $this->assertSame(0, $slots->freeCount());

        $first->release();
        $this->assertSame(1, $slots->freeCount());
        $this->assertSame(1, $slots->tryAcquire()?->number);

        $second->release();
        $this->assertFileExists($slots->lockPath(1));
        $this->assertFileExists($slots->lockPath(2));
    }

    public function testFreeCountReleasesWhatItProbed(): void
    {
        $slots = new AnalysisSlots(3, $this->runDir);

        $this->assertSame(3, $slots->freeCount());
        $this->assertNotNull($slots->tryAcquire());
    }

    public function testZeroMeansDisabled(): void
    {
        $slots = new AnalysisSlots(0, $this->runDir);

        $this->assertTrue($slots->isDisabled());
        $this->assertNull($slots->tryAcquire());
        $this->assertSame(0, $slots->freeCount());
    }

    public function testCreatesMissingRunDir(): void
    {
        $dir = $this->runDir . '/nested/run';
        $slots = new AnalysisSlots(1, $dir);

        $this->assertNotNull($slots->tryAcquire());
        $this->assertDirectoryExists($dir);

        @unlink($slots->lockPath(1));
        @rmdir($dir);
        @rmdir(dirname($dir));
    }

    public function testUnwritableRunDirYieldsNoSlotAndLogsError(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root může zapisovat všude');
        }
        chmod($this->runDir, 0500);
        $slots = new AnalysisSlots(1, $this->runDir);

        $this->assertNull($slots->tryAcquire());
        $log = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('cannot open slot lock file', $log);
        $this->assertStringContainsString('"level":"error"', $log);
    }

    public function testMaxConcurrentFromServerConfig(): void
    {
        $path = $this->runDir . '/server.json';
        $base = ['host' => 'h', 'port' => 1, 'admin_user' => 'u', 'admin_password' => 'p', 'mode' => 'development'];

        file_put_contents($path, json_encode($base + ['ai' => ['analysis' => ['maxConcurrent' => 4]]]));
        $config = new ServerConfig($path);
        $config->load();
        $this->assertSame(4, AnalysisSlots::maxConcurrentOf($config));
        $this->assertSame(4, AnalysisSlots::fromServerConfig($config, $this->runDir)->maxConcurrent());

        file_put_contents($path, json_encode($base + ['ai' => ['analysis' => ['maxConcurrent' => 'two']]]));
        $config = new ServerConfig($path);
        $config->load();
        $this->assertSame(ServerConfig::DEFAULT_AI_ANALYSIS_MAX_CONCURRENT, AnalysisSlots::maxConcurrentOf($config));
        $this->assertStringContainsString('invalid ai.analysis.maxConcurrent', (string) file_get_contents($this->logFile));

        unlink($path);
    }
}
