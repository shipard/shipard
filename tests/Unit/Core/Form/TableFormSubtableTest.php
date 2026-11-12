<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\FormTab;
use Shipard\Core\Form\TableForm;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;
use Shipard\Tests\Fixtures\Core\Form\StubSubtableParentForm;

/**
 * Default renderer sub-tabulek (TableForm::renderSubtable) — výběr sloupců
 * z definice dětské tabulky, labely, zarovnání, formátování buněk,
 * stateStyle řádků s docStates.
 */
class TableFormSubtableTest extends TestCase
{
    private function childDef(array $extra = [], ?array $docStates = null): TableDefinition
    {
        $def = [
            'tableId' => 501,
            'name'    => 'Child',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
                ['id' => 'parent', 'name' => 'Parent', 'type' => 'int', 'reference' => 'parent_tbl'],
                ['id' => 'order_pos', 'name' => 'Order', 'type' => 'smallint', 'default' => 0],
                ['id' => 'name', 'name' => 'Name', 'formLabel' => 'Title', 'type' => 'varchar', 'length' => 100],
                ['id' => 'amount', 'name' => 'Amount', 'type' => 'numeric', 'precision' => 12, 'scale' => 2],
                ['id' => 'active', 'name' => 'Active', 'type' => 'boolean', 'default' => 0],
                ['id' => 'kind', 'name' => 'Kind', 'type' => 'enumInt', 'cfgItem' => 'test.kinds', 'default' => 0],
                ['id' => 'secret', 'name' => 'Secret', 'type' => 'varchar', 'length' => 64, 'sensitive' => true],
                ['id' => 'payload', 'name' => 'Payload', 'type' => 'json', 'nullable' => true],
                ['id' => 'created', 'name' => 'Created', 'type' => 'datetime', 'nullable' => true],
                ['id' => 'docState', 'name' => 'State', 'type' => 'tinyint', 'default' => 10, 'system' => true],
                ['id' => 'docStateMain', 'name' => 'State main', 'type' => 'tinyint', 'default' => 1, 'system' => true],
                ['id' => 'valid_from', 'name' => 'Valid from', 'type' => 'date', 'nullable' => true],
                ['id' => 'note', 'name' => 'Note', 'type' => 'text', 'nullable' => true],
                ['id' => 'extra', 'name' => 'Extra', 'type' => 'varchar', 'length' => 10, 'nullable' => true],
            ],
        ];
        if ($docStates !== null) {
            $def['docStates'] = $docStates;
        }
        return TableDefinition::fromArray(array_merge($def, $extra));
    }

    private function config(array $items): ConfigRuntime
    {
        return ConfigRuntimeFactory::fromItems($items);
    }

    private function form(TableDefinition $childDef, ?ConfigRuntime $config = null): TableForm
    {
        $form = new StubSubtableParentForm('parent_tbl');
        $form->setTables(['child_tbl' => $childDef]);
        if ($config !== null) {
            $form->setConfig($config);
        }
        return $form;
    }

    private function itemsTab(TableForm $form): FormTab
    {
        foreach ($form->buildFormDefinition(['id' => 1], false)->tabs as $tab) {
            if ($tab->id === 'items') {
                return $tab;
            }
        }
        $this->fail('items tab missing');
    }

    // ── Columns ──────────────────────────────────────────────────────────────

    public function testDefaultColumnsSkipTechnicalAndSensitiveAndCapAtSix(): void
    {
        $form = $this->form($this->childDef());
        $result = $form->renderSubtable($this->itemsTab($form), [], ['id' => 1]);

        $ids = array_column($result['columns'], 'id');
        // id (PK), parent (FK), order_pos, secret (sensitive), payload (json),
        // created, docState* (system) vypadnou; zbytek v pořadí definice, max 6.
        $this->assertSame(['name', 'amount', 'active', 'kind', 'valid_from', 'note'], $ids);
        $this->assertCount(TableForm::SUBTABLE_DEFAULT_MAX_COLUMNS, $result['columns']);
        $this->assertNull($result['order_column']);
        $this->assertSame([], $result['rows']);
    }

    public function testColumnLabelsPreferFormLabelAndNumericAlignsRight(): void
    {
        $form = $this->form($this->childDef());
        $columns = $form->renderSubtable($this->itemsTab($form), [], ['id' => 1])['columns'];
        $byId = array_column($columns, null, 'id');

        $this->assertSame('Title', $byId['name']['label']);
        $this->assertSame('Amount', $byId['amount']['label']);
        $this->assertSame('right', $byId['amount']['align']);
        $this->assertArrayNotHasKey('align', $byId['name']);
        // enumInt je číselný typ v DB, ale zobrazuje text → bez zarovnání vpravo
        $this->assertArrayNotHasKey('align', $byId['kind']);
        // první textový sloupec roste
        $this->assertTrue($byId['name']['grow']);
        $this->assertArrayNotHasKey('grow', $byId['note']);
    }

    public function testMissingChildDefinitionYieldsEmptyResult(): void
    {
        $form = new StubSubtableParentForm('parent_tbl');
        $result = $form->renderSubtable($this->itemsTab($form), [['id' => 1]], ['id' => 1]);
        $this->assertSame(['columns' => [], 'rows' => [], 'order_column' => null], $result);
    }

    // ── Cells ────────────────────────────────────────────────────────────────

    public function testCellsAreFormattedByColumnType(): void
    {
        $config = $this->config([
            'test.kinds' => ['0' => ['name' => 'Běžný'], '1' => ['name' => 'Speciální']],
            'core.system.formDefaults' => [
                'booleanYes' => ['name' => 'Ano'],
                'booleanNo'  => ['name' => 'Ne'],
            ],
        ]);
        $form = $this->form($this->childDef(), $config);
        $rows = [
            ['id' => 7, 'parent' => 1, 'name' => 'Ukázka', 'amount' => '1234.5', 'active' => 1,
             'kind' => 1, 'valid_from' => '2026-01-31', 'note' => null, 'secret' => 'x'],
            ['id' => 8, 'parent' => 1, 'name' => '', 'amount' => null, 'active' => 0,
             'kind' => 5, 'valid_from' => null, 'note' => 'poznámka'],
        ];
        $result = $form->renderSubtable($this->itemsTab($form), $rows, ['id' => 1]);

        $this->assertCount(2, $result['rows']);
        $first = $result['rows'][0];
        $this->assertSame(7, $first['id']);
        $this->assertSame([
            'name'       => 'Ukázka',
            'amount'     => '1 234,50',
            'active'     => 'Ano',
            'kind'       => 'Speciální',
            'valid_from' => '2026-01-31',
        ], $first['cells']);
        $this->assertArrayNotHasKey('secret', $first['cells']);
        $this->assertArrayNotHasKey('stateStyle', $first);

        $second = $result['rows'][1];
        // prázdné hodnoty = chybějící klíč, neznámý enum = surová hodnota
        $this->assertSame(['active' => 'Ne', 'kind' => '5', 'note' => 'poznámka'], $second['cells']);
    }

    public function testBooleanFallsBackToEnglishWithoutConfig(): void
    {
        $form = $this->form($this->childDef());
        $result = $form->renderSubtable(
            $this->itemsTab($form),
            [['id' => 1, 'active' => 1, 'kind' => 0]],
            ['id' => 1],
        );
        $this->assertSame('Yes', $result['rows'][0]['cells']['active']);
        // enum bez configu = surová hodnota
        $this->assertSame('0', $result['rows'][0]['cells']['kind']);
    }

    // ── docStates → stateStyle ──────────────────────────────────────────────

    public function testRowsOfDocStateTableCarryStateStyle(): void
    {
        $config = $this->config([
            'test.states' => [
                '10' => ['stateName' => 'Koncept', 'stateStyle' => 'concept'],
                '40' => ['stateName' => 'V pořádku', 'stateStyle' => 'done'],
                '70' => ['stateName' => 'V archívu', 'stateStyle' => 'archive'],
            ],
        ]);
        $childDef = $this->childDef(docStates: [
            'stateColumn' => 'docState', 'mainColumn' => 'docStateMain', 'cfgItem' => 'test.states',
        ]);
        $form = $this->form($childDef, $config);
        $result = $form->renderSubtable(
            $this->itemsTab($form),
            [
                ['id' => 1, 'name' => 'A', 'docState' => 70],
                ['id' => 2, 'name' => 'B', 'docState' => 40],
                ['id' => 3, 'name' => 'C', 'docState' => 99],
            ],
            ['id' => 1],
        );

        $this->assertSame('archive', $result['rows'][0]['stateStyle']);
        $this->assertSame('done', $result['rows'][1]['stateStyle']);
        $this->assertArrayNotHasKey('stateStyle', $result['rows'][2]);
        // stavové sloupce nejsou mezi zobrazenými
        $this->assertNotContains('docState', array_column($result['columns'], 'id'));
    }
}
