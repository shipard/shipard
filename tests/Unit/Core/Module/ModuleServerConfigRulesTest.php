<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Module;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Config\ServerConfigSection;
use Shipard\Core\Module\ModuleClassLoader;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleServerConfigRules;

/**
 * Pravidla sekcí `server.json` z modulů (#172 D7, D10) — na syntetických
 * definicích nad fixturovými třídami (`tests/Fixtures/modules/test/serverConfig*`)
 * a nad skutečným stromem `modules/`.
 */
class ModuleServerConfigRulesTest extends TestCase
{
    private const SECTION = 'Shipard\\Module\\Test\\ServerConfig\\FixtureServerConfig';
    private const OTHER = 'Shipard\\Module\\Test\\ServerConfig\\FixtureOtherServerConfig';
    private const MISMATCH = 'Shipard\\Module\\Test\\ServerConfig\\KeyMismatchServerConfig';
    private const NOT_A_SECTION = 'Shipard\\Module\\Test\\ServerConfig\\NotASection';
    private const TWO = 'Shipard\\Module\\Test\\ServerConfigTwo\\FixtureTwoServerConfig';
    private const DUPLICATE = 'Shipard\\Module\\Test\\ServerConfigTwo\\DuplicateSectionServerConfig';
    private const ROOT = 'Shipard\\Module\\Test\\ServerConfigTwo\\FixtureRootServerConfig';

    private static function mainModulesPath(): string
    {
        return dirname(__DIR__, 4) . '/modules';
    }

    private static function fixtureModulesPath(): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/modules';
    }

    protected function setUp(): void
    {
        // Fixturové třídy leží ve vlastním kořeni modulů.
        ModuleClassLoader::register(new ModulePathResolver([self::mainModulesPath(), self::fixtureModulesPath()]));
    }

    protected function tearDown(): void
    {
        ModuleClassLoader::register(new ModulePathResolver([self::mainModulesPath()]));
    }

    /** @param list<array{key: string, class: string}> $sections */
    private function module(string $id, array $sections): ModuleDefinition
    {
        return ModuleDefinition::fromArray(['id' => $id, 'name' => $id, 'serverConfig' => $sections]);
    }

    /**
     * @param list<ModuleDefinition> $definitions
     * @param list<string> $coreKeys
     * @return list<string>
     */
    private function violations(array $definitions, array $coreKeys = ['host', 'mail']): array
    {
        return ModuleServerConfigRules::violations($definitions, $coreKeys);
    }

    public function testValidDeclarationsHaveNoViolations(): void
    {
        $this->assertSame([], $this->violations([
            // dvě sekce pod společným prvním segmentem nekolidují
            $this->module('test.serverConfig', [
                ['key' => 'fixture.section', 'class' => self::SECTION],
                ['key' => 'fixture.other', 'class' => self::OTHER],
            ]),
            // `fixture` není prefix `fixtureTwo` — porovnává se po segmentech
            $this->module('test.serverConfigTwo', [['key' => 'fixtureTwo', 'class' => self::TWO]]),
        ]));
    }

    public function testModuleWithoutSectionsHasNoViolations(): void
    {
        $this->assertSame([], $this->violations([
            ModuleDefinition::fromArray(['id' => 'test.serverConfig', 'name' => 'x']),
        ]));
    }

    public function testFixtureTreeHasNoViolations(): void
    {
        $definitions = ModuleLoader::loadAllModules(new ModulePathResolver([self::fixtureModulesPath()]));

        $this->assertSame([], $this->violations($definitions));
    }

    public function testModuleNamespaceOfModule(): void
    {
        $this->assertSame('Shipard\\Module\\Economy\\Vat\\', ModuleServerConfigRules::moduleNamespace('economy.vat'));
        $this->assertSame(
            'Shipard\\Module\\Economy\\ExchangeRates\\',
            ModuleServerConfigRules::moduleNamespace('economy.exchangeRates'),
        );
    }

    public function testCollisionIsComparedBySegments(): void
    {
        $this->assertTrue(ModuleServerConfigRules::collide('ai', 'ai'));
        $this->assertTrue(ModuleServerConfigRules::collide('ai', 'ai.analysis'));
        $this->assertTrue(ModuleServerConfigRules::collide('ai.analysis', 'ai'));
        $this->assertTrue(ModuleServerConfigRules::collide('registry.persons.cache', 'registry.persons'));
        $this->assertFalse(ModuleServerConfigRules::collide('ai.analysis', 'ai.chat'));
        $this->assertFalse(ModuleServerConfigRules::collide('ai', 'aiChat'));
        $this->assertFalse(ModuleServerConfigRules::collide('ai.analysis', 'ai.analysisQueue'));
    }

    public function testMissingClassIsAViolation(): void
    {
        $violations = $this->violations([
            $this->module('test.serverConfig', [
                ['key' => 'fixture.section', 'class' => 'Shipard\\Module\\Test\\ServerConfig\\NoSuchServerConfig'],
            ]),
        ]);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString("Module 'test.serverConfig': serverConfig[0] 'fixture.section' class", $violations[0]);
        $this->assertStringContainsString('does not exist', $violations[0]);
    }

    public function testClassThatIsNotASectionIsAViolation(): void
    {
        $violations = $this->violations([
            $this->module('test.serverConfig', [['key' => 'fixture.section', 'class' => self::NOT_A_SECTION]]),
        ]);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('does not implement ' . ServerConfigSection::class, $violations[0]);
    }

    public function testClassOutsideModuleNamespaceIsAViolation(): void
    {
        $violations = $this->violations([
            // třída jiného modulu
            $this->module('test.serverConfig', [['key' => 'fixtureTwo', 'class' => self::TWO]]),
            // třída jádra
            $this->module('test.serverConfigTwo', [['key' => 'other', 'class' => ServerConfig::class]]),
        ]);

        $this->assertCount(2, $violations);
        $this->assertStringContainsString('is outside the module namespace Shipard\\Module\\Test\\ServerConfig\\', $violations[0]);
        $this->assertStringContainsString('is outside the module namespace Shipard\\Module\\Test\\ServerConfigTwo\\', $violations[1]);
    }

    public function testKeyOfClassMustMatchDeclaration(): void
    {
        $violations = $this->violations([
            $this->module('test.serverConfig', [['key' => 'fixture.declared', 'class' => self::MISMATCH]]),
        ]);

        $this->assertSame(
            ["Module 'test.serverConfig': serverConfig[0] 'fixture.declared' class '" . self::MISMATCH . "' reports key 'fixture.actual'"],
            $violations,
        );
    }

    public function testSamePathInTwoModulesIsAViolation(): void
    {
        $violations = $this->violations([
            $this->module('test.serverConfig', [['key' => 'fixture.section', 'class' => self::SECTION]]),
            $this->module('test.serverConfigTwo', [['key' => 'fixture.section', 'class' => self::DUPLICATE]]),
        ]);

        $this->assertSame(
            ["Module 'test.serverConfigTwo': serverConfig[0] 'fixture.section' is already declared by module 'test.serverConfig'"],
            $violations,
        );
    }

    public function testSamePathTwiceInOneModuleIsAViolation(): void
    {
        $violations = $this->violations([
            $this->module('test.serverConfig', [
                ['key' => 'fixture.section', 'class' => self::SECTION],
                ['key' => 'fixture.section', 'class' => self::SECTION],
            ]),
        ]);

        $this->assertSame(
            ["Module 'test.serverConfig': serverConfig[1] 'fixture.section' is already declared by this module"],
            $violations,
        );
    }

    public function testPathThatIsAPrefixOfAnotherIsAViolation(): void
    {
        // Pořadí modulů podle id: test.serverConfig (obě podsekce) před
        // test.serverConfigTwo (`fixture`) — prefix koliduje s oběma.
        $violations = $this->violations([
            $this->module('test.serverConfigTwo', [['key' => 'fixture', 'class' => self::ROOT]]),
            $this->module('test.serverConfig', [
                ['key' => 'fixture.section', 'class' => self::SECTION],
                ['key' => 'fixture.other', 'class' => self::OTHER],
            ]),
        ]);

        $this->assertSame(
            [
                "Module 'test.serverConfigTwo': serverConfig[0] 'fixture' collides with 'fixture.section' declared by module 'test.serverConfig'",
                "Module 'test.serverConfigTwo': serverConfig[0] 'fixture' collides with 'fixture.other' declared by module 'test.serverConfig'",
            ],
            $violations,
        );
    }

    public function testLongerPathUnderDeclaredSectionIsAViolation(): void
    {
        $violations = $this->violations([
            $this->module('test.serverConfigTwo', [
                ['key' => 'fixture', 'class' => self::ROOT],
                ['key' => 'fixture.section', 'class' => self::DUPLICATE],
            ]),
        ]);

        $this->assertSame(
            ["Module 'test.serverConfigTwo': serverConfig[1] 'fixture.section' collides with 'fixture' declared by this module"],
            $violations,
        );
    }

    public function testPathStartingWithCoreKeyIsAViolation(): void
    {
        $definitions = [
            $this->module('test.serverConfig', [['key' => 'fixture.section', 'class' => self::SECTION]]),
            $this->module('test.serverConfigTwo', [['key' => 'fixtureTwo', 'class' => self::TWO]]),
        ];

        $this->assertSame(
            ["Module 'test.serverConfig': serverConfig[0] 'fixture.section' starts with 'fixture', a server.json key read by the core"],
            $this->violations($definitions, ['host', 'fixture']),
        );
        // jen celý první segment — `fixture` není `fixtureTwo`
        $this->assertSame(
            ["Module 'test.serverConfigTwo': serverConfig[0] 'fixtureTwo' starts with 'fixtureTwo', a server.json key read by the core"],
            $this->violations($definitions, ['fixtureTwo']),
        );
    }

    public function testRealModuleTreeHasNoViolations(): void
    {
        $definitions = ModuleLoader::loadAllModules(new ModulePathResolver([self::mainModulesPath()]));
        $this->assertNotEmpty($definitions);

        $this->assertSame([], ModuleServerConfigRules::violations($definitions, ServerConfig::CORE_KEYS));
    }
}
