<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Assets\AssetsForm;

/**
 * Formulář karty: předvyplnění z typu, skrytí a vynulování ceny
 * u dlouhodobého druhu, vlastník jen u cizího majetku.
 */
class AssetsFormTest extends TestCase
{
    private const CATEGORIES = [
        'small'    => ['name' => 'Drobný majetek', 'longTerm' => false],
        'tangible' => ['name' => 'Dlouhodobý hmotný', 'longTerm' => true, 'depreciable' => true],
    ];

    private static ?array $czRules = null;

    /** @param array<string, mixed>|null $typeRow řádek typu vrácený z DB */
    private function form(?array $typeRow = null): AssetsForm
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static fn(string $sql, mixed ...$params): ?array => str_contains($sql, 'economy_assets_types') ? $typeRow : null,
        );
        self::$czRules ??= JsoncParser::parseFile(__DIR__ . '/../../../../../modules/world/assets/config/assets-cz.jsonc');
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['economy.assets.categories', self::CATEGORIES],
            ['economy.assets.trackingKinds', ['single' => ['name' => 'Jednotlivá věc']]],
            ['economy.assets.accMethods', ['as_tax' => ['name' => 'Stejně jako daňové'], 'time' => ['name' => 'Časová']]],
            ['world.assets.cz', self::$czRules],
        ]);

        $form = new AssetsForm('economy_assets_assets');
        $form->setDb($db);
        $form->setConfig($config);
        $form->setTableDef(TableDefinition::fromArray(JsoncParser::parseFile(
            __DIR__ . '/../../../../../modules/economy/assets/tables/economy_assets_assets.jsonc',
        )));
        return $form;
    }

    private function element(FormDefinition $def, string $column, int $tab = 0): FormElement
    {
        foreach ($def->tabs[$tab]->sections[0]->columns[0]->elements as $el) {
            if ($el->column === $column) {
                return $el;
            }
        }
        $this->fail("Element {$column} not found");
    }

    // --- tab Odpisy (D30) ------------------------------------------------------

    public function testDepreciableCardHasDepreciationTabWithCountryRules(): void
    {
        $def = $this->form()->buildFormDefinition(['category' => 'tangible', 'tax_method' => 'straight'], true);

        $this->assertSame(['card', 'depreciation', 'attachments'], array_map(static fn($t) => $t->id, $def->tabs));
        $methods = array_column($this->element($def, 'tax_method', 1)->options, 'value');
        $this->assertSame(['straight', 'accelerated', 'extraordinary', 'accounting', 'none'], $methods);
        $this->assertContains('cz-2', array_column($this->element($def, 'tax_rule', 1)->options, 'value'));
        $this->assertFalse($this->element($def, 'tax_rule', 1)->hidden);
        $this->assertTrue($this->element($def, 'acc_months', 1)->hidden);

        // Bez pravidla (podle účetnictví) a s časovou účetní metodou.
        $def = $this->form()->buildFormDefinition(['category' => 'tangible', 'tax_method' => 'accounting', 'acc_method' => 'time'], true);
        $this->assertTrue($this->element($def, 'tax_rule', 1)->hidden);
        $this->assertFalse($this->element($def, 'acc_months', 1)->hidden);
        $this->assertTrue($this->element($def, 'acc_months', 1)->required);

        // Drobný majetek tab nemá.
        $def = $this->form()->buildFormDefinition(['category' => 'small'], true);
        $this->assertSame(['card', 'attachments'], array_map(static fn($t) => $t->id, $def->tabs));
    }

    public function testMethodChangeResetsRuleOutsideNewOffer(): void
    {
        $form = $this->form();
        $result = $form->recalculate('tax_method', ['category' => 'tangible', 'tax_method' => 'extraordinary', 'tax_rule' => 'cz-2']);
        $this->assertNull($result->data['tax_rule']);

        $result = $form->recalculate('tax_method', ['category' => 'tangible', 'tax_method' => 'accelerated', 'tax_rule' => 'cz-2']);
        $this->assertSame('cz-2', $result->data['tax_rule']);

        $result = $form->recalculate('acc_method', ['category' => 'tangible', 'acc_method' => 'as_tax', 'acc_months' => 60]);
        $this->assertNull($result->data['acc_months']);
    }

    public function testStoredLongTermCardHasEventsSubtableWithIndependentRows(): void
    {
        $def = $this->form()->buildFormDefinition(['id' => 7, 'category' => 'tangible', 'tax_method' => 'straight'], false);
        $events = $def->tabs[2];

        $this->assertSame('subtable', $events->type);
        $this->assertSame('economy_assets_events', $events->subtable['table']);
        $this->assertTrue($events->toArray()['subtable']['independent_rows']);
        $this->assertTrue($this->element($def, 'acquired_date')->readOnly);
        $this->assertTrue($this->element($def, 'disposed_date')->readOnly);
    }

    public function testNewSmallAssetShowsPriceAndHidesOwner(): void
    {
        $def = $this->form()->buildFormDefinition(['category' => 'small', 'is_foreign' => 0], true);

        $this->assertFalse($this->element($def, 'price')->hidden);
        $this->assertTrue($this->element($def, 'owner')->hidden);
        $this->assertFalse($this->element($def, 'accounting_group')->required);
        $this->assertSame('attachments', $def->tabs[1]->type);
        $this->assertSame(450, $def->tabs[1]->tableId);
    }

    public function testLongTermHidesPriceAndRequiresGroup(): void
    {
        $def = $this->form()->buildFormDefinition(['category' => 'tangible'], true);

        $this->assertTrue($this->element($def, 'price')->hidden);
        $this->assertTrue($this->element($def, 'accounting_group')->required);
    }

    public function testCategoryChangeToLongTermClearsPrice(): void
    {
        $result = $this->form()->recalculate('category', ['category' => 'tangible', 'price' => '100.00']);

        $this->assertNull($result->data['price']);
        $this->assertTrue($this->element($result->formDefinition, 'price')->hidden);
    }

    public function testTypeDefaultsFillCategoryOnNewCardAndEmptyGroup(): void
    {
        $form = $this->form(['default_category' => 'tangible', 'default_accounting_group' => 7]);
        $result = $form->recalculate('asset_type', [
            'asset_type' => 3, 'category' => 'small', 'accounting_group' => null, 'price' => '50.00',
        ]);

        $this->assertSame('tangible', $result->data['category']);
        $this->assertSame(7, $result->data['accounting_group']);
        $this->assertNull($result->data['price'], 'dlouhodobý druh z typu nuluje cenu');
    }

    public function testTypeDefaultsDoNotOverwriteExplicitValues(): void
    {
        $form = $this->form(['default_category' => 'tangible', 'default_accounting_group' => 7]);

        // existující karta → druh se nemění; vyplněná skupina zůstává
        $result = $form->recalculate('asset_type', [
            'id' => 12, 'asset_type' => 3, 'category' => 'small', 'accounting_group' => 2,
        ]);
        $this->assertSame('small', $result->data['category']);
        $this->assertSame(2, $result->data['accounting_group']);

        // nová karta, druh už změněný uživatelem → zůstává
        $result = $form->recalculate('asset_type', ['asset_type' => 3, 'category' => 'tangible']);
        $this->assertSame('tangible', $result->data['category']);
    }

    public function testForeignToggleShowsOwnerAndClearsItWhenUnchecked(): void
    {
        $form = $this->form();

        $on = $form->recalculate('is_foreign', ['is_foreign' => 1, 'owner' => null, 'category' => 'small']);
        $ownerEl = $this->element($on->formDefinition, 'owner');
        $this->assertFalse($ownerEl->hidden);
        $this->assertTrue($ownerEl->required);

        $off = $form->recalculate('is_foreign', ['is_foreign' => 0, 'owner' => 5, 'category' => 'small']);
        $this->assertNull($off->data['owner']);
        $this->assertTrue($this->element($off->formDefinition, 'owner')->hidden);
    }
}
