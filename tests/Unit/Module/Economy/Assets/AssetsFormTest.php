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
        'tangible' => ['name' => 'Dlouhodobý hmotný', 'longTerm' => true],
    ];

    /** @param array<string, mixed>|null $typeRow řádek typu vrácený z DB */
    private function form(?array $typeRow = null): AssetsForm
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static fn(string $sql, mixed ...$params): ?array => str_contains($sql, 'economy_assets_types') ? $typeRow : null,
        );
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['economy.assets.categories', self::CATEGORIES],
            ['economy.assets.trackingKinds', ['single' => ['name' => 'Jednotlivá věc']]],
        ]);

        $form = new AssetsForm('economy_assets_assets');
        $form->setDb($db);
        $form->setConfig($config);
        $form->setTableDef(TableDefinition::fromArray(JsoncParser::parseFile(
            __DIR__ . '/../../../../../modules/economy/assets/tables/economy_assets_assets.jsonc',
        )));
        return $form;
    }

    private function element(FormDefinition $def, string $column): FormElement
    {
        foreach ($def->tabs[0]->sections[0]->columns[0]->elements as $el) {
            if ($el->column === $column) {
                return $el;
            }
        }
        $this->fail("Element {$column} not found");
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
