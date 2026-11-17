<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\FormTab;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Docs\Core\DocsHeadsForm;

/**
 * Sub-tabulka řádků dokladu (položková sada) — sloupce podle vat_mode
 * rodiče, formátování buněk, textový řádek, zkratky jednotek jedním dotazem.
 * Definice `docs_core_rows` se bere z reálného JSONC (lokalizace cs), aby
 * test hlídal, že použité id sloupců ve schématu existují.
 */
class DocsHeadsFormSubtableTest extends TestCase
{
    private const ROWS_TABLE = __DIR__ . '/../../../../../modules/docs/core/tables/docs_core_rows.jsonc';
    private const RECAP_TABLE = __DIR__ . '/../../../../../modules/docs/core/tables/docs_core_vat_recap.jsonc';

    private function rowsTableDef(): TableDefinition
    {
        $raw = JsoncParser::parseFile(self::ROWS_TABLE);
        return TableDefinition::fromArray(ConfigLocalizer::localize($raw, 'cs'));
    }

    /** @param list<array<string, mixed>> $units */
    private function form(array $units = [], ?string &$sql = null): DocsHeadsForm
    {
        $form = new DocsHeadsForm('docs_core_heads');
        $form->setTables(['docs_core_rows' => $this->rowsTableDef()]);

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (mixed ...$args) use ($units, &$sql): array {
                $sql = (string) ($args[0] ?? '');
                return $units;
            },
        );
        $form->setDb($db);
        return $form;
    }

    private function recapTableDef(): TableDefinition
    {
        $raw = JsoncParser::parseFile(self::RECAP_TABLE);
        return TableDefinition::fromArray(ConfigLocalizer::localize($raw, 'cs'));
    }

    /** Form se schématy obou dětských tabulek — pro tab rekapitulace. */
    private function formWithRecap(): DocsHeadsForm
    {
        $form = new DocsHeadsForm('docs_core_heads');
        $form->setTables([
            'docs_core_rows' => $this->rowsTableDef(),
            'docs_core_vat_recap' => $this->recapTableDef(),
        ]);
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);
        $form->setDb($db);
        return $form;
    }

    private function recapTab(): FormTab
    {
        return new FormTab(
            id: 'recap',
            label: 'Rekapitulace DPH',
            type: 'subtable',
            subtable: ['table' => 'docs_core_vat_recap', 'foreignKey' => 'doc_head', 'formId' => 'docs.core.vatRecap'],
        );
    }

    private function rowsTab(): FormTab
    {
        return new FormTab(
            id: 'rows',
            label: 'Řádky',
            type: 'subtable',
            subtable: ['table' => 'docs_core_rows', 'foreignKey' => 'doc_head', 'formId' => 'docs.core.rows'],
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function columnsById(array $result): array
    {
        return array_column($result['columns'], null, 'id');
    }

    public function testVatDocumentHasVatColumnsWithLocalizedLabels(): void
    {
        $form = $this->form([['id' => 3, 'shortcut' => 'ks', 'name' => 'kus']]);
        $rows = [
            ['id' => 101, 'doc_head' => 1, 'row_kind' => 1, 'order_pos' => 1, 'description' => 'Ukázková služba',
             'quantity' => '2.0000', 'unit' => 3, 'unit_price' => '1000.0000',
             'vat_base' => '2000.00', 'vat_pct' => '21.00', 'vat_amount' => '420.00', 'vat_total' => '2420.00',
             'total_price' => '2000.00'],
        ];
        $result = $form->renderSubtable($this->rowsTab(), $rows, ['id' => 1, 'vat_mode' => 1]);

        $this->assertSame(
            ['order_pos', 'description', 'quantity', 'unit', 'unit_price', 'vat_base', 'vat_pct', 'vat_amount', 'vat_total'],
            array_column($result['columns'], 'id'),
        );
        $cols = $this->columnsById($result);
        $this->assertSame('#', $cols['order_pos']['label']);
        $this->assertSame('Popis', $cols['description']['label']);
        $this->assertTrue($cols['description']['grow']);
        $this->assertSame('Množství', $cols['quantity']['label']);
        $this->assertSame('right', $cols['quantity']['align']);
        $this->assertSame('DPH %', $cols['vat_pct']['label']);
        $this->assertSame('Celkem s DPH', $cols['vat_total']['label']);
        $this->assertArrayNotHasKey('align', $cols['unit']);
        $this->assertNull($result['order_column']);

        $this->assertCount(1, $result['rows']);
        $this->assertSame(101, $result['rows'][0]['id']);
        $this->assertSame([
            'order_pos'   => '1',
            'description' => 'Ukázková služba',
            'quantity'    => '2',
            'unit'        => 'ks',
            'unit_price'  => '1 000,00',
            'vat_base'    => '2 000,00',
            'vat_pct'     => '21',
            'vat_amount'  => '420,00',
            'vat_total'   => '2 420,00',
        ], $result['rows'][0]['cells']);
    }

    public function testNoVatDocumentDropsVatColumnsAndUsesTotalPrice(): void
    {
        $form = $this->form();
        $rows = [
            ['id' => 5, 'row_kind' => 1, 'order_pos' => 2, 'description' => 'Bez DPH', 'quantity' => '1.0000',
             'unit' => null, 'unit_price' => '150.5000', 'total_price' => '150.50', 'vat_pct' => '0.00'],
        ];
        $result = $form->renderSubtable($this->rowsTab(), $rows, ['id' => 1, 'vat_mode' => 0]);

        $ids = array_column($result['columns'], 'id');
        $this->assertSame(['order_pos', 'description', 'quantity', 'unit', 'unit_price', 'total_price'], $ids);
        $this->assertSame('Cena celkem', $this->columnsById($result)['total_price']['label']);
        $this->assertSame([
            'order_pos'   => '2',
            'description' => 'Bez DPH',
            'quantity'    => '1',
            'unit_price'  => '150,50',
            'total_price' => '150,50',
        ], $result['rows'][0]['cells']);
    }

    public function testTextRowIsMutedAndHasNoNumericCells(): void
    {
        $form = $this->form();
        $rows = [
            ['id' => 7, 'row_kind' => 0, 'order_pos' => 0, 'description' => 'Poznámka k dodávce',
             'quantity' => '0.0000', 'unit_price' => null, 'total_price' => '0.00', 'vat_total' => '0.00'],
            ['id' => 8, 'row_kind' => 1, 'order_pos' => 0, 'description' => 'Položka', 'quantity' => null,
             'unit_price' => null, 'vat_total' => null],
        ];
        $result = $form->renderSubtable($this->rowsTab(), $rows, ['id' => 1, 'vat_mode' => 1]);

        // order_pos = 0 → pořadí v seznamu
        $this->assertSame(
            ['order_pos' => '1', 'description' => ['text' => 'Poznámka k dodávce', 'class' => 'muted']],
            $result['rows'][0]['cells'],
        );
        // prázdná čísla = chybějící klíče, žádné „0,00"
        $this->assertSame(['order_pos' => '2', 'description' => 'Položka'], $result['rows'][1]['cells']);
    }

    public function testUnitsAreLoadedWithSingleInQuery(): void
    {
        $sql = null;
        $form = $this->form([
            ['id' => 3, 'shortcut' => 'ks', 'name' => 'kus'],
            ['id' => 4, 'shortcut' => '', 'name' => 'hodina'],
        ], $sql);
        $rows = [
            ['id' => 1, 'row_kind' => 1, 'unit' => 3],
            ['id' => 2, 'row_kind' => 1, 'unit' => 4],
            ['id' => 3, 'row_kind' => 1, 'unit' => 3],
            ['id' => 4, 'row_kind' => 1, 'unit' => 99],
        ];
        $result = $form->renderSubtable($this->rowsTab(), $rows, ['id' => 1, 'vat_mode' => 1]);

        $this->assertStringContainsString('FROM `core_units` WHERE `id` IN %in', $sql);
        $this->assertSame('ks', $result['rows'][0]['cells']['unit']);
        // bez zkratky padá na název
        $this->assertSame('hodina', $result['rows'][1]['cells']['unit']);
        $this->assertSame('ks', $result['rows'][2]['cells']['unit']);
        $this->assertArrayNotHasKey('unit', $result['rows'][3]['cells']);
    }

    public function testWithoutDbUnitsAreSimplyMissing(): void
    {
        $form = new DocsHeadsForm('docs_core_heads');
        $result = $form->renderSubtable(
            $this->rowsTab(),
            [['id' => 1, 'row_kind' => 1, 'unit' => 3, 'description' => 'x']],
            ['id' => 1, 'vat_mode' => 1],
        );
        $this->assertArrayNotHasKey('unit', $result['rows'][0]['cells']);
        // bez registru tabulek platí české fallback labely
        $this->assertSame('Popis', $this->columnsById($result)['description']['label']);
    }

    public function testOtherSubtableTabsFallBackToDefaultRenderer(): void
    {
        $form = $this->form();
        $tab = new FormTab(
            id: 'other',
            label: 'Other',
            type: 'subtable',
            subtable: ['table' => 'docs_core_rows', 'foreignKey' => 'doc_head'],
        );
        $result = $form->renderSubtable($tab, [], ['id' => 1]);
        // default renderer nad definicí docs_core_rows: první sloupce schématu bez FK/PK
        $this->assertSame('row_kind', $result['columns'][0]['id']);
        $this->assertCount(6, $result['columns']);
    }

    // ── Sloupec „Datum sazby“ (#159 U10) ────────────────────────────────────

    public function testRateDateColumnOnlyWithRowsSource(): void
    {
        $form = $this->form();
        $rows = [
            ['id' => 101, 'doc_head' => 1, 'row_kind' => 1, 'order_pos' => 1, 'description' => 'Dodávka',
             'quantity' => '1.0000', 'unit_price' => '5000.0000', 'vat_base' => '5000.00', 'vat_pct' => '21.00',
             'vat_amount' => '1050.00', 'vat_total' => '6050.00', 'vat_rate_date' => null],
            ['id' => 102, 'doc_head' => 1, 'row_kind' => 1, 'order_pos' => 2, 'description' => 'Odpočet zálohy',
             'quantity' => '1.0000', 'unit_price' => '-2000.0000', 'vat_base' => '-2000.00', 'vat_pct' => '20.00',
             'vat_amount' => '-400.00', 'vat_total' => '-2400.00', 'vat_rate_date' => '2012-11-20'],
        ];

        $result = $form->renderSubtable($this->rowsTab(), $rows, ['id' => 1, 'vat_mode' => 1, 'vat_rate_source' => 2]);
        $this->assertSame(
            ['order_pos', 'description', 'quantity', 'unit', 'unit_price', 'vat_base', 'vat_pct', 'vat_rate_date', 'vat_amount', 'vat_total'],
            array_column($result['columns'], 'id'),
        );
        $this->assertSame('Datum sazby', $this->columnsById($result)['vat_rate_date']['label']);
        $this->assertArrayNotHasKey('vat_rate_date', $result['rows'][0]['cells'], 'řádek bez vlastního data');
        $this->assertSame('2012-11-20', $result['rows'][1]['cells']['vat_rate_date']);

        foreach ([0, 1] as $source) {
            $result = $form->renderSubtable($this->rowsTab(), $rows, ['id' => 1, 'vat_mode' => 1, 'vat_rate_source' => $source]);
            $this->assertNotContains('vat_rate_date', array_column($result['columns'], 'id'), "zdroj {$source}");
        }

        $result = $form->renderSubtable($this->rowsTab(), $rows, ['id' => 1, 'vat_mode' => 0, 'vat_rate_source' => 2]);
        $this->assertNotContains('vat_rate_date', array_column($result['columns'], 'id'), 'doklad bez DPH');
    }

    public function testDeclaredRecapRateDateColumnOnlyWithRowsSource(): void
    {
        $form = $this->formWithRecap();
        $rows = [[
            'id' => 5, 'doc_head' => 1, 'order_pos' => 1, 'vat_code' => 'cz-110', 'vat_pct' => '20.00',
            'vat_rate_date' => '2012-11-20', 'base' => '-2000.00', 'tax' => '-400.00', 'total' => '-2400.00',
            'sum_base' => 1, 'sum_tax' => 1, 'sum_total' => 1, 'is_reverse_pair' => 0,
        ]];
        $head = ['id' => 1, 'vat_mode' => 1, 'doc_currency' => 'czk', 'home_currency' => 'czk'];

        $result = $form->renderSubtable($this->recapTab(), $rows, $head + ['vat_rate_source' => 2]);
        $this->assertSame(
            ['order_pos', 'vat_code', 'vat_pct', 'vat_rate_date', 'base', 'tax', 'total'],
            array_column($result['columns'], 'id'),
        );
        $this->assertSame('2012-11-20', $result['rows'][0]['cells']['vat_rate_date']);

        $result = $form->renderSubtable($this->recapTab(), $rows, $head + ['vat_rate_source' => 1]);
        $this->assertNotContains('vat_rate_date', array_column($result['columns'], 'id'));
    }

    // ── Sub-tabulka převzaté rekapitulace DPH (#75) ────────────────────────

    public function testDeclaredRecapSubtableColumnsAndCells(): void
    {
        $form = $this->formWithRecap();
        $rows = [[
            'id' => 5, 'doc_head' => 1, 'order_pos' => 1,
            'vat_code' => 'cz-110', 'vat_pct' => '21.00',
            'base' => '90.91', 'tax' => '19.09', 'total' => '110.00',
            'base_dom' => '90.91', 'tax_dom' => '19.09', 'total_dom' => '110.00',
            'sum_base' => 1, 'sum_tax' => 1, 'sum_total' => 1, 'is_reverse_pair' => 0,
        ]];
        $result = $form->renderSubtable($this->recapTab(), $rows, [
            'id' => 1, 'vat_mode' => 2, 'doc_currency' => 'czk', 'home_currency' => 'czk',
        ]);

        $this->assertSame(
            ['order_pos', 'vat_code', 'vat_pct', 'base', 'tax', 'total'],
            array_column($result['columns'], 'id'),
            'tuzemský doklad nemá sloupce domácí měny',
        );
        $cells = $result['rows'][0]['cells'];
        $this->assertSame('1', $cells['order_pos']);
        $this->assertSame('21', $cells['vat_pct']);
        $this->assertSame('90,91', $cells['base']);
        $this->assertSame('19,09', $cells['tax']);
        $this->assertSame('110,00', $cells['total']);
        $this->assertSame(5, $result['rows'][0]['id']);
    }

    public function testDeclaredRecapSubtableAddsDomesticColumnsForForeignCurrency(): void
    {
        $form = $this->formWithRecap();
        $rows = [[
            'id' => 5, 'doc_head' => 1, 'order_pos' => 1,
            'vat_code' => 'cz-110', 'vat_pct' => '21.00',
            'base' => '100.00', 'tax' => '21.00', 'total' => '121.00',
            'base_dom' => '2500.00', 'tax_dom' => '525.00', 'total_dom' => '3025.00',
            'sum_base' => 1, 'sum_tax' => 1, 'sum_total' => 1, 'is_reverse_pair' => 0,
        ]];
        $result = $form->renderSubtable($this->recapTab(), $rows, [
            'id' => 1, 'vat_mode' => 1, 'doc_currency' => 'eur', 'home_currency' => 'czk',
        ]);

        $cols = array_column($result['columns'], 'id');
        $this->assertContains('base_dom', $cols);
        $this->assertContains('tax_dom', $cols);
        $this->assertContains('total_dom', $cols);
        $this->assertSame('Základ (CZK)', $this->columnsById($result)['base_dom']['label']);
        $this->assertSame('2 500,00', $result['rows'][0]['cells']['base_dom']);
    }

    public function testDeclaredRecapSubtableMutesAmountsOutsideHeadTotals(): void
    {
        $form = $this->formWithRecap();
        // Oddaňovací pár samovyměření — sum_* = 0, do součtů nevstupuje.
        $rows = [[
            'id' => 6, 'doc_head' => 1, 'order_pos' => 2,
            'vat_code' => 'cz-203', 'vat_pct' => '21.00',
            'base' => '1000.00', 'tax' => '210.00', 'total' => '1210.00',
            'sum_base' => 0, 'sum_tax' => 0, 'sum_total' => 0, 'is_reverse_pair' => 1,
        ]];
        $result = $form->renderSubtable($this->recapTab(), $rows, [
            'id' => 1, 'vat_mode' => 1, 'doc_currency' => 'czk', 'home_currency' => 'czk',
        ]);

        $cells = $result['rows'][0]['cells'];
        $this->assertSame(['text' => '1 000,00', 'class' => 'muted'], $cells['base']);
        $this->assertSame(['text' => '210,00', 'class' => 'muted'], $cells['tax']);
    }
}
