<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Module;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleKindRules;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;

/**
 * Pravidla mezi druhy modulů (#170 D4) — na syntetických definicích a nad
 * skutečným stromem `modules/`.
 */
class ModuleKindRulesTest extends TestCase
{
    /** @param list<string> $dependencies */
    private function module(string $id, array $dependencies = [], bool $addon = false): ModuleDefinition
    {
        return ModuleDefinition::fromArray(
            ['id' => $id, 'name' => $id, 'dependencies' => $dependencies] + ($addon ? ['kind' => 'addon'] : []),
        );
    }

    public function testValidTreeHasNoViolations(): void
    {
        $this->assertSame([], ModuleKindRules::violations([
            $this->module('install.base', ['core.system', 'economy.docs']),
            $this->module('core.system'),
            $this->module('economy.docs', ['core.system']),
            $this->module('facility.core', ['economy.docs'], addon: true),
        ]));
    }

    public function testCoreModuleDependingOnAddonIsAViolation(): void
    {
        $violations = ModuleKindRules::violations([
            $this->module('install.dedicated', ['economy.docs']),
            $this->module('economy.docs', ['facility.core']),
            $this->module('facility.core', [], addon: true),
        ]);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString("Core module 'economy.docs' depends on add-on 'facility.core'", $violations[0]);
    }

    public function testCoreModuleDependingOnAddonTransitivelyIsAViolation(): void
    {
        $violations = ModuleKindRules::violations([
            $this->module('install.dedicated', ['economy.reports']),
            $this->module('economy.reports', ['economy.docs']),
            $this->module('economy.docs', ['facility.core']),
            $this->module('facility.core', [], addon: true),
        ]);

        $this->assertCount(2, $violations);
        $this->assertStringContainsString("Core module 'economy.docs' depends on add-on 'facility.core'", $violations[0]);
        $this->assertStringContainsString("Core module 'economy.reports' depends on add-on 'facility.core'", $violations[1]);
    }

    public function testBaseInstallModuleDependingOnAddonIsAViolation(): void
    {
        $violations = ModuleKindRules::violations([
            $this->module('install.base', ['core.system', 'facility.core']),
            $this->module('core.system'),
            $this->module('facility.core', [], addon: true),
        ]);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString("Install module 'install.base' depends on add-on 'facility.core'", $violations[0]);
    }

    public function testBaseInstallModuleDependingOnAddonTransitivelyIsAViolation(): void
    {
        $violations = ModuleKindRules::violations([
            $this->module('install.base', ['facility.extra']),
            $this->module('facility.extra', ['facility.core'], addon: true),
            $this->module('facility.core', [], addon: true),
        ]);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString(
            "Install module 'install.base' depends on add-on 'facility.core', 'facility.extra'",
            $violations[0],
        );
    }

    public function testDedicatedInstallModuleMayBringAnAddon(): void
    {
        $this->assertSame([], ModuleKindRules::violations([
            $this->module('install.base', ['core.system']),
            $this->module('install.facility', ['core.system', 'facility.core']),
            $this->module('core.system'),
            $this->module('facility.core', ['core.system'], addon: true),
        ]));
    }

    public function testOrphanModuleIsAViolation(): void
    {
        $violations = ModuleKindRules::violations([
            $this->module('install.base', ['core.system']),
            $this->module('core.system'),
            $this->module('core.help', ['core.system']),
        ]);

        $this->assertSame(
            ["Module 'core.help' is not reachable from any install module and is neither an add-on nor a dependency of one"],
            $violations,
        );
    }

    public function testAddonReachableOnlyThroughAnotherAddonPasses(): void
    {
        $this->assertSame([], ModuleKindRules::violations([
            $this->module('install.base', ['core.system']),
            $this->module('core.system'),
            $this->module('facility.rooms', ['facility.core'], addon: true),
            $this->module('facility.core', ['core.system'], addon: true),
        ]));
    }

    public function testCoreModuleReachableOnlyAsAddonDependencyPasses(): void
    {
        $this->assertSame([], ModuleKindRules::violations([
            $this->module('install.base', ['core.system']),
            $this->module('core.system'),
            $this->module('base.places', ['core.system']),
            $this->module('facility.core', ['base.places'], addon: true),
        ]));
    }

    public function testUnknownDependencyAndCycleDoNotBreakTheRules(): void
    {
        $this->assertSame([], ModuleKindRules::violations([
            $this->module('install.base', ['core.a', 'core.missing']),
            $this->module('core.a', ['core.b']),
            $this->module('core.b', ['core.a']),
        ]));
    }

    public function testRealModuleTreeHasNoViolations(): void
    {
        // Konstrukce resolveru zároveň ověří, že hlavní strom nemá adresář
        // s module.jsonc a neplatným jménem skupiny nebo modulu (#170 D11).
        $resolver = new ModulePathResolver([dirname(__DIR__, 4) . '/modules']);
        $definitions = ModuleLoader::loadAllModules($resolver);

        $this->assertNotEmpty($definitions);
        $this->assertSame([], ModuleKindRules::violations($definitions));
    }
}
