<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Safety;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Config\ServerConfigSection;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\SalesRegistration\Safety\SalesRegistrationServerConfig;

/**
 * Pojistka serveru (#171 D9): sekce `salesRegistration.safety` v server.json.
 * Bez sekce posílá jen produkční server, jinak `drop`; chybná konfigurace je
 * `drop` s popisem problému — nikdy výjimka.
 */
class SalesRegistrationServerConfigTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    /** @param array<string, mixed> $extra */
    private function serverConfig(string $mode, array $extra = []): ServerConfig
    {
        $path = $this->files[] = (string) tempnam(sys_get_temp_dir(), 'shpd-server-json-');
        file_put_contents($path, json_encode($extra + [
            'host' => 'localhost', 'port' => 3306, 'admin_user' => 'root', 'admin_password' => 'x', 'mode' => $mode,
        ]));
        $config = new ServerConfig($path);
        $config->load();
        return $config;
    }

    public function testImplementsTheSectionContractAndIsDeclaredByTheModule(): void
    {
        $this->assertInstanceOf(ServerConfigSection::class, SalesRegistrationServerConfig::ofMode('off'));
        $this->assertSame('salesRegistration', SalesRegistrationServerConfig::key());

        $module = JsoncParser::parseFile(__DIR__ . '/../../../../../../modules/economy/salesRegistration/module.jsonc');
        $this->assertSame(
            [['key' => 'salesRegistration', 'class' => SalesRegistrationServerConfig::class]],
            $module['serverConfig'],
        );
        $this->assertNotContains('salesRegistration', ServerConfig::CORE_KEYS);
    }

    /** @return array<string, array{string, string}> režim serveru → očekávaný režim pojistky */
    public static function serverModes(): array
    {
        return [
            'production sends'    => ['production', 'off'],
            'development drops'   => ['development', 'drop'],
            'testing drops'       => ['testing', 'drop'],
            'unknown mode drops'  => ['prod', 'drop'],
        ];
    }

    #[DataProvider('serverModes')]
    public function testWithoutTheSectionOnlyAProductionServerSends(string $serverMode, string $expected): void
    {
        $safety = SalesRegistrationServerConfig::fromServerConfig($this->serverConfig($serverMode));

        $this->assertSame($expected, $safety->mode);
        $this->assertSame(SalesRegistrationServerConfig::SOURCE_DEFAULT, $safety->source);
        $this->assertNull($safety->problem);
        $this->assertSame($expected === 'drop', $safety->isDrop());
    }

    public function testSectionWithoutSafetyBehavesLikeAMissingSection(): void
    {
        $safety = SalesRegistrationServerConfig::fromServerConfig($this->serverConfig('development', ['salesRegistration' => []]));

        $this->assertSame('drop', $safety->mode);
        $this->assertSame(SalesRegistrationServerConfig::SOURCE_DEFAULT, $safety->source);
    }

    /** @return array<string, array{string, string}> */
    public static function configuredModes(): array
    {
        return [
            'playground on dev'      => ['development', 'playground'],
            'off on dev (explicit)'  => ['development', 'off'],
            'drop on production'     => ['production', 'drop'],
            'playground on prod'     => ['production', 'playground'],
        ];
    }

    #[DataProvider('configuredModes')]
    public function testConfiguredModeWinsOverTheServerMode(string $serverMode, string $mode): void
    {
        $safety = SalesRegistrationServerConfig::fromServerConfig(
            $this->serverConfig($serverMode, ['salesRegistration' => ['safety' => ['mode' => $mode]]]),
        );

        $this->assertSame($mode, $safety->mode);
        $this->assertSame(SalesRegistrationServerConfig::SOURCE_CONFIGURED, $safety->source);
        $this->assertNull($safety->problem);
    }

    /** @return array<string, array{mixed, string}> */
    public static function invalidSections(): array
    {
        return [
            'section is a string'  => ['playground', "'salesRegistration' must be an object"],
            'safety is a string'   => [['safety' => 'playground'], "'salesRegistration.safety' must be an object"],
            'mode missing'         => [['safety' => []], "'salesRegistration.safety.mode' must be one of"],
            'unknown mode'         => [['safety' => ['mode' => 'production']], "'salesRegistration.safety.mode' must be one of"],
            'mode is a boolean'    => [['safety' => ['mode' => true]], "'salesRegistration.safety.mode' must be one of"],
            'mode in wrong case'   => [['safety' => ['mode' => 'OFF']], "'salesRegistration.safety.mode' must be one of"],
        ];
    }

    #[DataProvider('invalidSections')]
    public function testInvalidSectionFailsClosedEvenOnAProductionServer(mixed $section, string $problem): void
    {
        $safety = SalesRegistrationServerConfig::fromServerConfig(
            $this->serverConfig('production', ['salesRegistration' => $section]),
        );

        $this->assertSame('drop', $safety->mode);
        $this->assertSame(SalesRegistrationServerConfig::SOURCE_INVALID, $safety->source);
        $this->assertStringContainsString($problem, (string) $safety->problem);
    }

    public function testUnreadableServerConfigFailsClosedWithoutThrowing(): void
    {
        $missing = SalesRegistrationServerConfig::forServer(new ServerConfig('/nonexistent/server.json'));
        $this->assertSame('drop', $missing->mode, 'nenačtený ServerConfig nemá režim serveru — nikdy off');

        $notLoaded = SalesRegistrationServerConfig::fromServerConfig(new ServerConfig('/nonexistent/server.json'));
        $this->assertTrue($notLoaded->isDrop());

        $this->assertSame('off', SalesRegistrationServerConfig::forServer($this->serverConfig('production'))->mode);
    }

    public function testOfModeRefusesUnknownMode(): void
    {
        $this->assertSame('playground', SalesRegistrationServerConfig::ofMode('playground')->mode);

        $bad = SalesRegistrationServerConfig::ofMode('live');
        $this->assertTrue($bad->isDrop());
        $this->assertSame(SalesRegistrationServerConfig::SOURCE_INVALID, $bad->source);
    }
}
