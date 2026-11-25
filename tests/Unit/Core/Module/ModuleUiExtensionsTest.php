<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Module;

use PHPUnit\Framework\TestCase;
use Shipard\Api\FormLoader;
use Shipard\Api\ViewerLoader;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleUiExtension;
use Shipard\Core\Module\ModuleUiExtensions;
use Shipard\Module\Test\UiExtensions\FixtureDetailExtension;
use Shipard\Module\Test\UiExtensions\FixturePingAction;
use Shipard\Module\Test\UiExtensions\FixtureRowDecorator;

/**
 * Registr rozšíření UI z modulů (#172 D19): jen aktivní moduly zdroje dat,
 * pořadí `order` → id modulu → deklarace, izolace poskytovatele.
 */
class ModuleUiExtensionsTest extends TestCase
{
    use UiExtensionFixtures;

    protected function setUp(): void
    {
        $this->setUpUiExtensionFixtures();
    }

    protected function tearDown(): void
    {
        $this->tearDownUiExtensionFixtures();
    }

    /** @param array<string, list<array<string, mixed>>> $fields */
    private function module(string $id, array $fields): ModuleDefinition
    {
        return ModuleDefinition::fromArray(['id' => $id, 'name' => $id] + $fields);
    }

    /**
     * @param list<ModuleUiExtension> $extensions
     * @return list<string> "modul:třída"
     */
    private function labels(array $extensions): array
    {
        return array_map(static fn (ModuleUiExtension $e): string => $e->moduleId . ':' . $e->class, $extensions);
    }

    public function testEmptyRegistryHasNoExtensions(): void
    {
        $none = ModuleUiExtensions::none();

        foreach (array_keys(ModuleUiExtensions::KINDS) as $kind) {
            $this->assertSame([], $none->for($kind, self::UI_TABLE));
        }
    }

    public function testExtensionsAreKeptPerKindAndTable(): void
    {
        $extensions = ModuleUiExtensions::fromModules([
            $this->module('test.one', [
                'detailExtensions' => [['table' => 'a_b_c', 'class' => 'X\\Detail']],
                'formTabs' => [['table' => 'a_b_c', 'class' => 'X\\Tab'], ['table' => 'd_e_f', 'class' => 'X\\Other']],
                'rowDecorators' => [['table' => 'd_e_f', 'class' => 'X\\Row', 'order' => 10]],
            ]),
        ]);

        $this->assertSame(['test.one:X\\Detail'], $this->labels($extensions->for(ModuleUiExtensions::DETAIL, 'a_b_c')));
        $this->assertSame(['test.one:X\\Tab'], $this->labels($extensions->for(ModuleUiExtensions::FORM_TABS, 'a_b_c')));
        $this->assertSame(['test.one:X\\Other'], $this->labels($extensions->for(ModuleUiExtensions::FORM_TABS, 'd_e_f')));
        $this->assertSame([], $extensions->for(ModuleUiExtensions::DETAIL, 'd_e_f'));

        $row = $extensions->for(ModuleUiExtensions::ROW_DECORATORS, 'd_e_f')[0];
        $this->assertSame(ModuleUiExtensions::ROW_DECORATORS, $row->kind);
        $this->assertSame('d_e_f', $row->table);
        $this->assertSame(10, $row->order);
    }

    public function testOrderIsOrderThenModuleIdThenDeclaration(): void
    {
        // Moduly záměrně v opačném pořadí, než jaké má vyjít.
        $extensions = ModuleUiExtensions::fromModules([
            $this->module('test.zeta', [
                'detailExtensions' => [
                    ['table' => 'a_b_c', 'class' => 'Z\\Default'],
                    ['table' => 'a_b_c', 'class' => 'Z\\Early', 'order' => 100],
                ],
            ]),
            $this->module('test.alpha', [
                'detailExtensions' => [
                    ['table' => 'a_b_c', 'class' => 'A\\Second'],
                    ['table' => 'a_b_c', 'class' => 'A\\Late', 'order' => 900],
                    ['table' => 'a_b_c', 'class' => 'A\\Third', 'order' => 500],
                ],
            ]),
        ]);

        $this->assertSame([
            'test.zeta:Z\\Early',
            'test.alpha:A\\Second',
            'test.alpha:A\\Third',
            'test.zeta:Z\\Default',
            'test.alpha:A\\Late',
        ], $this->labels($extensions->for(ModuleUiExtensions::DETAIL, 'a_b_c')));
    }

    public function testOnlyActiveModulesContribute(): void
    {
        // Zdroj dat jen s hostitelským modulem: doplněk není zapnutý.
        $host = $this->uiExtensionsOf(['test.uiHost']);
        foreach (array_keys(ModuleUiExtensions::KINDS) as $kind) {
            $this->assertSame([], $host->for($kind, self::UI_TABLE), $kind);
        }

        $active = $this->uiExtensionsOf(['test.uiExtensions']);
        foreach (array_keys(ModuleUiExtensions::KINDS) as $kind) {
            // Fixtura má čtyři akce záznamu, od ostatních druhů po jednom.
            $expected = $kind === ModuleUiExtensions::RECORD_ACTIONS ? 4 : 1;
            $this->assertCount($expected, $active->for($kind, self::UI_TABLE), $kind);
            $this->assertSame('test.uiExtensions', $active->for($kind, self::UI_TABLE)[0]->moduleId);
        }
    }

    public function testRecordActionsCarryIdAndAdminFlagInRegistryOrder(): void
    {
        $actions = $this->uiExtensionsOf()->for(ModuleUiExtensions::RECORD_ACTIONS, self::UI_TABLE);

        $this->assertSame(
            [
                'test.uiExtensions.purge',
                'test.uiExtensions.ping',
                'test.uiExtensions.confirmPayment',
                'test.uiExtensions.uploadCertificate',
            ],
            array_map(static fn (ModuleUiExtension $e): string => $e->globalId(), $actions),
            'pořadí order → id modulu → deklarace',
        );
        $this->assertSame([true, false, false, false], array_column($actions, 'admin'));
        $this->assertSame(FixturePingAction::class, $actions[1]->class);
    }

    public function testRecordActionIsFoundByGlobalIdAndTable(): void
    {
        $extensions = $this->uiExtensionsOf();

        $ping = $extensions->recordAction(self::UI_TABLE, 'test.uiExtensions.ping');
        $this->assertNotNull($ping);
        $this->assertSame('ping', $ping->id);
        $this->assertSame(ModuleUiExtensions::RECORD_ACTIONS, $ping->kind);

        $this->assertNull($extensions->recordAction(self::UI_TABLE, 'test.uiExtensions.nope'));
        $this->assertNull($extensions->recordAction(self::UI_TABLE, 'ping'), 'krátké id nestačí');
        $this->assertNull($extensions->recordAction('other_table', 'test.uiExtensions.ping'), 'akce jiné tabulky');
        // Neaktivní modul: akci zdroj dat nezná.
        $this->assertNull($this->uiExtensionsOf(['test.uiHost'])->recordAction(self::UI_TABLE, 'test.uiExtensions.ping'));
    }

    public function testExtensionOfAnotherKindHasNoGlobalId(): void
    {
        $detail = $this->uiExtensionsOf()->for(ModuleUiExtensions::DETAIL, self::UI_TABLE)[0];

        $this->assertNull($detail->id);
        $this->expectException(\LogicException::class);
        $detail->globalId();
    }

    public function testLoadersTakeExtensionsFromModulesOfTheDataSource(): void
    {
        $viewers = ViewerLoader::load($this->uiDataSource(['test.uiExtensions']), self::uiFixtureResolver(), 'en');
        $this->assertCount(1, $viewers->uiExtensions()->for(ModuleUiExtensions::DETAIL, self::UI_TABLE));
        $this->assertCount(1, $viewers->uiExtensions()->for(ModuleUiExtensions::ROW_DECORATORS, self::UI_TABLE));

        $forms = FormLoader::load($this->uiDataSource(['test.uiExtensions']), self::uiFixtureResolver());
        $this->assertCount(1, $forms->uiExtensions()->for(ModuleUiExtensions::FORM_TABS, self::UI_TABLE));

        $viewers = ViewerLoader::load($this->uiDataSource(['test.uiHost']), self::uiFixtureResolver(), 'en');
        $this->assertSame([], $viewers->uiExtensions()->for(ModuleUiExtensions::DETAIL, self::UI_TABLE));
        $forms = FormLoader::load($this->uiDataSource(['test.uiHost']), self::uiFixtureResolver());
        $this->assertSame([], $forms->uiExtensions()->for(ModuleUiExtensions::FORM_TABS, self::UI_TABLE));
    }

    public function testCallPassesProviderInstanceAndReturnsResult(): void
    {
        $extensions = $this->uiExtensionsOf();
        $detail = $extensions->for(ModuleUiExtensions::DETAIL, self::UI_TABLE)[0];

        $seen = [];
        $result = $extensions->call($detail, static function (object $provider) use (&$seen): string {
            $seen[] = $provider;
            return 'ok';
        });
        $extensions->call($detail, static function (object $provider) use (&$seen): void {
            $seen[] = $provider;
        });

        $this->assertSame('ok', $result);
        $this->assertInstanceOf(FixtureDetailExtension::class, $seen[0]);
        $this->assertSame($seen[0], $seen[1], 'jedna instance poskytovatele na registr');
        $this->assertSame([], $this->uiExtensionFailures());
    }

    public function testProviderExceptionIsLoggedAndSkipped(): void
    {
        $extensions = $this->uiExtensionsOf();
        $row = $extensions->for(ModuleUiExtensions::ROW_DECORATORS, self::UI_TABLE)[0];

        $result = $extensions->call($row, static function (object $provider): never {
            throw new \RuntimeException('boom');
        });

        $this->assertNull($result);
        $failures = $this->uiExtensionFailures();
        $this->assertCount(1, $failures);
        $this->assertSame('test.uiExtensions', $failures[0]['module']);
        $this->assertSame(FixtureRowDecorator::class, $failures[0]['class']);
        $this->assertSame(self::UI_TABLE, $failures[0]['table']);
        $this->assertSame(ModuleUiExtensions::ROW_DECORATORS, $failures[0]['kind']);
        $this->assertSame(\RuntimeException::class, $failures[0]['exception']);
        $this->assertSame('boom', $failures[0]['message']);
    }

    public function testMissingClassAndWrongInterfaceAreLoggedAndSkipped(): void
    {
        $extensions = ModuleUiExtensions::fromModules([
            $this->module('test.uiExtensions', [
                'detailExtensions' => [
                    ['table' => self::UI_TABLE, 'class' => self::UI_NAMESPACE . 'NoSuchExtension'],
                    ['table' => self::UI_TABLE, 'class' => self::UI_NAMESPACE . 'NotAnExtension'],
                    // dekorátor řádku registrovaný jako rozšíření detailu
                    ['table' => self::UI_TABLE, 'class' => FixtureRowDecorator::class],
                    ['table' => self::UI_TABLE, 'class' => self::UI_NAMESPACE . 'NeedsArgumentsExtension'],
                ],
            ]),
        ]);

        $called = 0;
        foreach ($extensions->for(ModuleUiExtensions::DETAIL, self::UI_TABLE) as $extension) {
            $this->assertNull($extensions->call($extension, static function () use (&$called): string {
                $called++;
                return 'never';
            }));
        }

        $this->assertSame(0, $called);
        $messages = array_column($this->uiExtensionFailures(), 'message');
        $this->assertCount(4, $messages);
        $this->assertStringContainsString('does not exist', $messages[0]);
        $this->assertStringContainsString('does not implement', $messages[1]);
        $this->assertStringContainsString('does not implement', $messages[2]);
    }
}
