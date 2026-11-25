<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\ExchangeRates;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Config\ServerConfigSection;
use Shipard\Module\Economy\ExchangeRates\ExchangeRatesServerConfig;

/**
 * Sekce `exchangeRates` v `server.json` (#121 task B, U3; sekce modulu #172 D10)
 * — případy přestěhované z testu getteru `ServerConfig`.
 */
class ExchangeRatesServerConfigTest extends TestCase
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
        $this->assertTrue(is_subclass_of(ExchangeRatesServerConfig::class, ServerConfigSection::class));
        $this->assertSame('exchangeRates', ExchangeRatesServerConfig::key());
    }

    public function testServiceUrlDefaultsToPublicService(): void
    {
        $section = ExchangeRatesServerConfig::fromServerConfig($this->serverConfig());

        $this->assertSame('https://data.shipard.app/feed/money-exchange-rates', $section->serviceUrl());
        $this->assertSame(ExchangeRatesServerConfig::DEFAULT_SERVICE_URL, $section->serviceUrl());
    }

    public function testServiceUrlEmptyStringDisablesDownloading(): void
    {
        $section = ExchangeRatesServerConfig::fromServerConfig($this->serverConfig(['exchangeRates' => ['serviceUrl' => '']]));

        $this->assertNull($section->serviceUrl());
    }

    public function testServiceUrlCustomStripsTrailingSlash(): void
    {
        $section = ExchangeRatesServerConfig::fromServerConfig(
            $this->serverConfig(['exchangeRates' => ['serviceUrl' => 'http://127.0.0.1:8099/feed/']]),
        );

        $this->assertSame('http://127.0.0.1:8099/feed', $section->serviceUrl());
    }

    public function testServiceUrlRejectsNonHttpScheme(): void
    {
        $config = $this->serverConfig(['exchangeRates' => ['serviceUrl' => 'ftp://example.org/feed']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Server config 'exchangeRates.serviceUrl' must start with http:// or https://, got 'ftp://example.org/feed'");
        ExchangeRatesServerConfig::fromServerConfig($config);
    }

    public function testServiceUrlRejectsNonString(): void
    {
        $config = $this->serverConfig(['exchangeRates' => ['serviceUrl' => 42]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Server config 'exchangeRates.serviceUrl' must be a string (empty string disables downloading)");
        ExchangeRatesServerConfig::fromServerConfig($config);
    }

    public function testSectionWithoutTheKeyOrOfAnotherTypeMeansTheDefault(): void
    {
        foreach ([['exchangeRates' => []], ['exchangeRates' => ['other' => 1]], ['exchangeRates' => 'x'], ['exchangeRates' => null]] as $extra) {
            $this->assertSame(
                ExchangeRatesServerConfig::DEFAULT_SERVICE_URL,
                ExchangeRatesServerConfig::fromServerConfig($this->serverConfig($extra))->serviceUrl(),
                json_encode($extra),
            );
        }
    }
}
