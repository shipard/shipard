<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Module\Docs\Core\DocRowsForm;

/**
 * Kontační řádek účetního dokladu (cmnbkp): operace s atributem `rowAccount`
 * přepne DocRowsForm do účetní větve — účet / strana / částka místo
 * položkového bloku (množství / cena / DPH).
 */
class DocRowsFormContationTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_rowcont_test_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/config/configuration/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir . '/config/configuration');
        rmdir($this->tmpDir . '/config');
        rmdir($this->tmpDir);
    }

    private function buildConfig(): ConfigRuntime
    {
        $items = [
            'docs.core.rowOperations' => [
                'acc.record' => [
                    'name' => 'Účetní zápis', 'rowSide' => 1, 'rowPartner' => 1,
                    'rowPaymentId' => 1, 'rowAccount' => 'direct',
                    'docTypes' => ['cmnbkp' => ['order' => 100]],
                ],
                'acc.item' => [
                    'name' => 'Účetní položka', 'rowSide' => 1, 'rowPartner' => 1,
                    'rowPaymentId' => 1, 'rowAccount' => 'item',
                    'docTypes' => ['cmnbkp' => ['order' => 200]],
                ],
                // saldokontní operace — vlajky bez rowAccount (účet z kategorie)
                'acc.balanceReceivable' => [
                    'name' => 'Zápočet pohledávky', 'rowSide' => 1, 'rowPartner' => 1,
                    'rowPaymentId' => 1, 'docTypes' => ['cmnbkp' => ['order' => 300]],
                ],
                // kurzový rozdíl — rowSide: 0 (kontační layout bez volby
                // strany, strany fixní z kroků předpisu, D12)
                'acc.fxLossReceivable' => [
                    'name' => 'Kurzová ztráta — pohledávka', 'rowSide' => 0,
                    'rowPartner' => 1, 'rowPaymentId' => 1,
                    'docTypes' => ['cmnbkp' => ['order' => 500]],
                ],
                // majetek — systémová operace s kartou (assets D48)
                'asset.depreciation' => [
                    'name' => 'Odpis majetku', 'rowSide' => 1, 'rowAccount' => 'direct',
                    'rowAsset' => 1, 'system' => 1,
                    'docTypes' => ['cmnbkp' => ['order' => 930]],
                ],
                // faktura — bez rowAccount, ať ověříme, že položková větev zůstává
                'purchase.goods' => ['name' => 'Nákup zboží', 'docTypes' => ['invni' => ['order' => 100]]],
                // záloha — bez rowAccount (účet dohledá kategorie předpisu,
                // D10) i bez rowSide: položkový layout + saldo identita
                'purchase.advanceDeduction' => [
                    'name' => 'Odpočet poskytnuté zálohy',
                    'rowPaymentId' => 1, 'docTypes' => ['invni' => ['order' => 400]],
                ],
                // majetek — přímý účet bez rowSide: položkový layout
                // s inputem účtu, stranu určuje krok předpisu
                'purchase.asset' => [
                    'name' => 'Pořízení majetku', 'rowAccount' => 'direct',
                    'docTypes' => ['invni' => ['order' => 600]],
                ],
            ],
            'docs.core.rowKinds' => [
                '0' => ['name' => 'Textový řádek'],
                '1' => ['name' => 'Běžný řádek'],
            ],
            'docs.core.accSides' => [
                '0' => ['name' => 'Má dáti'],
                '1' => ['name' => 'Dal'],
            ],
            'docs.core.priceCalcModes' => [
                '0' => ['name' => 'Z jednotkové'],
                '1' => ['name' => 'Z celkové'],
            ],
        ];
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode(['_meta' => ['language' => 'cs'], 'items' => $items]),
        );
        return ConfigRuntime::load($this->tmpDir, 'cs');
    }

    private function form(string $docType, int $vatMode = 0): DocRowsForm
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'doc_type' => $docType, 'vat_place' => 0, 'vat_duzp' => null,
            'vat_mode' => $vatMode, 'vat_registration' => null,
        ]);
        $db->method('fetchAll')->willReturn([]);

        $form = new DocRowsForm('docs_core_rows');
        $form->setConfig($this->buildConfig());
        $form->setDb($db);
        return $form;
    }

    private function findElement(FormDefinition $def, string $column): ?FormElement
    {
        foreach ($def->tabs[0]->sections as $section) {
            foreach ($section->columns as $col) {
                foreach ($col->elements as $el) {
                    if ($el->column === $column) {
                        return $el;
                    }
                }
            }
        }
        return null;
    }

    public function testAccRecordShowsAccountAndHidesItemBlock(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'acc.record'];
        $def = $this->form('cmnbkp')->buildFormDefinition($data, true);

        // Účet přímo, strana, částka.
        $account = $this->findElement($def, 'account');
        $this->assertNotNull($account);
        $this->assertSame('lookup', $account->type);
        $this->assertSame('economy_accounting_accounts', $account->lookup['table']);
        $this->assertSame(['account_level' => 4], $account->lookup['filter']);

        $this->assertNotNull($this->findElement($def, 'acc_side'));
        $this->assertNotNull($this->findElement($def, 'total_price'));

        // Per-řádková identita (vlajky rowPartner / rowPaymentId).
        $this->assertNotNull($this->findElement($def, 'partner'));
        $this->assertNotNull($this->findElement($def, 'payment_reference'));
        $this->assertNotNull($this->findElement($def, 'due_date'));

        // Položkový / DPH blok se v kontační větvi vůbec nestaví.
        $this->assertNull($this->findElement($def, 'quantity'));
        $this->assertNull($this->findElement($def, 'unit_price'));
        $this->assertNull($this->findElement($def, 'vat_code'));

        // price_calc_mode je skryté pole (částka se zadává přímo).
        $pcm = $this->findElement($def, 'price_calc_mode');
        $this->assertNotNull($pcm);
        $this->assertTrue($pcm->hidden);
    }

    public function testAccItemShowsItemLookupRestrictedToType2(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'acc.item'];
        $def = $this->form('cmnbkp')->buildFormDefinition($data, true);

        $item = $this->findElement($def, 'item');
        $this->assertNotNull($item);
        $this->assertSame('economy_items', $item->lookup['table']);
        $this->assertSame(['item_type' => 2], $item->lookup['filter']);

        // Přímý účet se v item-větvi nestaví.
        $this->assertNull($this->findElement($def, 'account'));
    }

    public function testBalanceOpShowsIdentityButHidesAccountAndItem(): void
    {
        // Saldokontní operace (zápočet) — účet implicitní z kategorie předpisu,
        // formulář ukáže stranu / částku / saldo identitu, ale ne vstup účtu.
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'acc.balanceReceivable'];
        $def = $this->form('cmnbkp')->buildFormDefinition($data, true);

        // Strana, částka, saldo identita.
        $this->assertNotNull($this->findElement($def, 'acc_side'));
        $this->assertNotNull($this->findElement($def, 'total_price'));
        $this->assertNotNull($this->findElement($def, 'partner'));
        $this->assertNotNull($this->findElement($def, 'payment_reference'));
        $this->assertNotNull($this->findElement($def, 'due_date'));

        // Vstup účtu ani položky se u saldokontní operace nestaví — účet je
        // implicitní z kategorie (311/321).
        $this->assertNull($this->findElement($def, 'account'));
        $this->assertNull($this->findElement($def, 'item'));

        // Položkový / DPH blok zůstává skrytý.
        $this->assertNull($this->findElement($def, 'quantity'));
        $this->assertNull($this->findElement($def, 'vat_code'));

        // price_calc_mode skryté (částka přímo).
        $pcm = $this->findElement($def, 'price_calc_mode');
        $this->assertNotNull($pcm);
        $this->assertTrue($pcm->hidden);
    }

    public function testFxOpUsesContationLayoutWithoutSideSelect(): void
    {
        // Kurzový rozdíl (rowSide: 0, D12) — kontační layout, ale bez volby
        // strany MD/DAL: strany jsou fixní z kroků předpisu, částka kladná,
        // směr nese volba operace. Účet implicitní z kategorií (563/663 ×
        // 311/321), saldo identita z vlajek.
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'acc.fxLossReceivable'];
        $def = $this->form('cmnbkp')->buildFormDefinition($data, true);

        // Kontační layout: částka + saldo identita ano.
        $this->assertNotNull($this->findElement($def, 'total_price'));
        $this->assertNotNull($this->findElement($def, 'partner'));
        $this->assertNotNull($this->findElement($def, 'payment_reference'));
        $this->assertNotNull($this->findElement($def, 'due_date'));

        // Bez volby strany, bez vstupu účtu/položky.
        $this->assertNull($this->findElement($def, 'acc_side'), 'Strany jsou fixní z kroků předpisu');
        $this->assertNull($this->findElement($def, 'account'));
        $this->assertNull($this->findElement($def, 'item'));

        // Položkový / DPH blok se nestaví.
        $this->assertNull($this->findElement($def, 'quantity'));
        $this->assertNull($this->findElement($def, 'vat_code'));

        // price_calc_mode skryté (částka přímo).
        $pcm = $this->findElement($def, 'price_calc_mode');
        $this->assertNotNull($pcm);
        $this->assertTrue($pcm->hidden);
    }

    public function testFxOpRecalculateSetsPriceCalcMode(): void
    {
        // rowSide: 0 je pořád kontační řádek — price_calc_mode = 1, ať se
        // ručně zadaná částka nepřepíše výpočtem z množství × cena.
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'acc.fxLossReceivable'];
        $result = $this->form('cmnbkp')->recalculate('operation', $data);

        $this->assertSame(1, $result->data['price_calc_mode']);
    }

    public function testBalanceOpRecalculateSetsPriceCalcMode(): void
    {
        // Přepnutí na saldokontní operaci zajistí price_calc_mode = 1, ať se
        // ručně zadaná částka nepřepíše výpočtem z množství × cena.
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'acc.balanceReceivable'];
        $result = $this->form('cmnbkp')->recalculate('operation', $data);

        $this->assertSame(1, $result->data['price_calc_mode']);
    }

    public function testNewContationRowDefaultsOperationAndPriceCalcMode(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5];
        $this->form('cmnbkp')->applyNewRecordDefaults($data);

        $this->assertSame('acc.record', $data['operation']);
        $this->assertSame(1, $data['price_calc_mode']);
    }

    public function testInvoiceRowKeepsItemBlock(): void
    {
        // Faktura: operace bez rowAccount → stávající položková větev.
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'purchase.goods'];
        $def = $this->form('invni', vatMode: 1)->buildFormDefinition($data, true);

        $this->assertNotNull($this->findElement($def, 'quantity'));
        $this->assertNotNull($this->findElement($def, 'unit_price'));
        $this->assertNull($this->findElement($def, 'account'));
        $this->assertNull($this->findElement($def, 'acc_side'));
    }

    public function testAdvanceDeductionKeepsItemLayoutWithIdentityWithoutAccount(): void
    {
        // Záloha na faktuře (rowPaymentId bez rowAccount i rowSide, D10):
        // položkový layout s DPH blokem zůstává, přibývá platební identita;
        // vstup účtu se nezobrazuje — účet dohledá kategorie advances.*
        // účtovacího předpisu. Strana MD/DAL se nezadává — určuje ji krok
        // předpisu (reverseSign).
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'purchase.advanceDeduction'];
        $def = $this->form('invni', vatMode: 2)->buildFormDefinition($data, true);

        $this->assertNull($this->findElement($def, 'account'), 'Účet dohledá kategorie předpisu');
        $this->assertNull($this->findElement($def, 'acc_side'), 'Stranu určuje krok předpisu');
        $this->assertNotNull($this->findElement($def, 'item'), 'Položkový layout zůstává');

        // Položkový + DPH blok zůstává (odpočet nese záporný základ i daň).
        $this->assertNotNull($this->findElement($def, 'quantity'));
        $this->assertNotNull($this->findElement($def, 'vat_code'));

        // Platební identita zálohy (payment_reference = číslo zálohového dokladu).
        $this->assertNotNull($this->findElement($def, 'payment_reference'));
        $this->assertNotNull($this->findElement($def, 'due_date'));
        $this->assertNull($this->findElement($def, 'partner'), 'rowPartner operace nemá');
    }

    public function testAssetShowsAccountWithoutIdentity(): void
    {
        // Majetek: přímý účet ano, platební identita ne (bez rowPaymentId).
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'purchase.asset'];
        $def = $this->form('invni', vatMode: 2)->buildFormDefinition($data, true);

        $this->assertNotNull($this->findElement($def, 'account'));
        $this->assertNull($this->findElement($def, 'item'));
        $this->assertNull($this->findElement($def, 'payment_reference'));
        $this->assertNull($this->findElement($def, 'partner'));
        $this->assertNotNull($this->findElement($def, 'vat_code'));
    }

    public function testAdvanceOperationKeepsPriceCalcModeUntouched(): void
    {
        // price_calc_mode = 1 vynucuje jen kontační layout (rowSide);
        // zálohy v položkovém layoutu počítají cenu standardně.
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'purchase.advanceDeduction'];
        $result = $this->form('invni', vatMode: 2)->recalculate('operation', $data);

        $this->assertArrayNotHasKey('price_calc_mode', $result->data);
    }

    public function testContationRowSkipsLiveCalculation(): void
    {
        // Částka je zadaná ručně (price_calc_mode fixní 1) a řádek nemá DPH
        // blok — živý přepočet (#71) ji nesmí přepsat ani zapsat vat_*.
        $result = $this->form('cmnbkp', vatMode: 1)->recalculate('total_price', [
            'row_kind' => 1, 'doc_head' => 5, 'operation' => 'acc.record',
            'quantity' => '2', 'total_price' => '500', 'price_calc_mode' => 1, 'vat_pct' => '21',
        ]);

        $this->assertSame('500', $result->data['total_price']);
        $this->assertArrayNotHasKey('unit_price', $result->data);
        $this->assertArrayNotHasKey('vat_base', $result->data);

        $el = $this->findElement($result->formDefinition, 'total_price');
        $this->assertNotNull($el);
        $this->assertFalse($el->readOnly);
    }

    // ── Systémové operace (asset.*, assets D48) ─────────────────────────────

    public function testSystemOperationIsNotOfferedForNewRow(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'acc.record'];
        $def = $this->form('cmnbkp')->buildFormDefinition($data, true);

        $this->assertNotContains(
            'asset.depreciation',
            array_column($this->findElement($def, 'operation')->options, 'value'),
        );
        $this->assertNull($this->findElement($def, 'asset'));
    }

    public function testStoredSystemRowIsShownReadOnlyWithAsset(): void
    {
        $data = ['id' => 9, 'row_kind' => 1, 'doc_head' => 5, 'operation' => 'asset.depreciation', 'asset' => 5];
        $def = $this->form('cmnbkp')->buildFormDefinition($data, false);

        $operation = $this->findElement($def, 'operation');
        $this->assertContains('asset.depreciation', array_column($operation->options, 'value'));

        $asset = $this->findElement($def, 'asset');
        $this->assertNotNull($asset);
        $this->assertSame('economy_assets_assets', $asset->lookup['table']);

        foreach (['row_kind', 'operation', 'asset', 'account', 'acc_side', 'total_price', 'description'] as $column) {
            $this->assertTrue($this->findElement($def, $column)->readOnly, "{$column} má být jen ke čtení");
        }
    }
}
