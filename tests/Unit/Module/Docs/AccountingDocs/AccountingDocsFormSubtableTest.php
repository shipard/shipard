<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\AccountingDocs;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\FormTab;
use Shipard\Module\Docs\AccountingDocs\AccountingDocsForm;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/**
 * Sub-tabulka řádků účetního dokladu — kontační sada (Pohyb · Účet ·
 * Popis · Strana · Částka), strana jen u operací s rowSide: 1, účty jedním
 * dotazem, textový řádek.
 */
class AccountingDocsFormSubtableTest extends TestCase
{
    /** @param list<array<string, mixed>> $accounts */
    private function form(array $accounts = [], ?string &$sql = null): AccountingDocsForm
    {
        $form = new AccountingDocsForm('docs_core_heads');
        $form->setConfig(ConfigRuntimeFactory::fromItems([
            'docs.core.rowOperations' => [
                'acc.record' => [
                    'name' => 'Účetní zápis', 'rowSide' => 1, 'rowAccount' => 'direct',
                    'docTypes' => ['cmnbkp' => ['order' => 100]],
                ],
                'acc.balanceReceivable' => [
                    'name' => 'Zápočet pohledávky', 'rowSide' => 1,
                    'docTypes' => ['cmnbkp' => ['order' => 300]],
                ],
                'acc.fxLossReceivable' => [
                    'name' => 'Kurzová ztráta — pohledávka', 'rowSide' => 0,
                    'docTypes' => ['cmnbkp' => ['order' => 500]],
                ],
            ],
            'docs.core.accSides' => [
                '0' => ['name' => 'Má dáti'],
                '1' => ['name' => 'Dal'],
            ],
        ]));

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (mixed ...$args) use ($accounts, &$sql): array {
                $sql = (string) ($args[0] ?? '');
                return $accounts;
            },
        );
        $form->setDb($db);
        return $form;
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

    public function testContationColumnsAndCells(): void
    {
        $sql = null;
        $form = $this->form([['id' => 12, 'number' => '321000', 'name' => 'Dodavatelé']], $sql);
        $rows = [
            ['id' => 1, 'row_kind' => 1, 'order_pos' => 1, 'operation' => 'acc.record', 'account' => 12,
             'description' => 'Úhrada faktury', 'acc_side' => 0, 'total_price' => '1500.00'],
            ['id' => 2, 'row_kind' => 1, 'order_pos' => 2, 'operation' => 'acc.balanceReceivable', 'account' => null,
             'description' => 'Zápočet', 'acc_side' => 1, 'total_price' => '1500.00'],
            // kurzový rozdíl: rowSide 0 → strana z předpisu, buňka prázdná
            ['id' => 3, 'row_kind' => 1, 'order_pos' => 3, 'operation' => 'acc.fxLossReceivable', 'account' => null,
             'description' => 'Kurzový rozdíl', 'acc_side' => 0, 'total_price' => '12.30'],
            ['id' => 4, 'row_kind' => 0, 'order_pos' => 4, 'operation' => null, 'description' => 'Text',
             'total_price' => '0.00'],
        ];
        $result = $form->renderSubtable($this->rowsTab(), $rows, ['id' => 1, 'doc_type' => 'cmnbkp', 'vat_mode' => 0]);

        $this->assertSame(
            ['order_pos', 'operation', 'account', 'description', 'acc_side', 'total_price'],
            array_column($result['columns'], 'id'),
        );
        $cols = array_column($result['columns'], null, 'id');
        $this->assertSame('Částka', $cols['total_price']['label']);
        $this->assertSame('right', $cols['total_price']['align']);
        $this->assertTrue($cols['description']['grow']);
        $this->assertNull($result['order_column']);

        $this->assertStringContainsString('FROM `economy_accounting_accounts` WHERE `id` IN %in', $sql);

        $this->assertSame([
            'order_pos'   => '1',
            'operation'   => 'Účetní zápis',
            'account'     => '321000 Dodavatelé',
            'description' => 'Úhrada faktury',
            'acc_side'    => 'Má dáti',
            'total_price' => '1 500,00',
        ], $result['rows'][0]['cells']);
        $this->assertSame([
            'order_pos'   => '2',
            'operation'   => 'Zápočet pohledávky',
            'description' => 'Zápočet',
            'acc_side'    => 'Dal',
            'total_price' => '1 500,00',
        ], $result['rows'][1]['cells']);
        $this->assertSame([
            'order_pos'   => '3',
            'operation'   => 'Kurzová ztráta — pohledávka',
            'description' => 'Kurzový rozdíl',
            'total_price' => '12,30',
        ], $result['rows'][2]['cells']);
        $this->assertSame(
            ['order_pos' => '4', 'description' => ['text' => 'Text', 'class' => 'muted']],
            $result['rows'][3]['cells'],
        );
    }

    public function testUnknownOperationFallsBackToRawKey(): void
    {
        $form = $this->form();
        $result = $form->renderSubtable(
            $this->rowsTab(),
            [['id' => 1, 'row_kind' => 1, 'operation' => 'acc.unknown', 'acc_side' => 1, 'total_price' => '5.00']],
            ['id' => 1],
        );
        $cells = $result['rows'][0]['cells'];
        $this->assertSame('acc.unknown', $cells['operation']);
        // neznámá operace nemá rowSide → strana se nezobrazí
        $this->assertArrayNotHasKey('acc_side', $cells);
        $this->assertSame('5,00', $cells['total_price']);
    }

    public function testNoAccountsQueryWhenRowsHaveNoAccount(): void
    {
        $sql = null;
        $form = $this->form([], $sql);
        $form->renderSubtable(
            $this->rowsTab(),
            [['id' => 1, 'row_kind' => 1, 'operation' => 'acc.balanceReceivable', 'account' => null]],
            ['id' => 1],
        );
        $this->assertNull($sql);
    }

    public function testRowDimensionAddsColumnAfterAccount(): void
    {
        // Dimenze deníku `asset` (assets D47): sloupec Majetek jen když ji
        // některý řádek nese, popisek z displayPattern cílové tabulky.
        $form = new AccountingDocsForm('docs_core_heads');
        $form->setConfig(ConfigRuntimeFactory::fromItems([
            'docs.core.rowOperations' => [
                'asset.depreciation' => ['name' => 'Odpis majetku', 'rowSide' => 1, 'rowAccount' => 'direct', 'rowAsset' => 1, 'system' => 1],
            ],
            'docs.core.accSides' => ['0' => ['name' => 'Má dáti'], '1' => ['name' => 'Dal']],
            'core.accounting.journalDimensions' => ['asset' => [
                'id' => 'asset', 'rowColumn' => 'asset', 'headColumn' => null, 'journalColumn' => 'asset',
                'table' => 'economy_assets_assets', 'name' => 'Majetek', 'displayPattern' => '{asset_number} — {name}',
            ]],
        ]));
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static fn(mixed ...$args): array => str_contains((string) $args[0], 'economy_assets_assets')
                ? [['id' => 5, 'asset_number' => 'MA0005', 'name' => 'Soustruh']]
                : [['id' => 12, 'number' => '551000', 'name' => 'Odpisy']],
        );
        $form->setDb($db);

        $rows = [
            ['id' => 1, 'row_kind' => 1, 'order_pos' => 1, 'operation' => 'asset.depreciation', 'account' => 12,
             'asset' => 5, 'description' => 'Odpis', 'acc_side' => 0, 'total_price' => '1000.00'],
        ];
        $result = $form->renderSubtable($this->rowsTab(), $rows, ['id' => 1, 'doc_type' => 'cmnbkp', 'vat_mode' => 0]);

        $this->assertSame(
            ['order_pos', 'operation', 'account', 'dim_asset', 'description', 'acc_side', 'total_price'],
            array_column($result['columns'], 'id'),
        );
        $this->assertSame('Majetek', array_column($result['columns'], 'label', 'id')['dim_asset']);
        $this->assertSame('MA0005 — Soustruh', $result['rows'][0]['cells']['dim_asset']);
    }
}
