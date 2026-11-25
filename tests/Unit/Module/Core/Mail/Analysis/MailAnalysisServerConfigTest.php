<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Analysis;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Config\ServerConfigSection;
use Shipard\Module\Core\Mail\Analysis\MailAnalysisServerConfig;

/**
 * Sekce `ai.analysis` v `server.json` (tasks/mail-analysis-inprocess.md D15;
 * sekce modulu #172 D10) — případy přestěhované z testu getteru `ServerConfig`.
 */
class MailAnalysisServerConfigTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shipard_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    /** @param array<string, mixed> $extra Klíče `server.json` navíc k povinným. */
    private function serverConfig(array $extra = []): ServerConfig
    {
        $path = $this->tempDir . '/server.json';
        file_put_contents($path, json_encode([
            'host' => '127.0.0.1', 'port' => 3306, 'admin_user' => 'root', 'admin_password' => 'secret', 'mode' => 'production',
        ] + $extra));
        $config = new ServerConfig($path);
        $config->load();
        return $config;
    }

    public function testIsASectionWithItsKey(): void
    {
        $this->assertTrue(is_subclass_of(MailAnalysisServerConfig::class, ServerConfigSection::class));
        $this->assertSame('ai.analysis', MailAnalysisServerConfig::key());
    }

    public function testMaxConcurrentDefaultsAndValidates(): void
    {
        $this->assertSame(2, MailAnalysisServerConfig::fromServerConfig($this->serverConfig())->maxConcurrent());
        $this->assertSame(2, MailAnalysisServerConfig::DEFAULT_MAX_CONCURRENT);

        $config = $this->serverConfig(['ai' => ['analysis' => ['maxConcurrent' => 0]]]);
        $this->assertSame(0, MailAnalysisServerConfig::fromServerConfig($config)->maxConcurrent());

        $config = $this->serverConfig(['ai' => ['analysis' => ['maxConcurrent' => 5]]]);
        $this->assertSame(5, MailAnalysisServerConfig::fromServerConfig($config)->maxConcurrent());

        $config = $this->serverConfig(['ai' => ['analysis' => ['maxConcurrent' => -1]]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Server config 'ai.analysis.maxConcurrent' must be a non-negative integer");
        MailAnalysisServerConfig::fromServerConfig($config);
    }

    /** @param mixed $value */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonIntegerLimits')]
    public function testMaxConcurrentRejectsNonInteger(mixed $value): void
    {
        $config = $this->serverConfig(['ai' => ['analysis' => ['maxConcurrent' => $value]]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Server config 'ai.analysis.maxConcurrent' must be a non-negative integer");
        MailAnalysisServerConfig::fromServerConfig($config);
    }

    /** @return array<string, array{mixed}> */
    public static function nonIntegerLimits(): array
    {
        return ['slovo' => ['two'], 'číslo v řetězci' => ['2'], 'desetinné' => [2.5], 'true' => [true], 'objekt' => [['n' => 2]]];
    }

    public function testSectionWithoutTheKeyOrOfAnotherTypeMeansTheDefault(): void
    {
        $cases = [
            ['ai' => []],
            ['ai' => ['analysis' => []]],
            ['ai' => ['analysis' => ['maxConcurrent' => null]]],
            ['ai' => ['analysis' => 'x']],
            ['ai' => 'x'],
            ['ai' => ['chat' => ['maxConcurrent' => 9]]],
        ];
        foreach ($cases as $extra) {
            $this->assertSame(
                MailAnalysisServerConfig::DEFAULT_MAX_CONCURRENT,
                MailAnalysisServerConfig::fromServerConfig($this->serverConfig($extra))->maxConcurrent(),
                json_encode($extra),
            );
        }
    }
}
