<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons\Registry;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Config\ServerConfigSection;
use Shipard\Module\Base\Persons\Registry\PersonsRegistryServerConfig;
use Shipard\Module\Base\Persons\Registry\PersonsRegistryClient;

/**
 * Sekce `registry.persons` v `server.json` (sekce modulu #172 D10) — případy
 * přestěhované z testu getteru `ServerConfig`.
 */
class PersonsRegistryServerConfigTest extends TestCase
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
        $this->assertTrue(is_subclass_of(PersonsRegistryServerConfig::class, ServerConfigSection::class));
        $this->assertSame('registry.persons', PersonsRegistryServerConfig::key());
    }

    public function testBaseUrlDefault(): void
    {
        $section = PersonsRegistryServerConfig::fromServerConfig($this->serverConfig());

        $this->assertSame('https://data.shipard.org/persons', $section->baseUrl());
        $this->assertSame(PersonsRegistryClient::DEFAULT_BASE_URL, $section->baseUrl());
    }

    public function testBaseUrlCustom(): void
    {
        $section = PersonsRegistryServerConfig::fromServerConfig($this->serverConfig([
            'registry' => [
                'persons' => [
                    'baseUrl' => 'https://dev-example.shpd.dev/abcd-efgh-ijkl-mnop/www/data.shipard.org/persons',
                ],
            ],
        ]));

        $this->assertSame(
            'https://dev-example.shpd.dev/abcd-efgh-ijkl-mnop/www/data.shipard.org/persons',
            $section->baseUrl(),
        );
    }

    public function testBaseUrlStripsTrailingSlash(): void
    {
        $section = PersonsRegistryServerConfig::fromServerConfig(
            $this->serverConfig(['registry' => ['persons' => ['baseUrl' => 'https://example.org/persons/']]]),
        );

        $this->assertSame('https://example.org/persons', $section->baseUrl());
    }

    public function testBaseUrlRejectsNonHttpScheme(): void
    {
        $config = $this->serverConfig(['registry' => ['persons' => ['baseUrl' => 'ftp://example.org/persons']]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Server config 'registry.persons.baseUrl' must start with http:// or https://, got 'ftp://example.org/persons'");
        PersonsRegistryServerConfig::fromServerConfig($config);
    }

    /** @param mixed $baseUrl */
    #[\PHPUnit\Framework\Attributes\DataProvider('emptyOrNonStringBaseUrls')]
    public function testBaseUrlRejectsEmptyOrNonString(mixed $baseUrl): void
    {
        $config = $this->serverConfig(['registry' => ['persons' => ['baseUrl' => $baseUrl]]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Server config 'registry.persons.baseUrl' must be a non-empty string");
        PersonsRegistryServerConfig::fromServerConfig($config);
    }

    /** @return array<string, array{mixed}> */
    public static function emptyOrNonStringBaseUrls(): array
    {
        return ['prázdný řetězec' => [''], 'číslo' => [42], 'objekt' => [['url' => 'https://example.org']]];
    }

    public function testSectionWithoutTheKeyOrOfAnotherTypeMeansTheDefault(): void
    {
        $cases = [
            ['registry' => []],
            ['registry' => ['persons' => []]],
            ['registry' => ['persons' => ['baseUrl' => null]]],
            ['registry' => ['persons' => 'x']],
            ['registry' => 'x'],
            ['registry' => ['other' => ['baseUrl' => 'https://example.org']]],
        ];
        foreach ($cases as $extra) {
            $this->assertSame(
                PersonsRegistryClient::DEFAULT_BASE_URL,
                PersonsRegistryServerConfig::fromServerConfig($this->serverConfig($extra))->baseUrl(),
                json_encode($extra),
            );
        }
    }

    public function testClientTakesTheBaseUrlFromTheSection(): void
    {
        $client = PersonsRegistryClient::fromServerConfig(
            $this->serverConfig(['registry' => ['persons' => ['baseUrl' => 'https://example.org/persons/']]]),
        );

        $this->assertSame(
            'https://example.org/persons',
            (new \ReflectionProperty(PersonsRegistryClient::class, 'baseUrl'))->getValue($client),
        );
    }
}
