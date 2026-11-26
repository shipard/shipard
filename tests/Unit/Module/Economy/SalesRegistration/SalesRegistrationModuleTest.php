<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleKind;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;

/**
 * Deklarace doplňku economy.salesRegistration nad skutečnými soubory
 * (tasks/sales-registration-phase1.md): doplněk mimo install.base, bez
 * závislosti na modulu země, mapa enginů jako cfgItem doplňku.
 */
class SalesRegistrationModuleTest extends TestCase
{
    private const MODULES = __DIR__ . '/../../../../../modules';
    private const MODULE = self::MODULES . '/economy/salesRegistration';

    /** @return array<string, mixed> */
    private static function module(): array
    {
        return JsoncParser::parseFile(self::MODULE . '/module.jsonc');
    }

    public function testIsAnAddonWithMinimalDependencies(): void
    {
        $module = ModuleDefinition::fromArray(self::module());

        $this->assertSame('economy.salesRegistration', $module->id);
        $this->assertSame(ModuleKind::Addon, $module->kind);
        $this->assertSame(['core.system'], $module->dependencies, 'typ se nabízí podle aktivního modulu země, ne přes závislost');
    }

    public function testIsNotPartOfTheBaseInstallation(): void
    {
        $base = JsoncParser::parseFile(self::MODULES . '/install/base/module.jsonc');

        $this->assertNotContains('economy.salesRegistration', $base['dependencies'] ?? []);
    }

    public function testEveryConfigFileExistsAndParses(): void
    {
        foreach (self::module()['config'] ?? [] as $item) {
            $file = self::MODULE . '/' . $item['file'];
            $this->assertFileExists($file, $item['id']);
            $this->assertIsArray(JsoncParser::parseFile($file), $item['id']);
            $this->assertStringStartsWith('economy.salesRegistration.', $item['id']);
        }
    }

    public function testEngineMapIsDeclaredByTheAddon(): void
    {
        $files = array_column(self::module()['config'] ?? [], 'file', 'id');

        $this->assertSame('config/engines.jsonc', $files[SalesRegistrationTypes::ENGINES_CFG] ?? null);
    }

    /** Každý engine z mapy patří typu, který některý modul země deklaruje. */
    public function testEveryMappedTypeIsDeclaredByItsCountryModule(): void
    {
        $engines = JsoncParser::parseFile(self::MODULE . '/config/engines.jsonc');

        foreach ($engines as $typeId => $class) {
            $country = explode('.', (string) $typeId)[0];
            $file = self::MODULES . "/world/{$country}/config/salesRegistrationTypes.jsonc";
            $this->assertFileExists($file, "typ {$typeId}: modul země world.{$country} typy nedeklaruje");
            $this->assertArrayHasKey($typeId, JsoncParser::parseFile($file));
            $this->assertStringStartsWith('Shipard\\Module\\Economy\\SalesRegistration\\', (string) $class);
            $this->assertTrue(class_exists((string) $class), "typ {$typeId}: třída engine {$class} neexistuje");
        }
        $this->addToAssertionCount(1);
    }
}
