<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Module;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\ModuleFormTab;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleUiExtensionRules;
use Shipard\Core\Viewer\DetailExtension;
use Shipard\Core\Viewer\RowDecorator;

/**
 * Pravidla rozšíření UI z modulů (#172 D16–D19) — na syntetických definicích
 * nad fixturovými třídami (`tests/Fixtures/modules/test/uiExtensions`)
 * a nad skutečným stromem `modules/`.
 */
class ModuleUiExtensionRulesTest extends TestCase
{
    use UiExtensionFixtures;

    private const DETAIL = self::UI_NAMESPACE . 'FixtureDetailExtension';
    private const FORM_TAB = self::UI_NAMESPACE . 'FixtureFormTab';
    private const ROW = self::UI_NAMESPACE . 'FixtureRowDecorator';

    protected function setUp(): void
    {
        $this->setUpUiExtensionFixtures();
    }

    protected function tearDown(): void
    {
        $this->tearDownUiExtensionFixtures();
    }

    private function host(): ModuleDefinition
    {
        return ModuleDefinition::fromArray(['id' => 'test.uiHost', 'name' => 'host', 'tables' => [self::UI_TABLE]]);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $fields
     * @param list<string> $dependencies
     */
    private function addon(array $fields, array $dependencies = ['test.uiHost']): ModuleDefinition
    {
        return ModuleDefinition::fromArray(
            ['id' => 'test.uiExtensions', 'name' => 'addon', 'dependencies' => $dependencies] + $fields,
        );
    }

    /** @return array{table: string, class: string} */
    private function reg(string $class, string $table = self::UI_TABLE): array
    {
        return ['table' => $table, 'class' => $class];
    }

    public function testValidDeclarationsHaveNoViolations(): void
    {
        $this->assertSame([], ModuleUiExtensionRules::violations([
            $this->host(),
            $this->addon([
                'detailExtensions' => [$this->reg(self::DETAIL)],
                'formTabs' => [$this->reg(self::FORM_TAB)],
                'rowDecorators' => [$this->reg(self::ROW)],
            ]),
        ]));
    }

    public function testModuleWithoutExtensionsHasNoViolations(): void
    {
        $this->assertSame([], ModuleUiExtensionRules::violations([$this->host()]));
    }

    public function testFixtureTreeHasNoViolations(): void
    {
        $definitions = ModuleLoader::loadAllModules(self::uiFixtureResolver());

        $this->assertSame([], ModuleUiExtensionRules::violations($definitions));
    }

    public function testRealModuleTreeHasNoViolations(): void
    {
        $definitions = ModuleLoader::loadAllModules(new ModulePathResolver([self::uiMainModulesPath()]));
        $this->assertNotEmpty($definitions);

        $this->assertSame([], ModuleUiExtensionRules::violations($definitions));
    }

    public function testMissingClassIsAViolation(): void
    {
        $violations = ModuleUiExtensionRules::violations([
            $this->host(),
            $this->addon(['formTabs' => [$this->reg(self::UI_NAMESPACE . 'NoSuchTab')]]),
        ]);

        $this->assertSame(
            ["Module 'test.uiExtensions': formTabs[0] class '" . self::UI_NAMESPACE . "NoSuchTab' does not exist"],
            $violations,
        );
    }

    public function testClassMustImplementInterfaceOfItsKind(): void
    {
        $violations = ModuleUiExtensionRules::violations([
            $this->host(),
            $this->addon([
                'detailExtensions' => [$this->reg(self::UI_NAMESPACE . 'NotAnExtension'), $this->reg(self::ROW)],
                'formTabs' => [$this->reg(self::DETAIL)],
                'rowDecorators' => [$this->reg(self::FORM_TAB)],
            ]),
        ]);

        $this->assertCount(4, $violations);
        $this->assertStringContainsString('detailExtensions[0]', $violations[0]);
        $this->assertStringContainsString('does not implement ' . DetailExtension::class, $violations[0]);
        $this->assertStringContainsString('detailExtensions[1]', $violations[1]);
        $this->assertStringContainsString('does not implement ' . DetailExtension::class, $violations[1]);
        $this->assertStringContainsString('formTabs[0]', $violations[2]);
        $this->assertStringContainsString('does not implement ' . ModuleFormTab::class, $violations[2]);
        $this->assertStringContainsString('rowDecorators[0]', $violations[3]);
        $this->assertStringContainsString('does not implement ' . RowDecorator::class, $violations[3]);
    }

    public function testClassOutsideModuleNamespaceIsAViolation(): void
    {
        $violations = ModuleUiExtensionRules::violations([
            ModuleDefinition::fromArray([
                'id' => 'test.uiHost',
                'name' => 'host',
                'tables' => [self::UI_TABLE],
                // třída jiného modulu a třída jádra
                'detailExtensions' => [$this->reg(self::DETAIL)],
                'rowDecorators' => [$this->reg(RowDecorator::class)],
            ]),
        ]);

        $this->assertCount(2, $violations);
        $this->assertStringContainsString('is outside the module namespace Shipard\\Module\\Test\\UiHost\\', $violations[0]);
        $this->assertStringContainsString('is outside the module namespace Shipard\\Module\\Test\\UiHost\\', $violations[1]);
    }

    public function testClassThatNeedsConstructorArgumentsIsAViolation(): void
    {
        $violations = ModuleUiExtensionRules::violations([
            $this->host(),
            $this->addon(['detailExtensions' => [$this->reg(self::UI_NAMESPACE . 'NeedsArgumentsExtension')]]),
        ]);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('cannot be created without constructor arguments', $violations[0]);
    }

    public function testTableMustBelongToModuleOrItsDependency(): void
    {
        $unknown = ModuleUiExtensionRules::violations([
            $this->host(),
            $this->addon(['rowDecorators' => [$this->reg(self::ROW, 'no_such_table')]]),
        ]);
        $this->assertSame(
            ["Module 'test.uiExtensions': rowDecorators[0] table 'no_such_table' is neither a table of the module"
                . ' nor of a module it depends on'],
            $unknown,
        );

        // Tabulka existuje, ale modul na jejím vlastníkovi nezávisí.
        $independent = ModuleUiExtensionRules::violations([
            $this->host(),
            $this->addon(['rowDecorators' => [$this->reg(self::ROW)]], dependencies: []),
        ]);
        $this->assertCount(1, $independent);
        $this->assertStringContainsString("table '" . self::UI_TABLE . "'", $independent[0]);
    }

    public function testTableOfTransitiveDependencyAndOwnTableAreAllowed(): void
    {
        $this->assertSame([], ModuleUiExtensionRules::violations([
            $this->host(),
            ModuleDefinition::fromArray(['id' => 'test.middle', 'name' => 'middle', 'dependencies' => ['test.uiHost']]),
            // přes prostředníka + vlastní tabulka modulu
            ModuleDefinition::fromArray([
                'id' => 'test.uiExtensions',
                'name' => 'addon',
                'dependencies' => ['test.middle'],
                'tables' => ['test_uiextensions_own'],
                'detailExtensions' => [$this->reg(self::DETAIL), $this->reg(self::DETAIL, 'test_uiextensions_own')],
            ]),
        ]));
    }

    public function testSameClassTwiceForOneTableIsAViolation(): void
    {
        $violations = ModuleUiExtensionRules::violations([
            $this->host(),
            $this->addon(['detailExtensions' => [$this->reg(self::DETAIL), $this->reg(self::DETAIL) + ['order' => 10]]]),
        ]);

        $this->assertSame(
            ["Module 'test.uiExtensions': detailExtensions[1] class '" . self::DETAIL
                . "' is already registered for table '" . self::UI_TABLE . "'"],
            $violations,
        );
    }
}
