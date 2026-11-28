<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Module;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Module\ModuleClassLoader;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleProvisioner;
use Shipard\Core\Module\ModuleProvisionerRules;

/**
 * Pravidla provisionerů z modulů (#174 D28) — na syntetických definicích nad
 * fixturovými třídami (`tests/Fixtures/modules/test/provisioners`) a nad
 * skutečným stromem `modules/`.
 */
class ModuleProvisionerRulesTest extends TestCase
{
    private const KINDS = 'Shipard\\Module\\Test\\Provisioners\\FixtureKindsProvisioner';
    private const NOT_A_PROVISIONER = 'Shipard\\Module\\Test\\Provisioners\\NotAProvisioner';
    private const NEEDS_ARGUMENTS = 'Shipard\\Module\\Test\\Provisioners\\NeedsArgumentsProvisioner';

    private static function mainModulesPath(): string
    {
        return dirname(__DIR__, 4) . '/modules';
    }

    protected function setUp(): void
    {
        // Fixturové třídy leží ve vlastním kořeni modulů.
        ModuleClassLoader::register(new ModulePathResolver([
            self::mainModulesPath(),
            dirname(__DIR__, 3) . '/Fixtures/modules',
        ]));
    }

    protected function tearDown(): void
    {
        ModuleClassLoader::register(new ModulePathResolver([self::mainModulesPath()]));
    }

    /** @param list<string> $classes */
    private function module(string $id, array $classes): ModuleDefinition
    {
        return ModuleDefinition::fromArray([
            'id' => $id,
            'name' => $id,
            'provisioners' => array_map(static fn(string $class): array => ['class' => $class], $classes),
        ]);
    }

    public function testValidDeclarationHasNoViolations(): void
    {
        $this->assertSame([], ModuleProvisionerRules::violations([
            $this->module('test.provisioners', [self::KINDS]),
            ModuleDefinition::fromArray(['id' => 'test.plain', 'name' => 'test.plain']),
        ]));
    }

    public function testFixtureTreeHasNoViolations(): void
    {
        $definitions = ModuleLoader::loadAllModules(
            new ModulePathResolver([dirname(__DIR__, 3) . '/Fixtures/modules']),
        );

        $this->assertSame([], ModuleProvisionerRules::violations($definitions));
    }

    public function testModuleNamespace(): void
    {
        $this->assertSame('Shipard\\Module\\Facility\\Buildings\\', ModuleProvisionerRules::moduleNamespace('facility.buildings'));
        $this->assertSame('Shipard\\Module\\Economy\\WorkOrders\\', ModuleProvisionerRules::moduleNamespace('economy.workOrders'));
    }

    public function testMissingClassIsAViolation(): void
    {
        $class = 'Shipard\\Module\\Test\\Provisioners\\NoSuchProvisioner';

        $this->assertSame(
            ["Module 'test.provisioners': provisioners class '{$class}' does not exist"],
            ModuleProvisionerRules::violations([$this->module('test.provisioners', [$class])]),
        );
    }

    public function testClassThatIsNotAProvisionerIsAViolation(): void
    {
        $this->assertSame(
            ["Module 'test.provisioners': provisioners class '" . self::NOT_A_PROVISIONER
                . "' does not implement " . ModuleProvisioner::class],
            ModuleProvisionerRules::violations([$this->module('test.provisioners', [self::NOT_A_PROVISIONER])]),
        );
    }

    public function testClassOfAnotherModuleIsAViolation(): void
    {
        // Modul zakládá jen svá data — třída jiného modulu (i závislosti) neprojde.
        $this->assertSame(
            ["Module 'test.other': provisioners class '" . self::KINDS
                . "' is outside the module namespace Shipard\\Module\\Test\\Other\\"],
            ModuleProvisionerRules::violations([$this->module('test.other', [self::KINDS])]),
        );
    }

    public function testConstructorWithRequiredArgumentsIsAViolation(): void
    {
        $this->assertSame(
            ["Module 'test.provisioners': provisioners class '" . self::NEEDS_ARGUMENTS
                . "' has a constructor with required arguments"],
            ModuleProvisionerRules::violations([$this->module('test.provisioners', [self::NEEDS_ARGUMENTS])]),
        );
    }

    public function testRealModuleTreeHasNoViolations(): void
    {
        $definitions = ModuleLoader::loadAllModules(new ModulePathResolver([self::mainModulesPath()]));
        $this->assertNotEmpty($definitions);

        $this->assertSame([], ModuleProvisionerRules::violations($definitions));
    }
}
