<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Docs\Core\DocRowsForm;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/**
 * Select pohybu v řádkovém sub-formu: options filtrované podle doc_type
 * hlavičky, řazené dle order, skryté pro textové řádky. Výchozí pohyb dává
 * `DocRowDefaults` — nejnižší order, u řádku s položkou mapa
 * `applyRowOperations` podle typu položky; výběr položky přenastaví jen
 * automatický pohyb (#166 U3).
 */
class DocRowsFormOperationsTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_rowops_test_' . uniqid();
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

    /** @return array<string, mixed> cfgItems sdílené oběma konfiguracemi */
    private function configItems(): array
    {
        return [
            'docs.core.rowOperations' => [
                // schválně přeházené pořadí — řadí se podle order, ne klíče
                'acc.entry' => ['name' => 'Účetní položka', 'docTypes' => [
                    'invno' => ['order' => 900], 'invni' => ['order' => 900], 'cash' => ['order' => 900],
                ]],
                'sale.goods'    => ['name' => 'Prodej zboží',  'docTypes' => [
                    'invno' => ['order' => 200], 'cash' => ['order' => 200, 'cashDir' => 1],
                ]],
                'sale.services' => ['name' => 'Prodej služeb', 'docTypes' => [
                    'invno' => ['order' => 100], 'cash' => ['order' => 100, 'cashDir' => 1],
                ]],
                'purchase.goods' => ['name' => 'Nákup zboží', 'docTypes' => [
                    'invni' => ['order' => 100], 'cash' => ['order' => 100, 'cashDir' => 2],
                ]],
                'payment.receivable' => [
                    'name' => 'Úhrada pohledávky',
                    'rowSide' => 0, 'rowPartner' => 1, 'rowPaymentId' => 1, 'identityRequired' => 1,
                    'docTypes' => ['cash' => ['order' => 300, 'cashDir' => 1]],
                ],
                'payment.payable' => [
                    'name' => 'Úhrada závazku',
                    'rowSide' => 0, 'rowPartner' => 1, 'rowPaymentId' => 1, 'identityRequired' => 1,
                    'docTypes' => ['cash' => ['order' => 400, 'cashDir' => 2]],
                ],
                'transfer.in' => [
                    'name' => 'Příjem z převodu peněz', 'rowSide' => 0, 'rowPaymentId' => 1,
                    'docTypes' => ['cash' => ['order' => 350, 'cashDir' => 1]],
                ],
                'transfer.out' => [
                    'name' => 'Výdej pro převod peněz', 'rowSide' => 0, 'rowPaymentId' => 1,
                    'docTypes' => ['cash' => ['order' => 450, 'cashDir' => 2]],
                ],
                // zálohy (Task E): odpočet položkový s DPH jako na faktuře, hotovostní záloha kontační
                'sale.advanceDeduction' => [
                    'name' => 'Odpočet přijaté zálohy', 'rowPaymentId' => 1,
                    'docTypes' => ['invno' => ['order' => 300], 'cash' => ['order' => 360, 'cashDir' => 1]],
                ],
                'advance.received' => [
                    'name' => 'Přijatá záloha', 'rowSide' => 0, 'rowPartner' => 1, 'rowPaymentId' => 1, 'partnerRequired' => 1,
                    'docTypes' => ['cash' => ['order' => 370, 'cashDir' => 1]],
                ],
            ],
            'docs.core.docTypes' => [
                'invno' => ['trade_dir' => 1],
                'invni' => ['trade_dir' => 2],
                'cash'  => ['trade_dir' => 0, 'trade_dir_column' => 'cash_dir', 'series_binding' => 'cash_desk'],
            ],
            'docs.core.rowKinds' => [
                '0' => ['name' => 'Textový řádek'],
                '1' => ['name' => 'Běžný řádek'],
            ],
        ];
    }

    private function buildConfig(): ConfigRuntime
    {
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode(['_meta' => ['language' => 'cs'], 'items' => $this->configItems()]),
        );
        return ConfigRuntime::load($this->tmpDir, 'cs');
    }

    /** Config s reálným world.vat.cz — pořadí kódů v JSONC = pořadí options. */
    private function configWithVat(): ConfigRuntime
    {
        $items = $this->configItems();
        $items['world.vat.cz'] = JsoncParser::parseFile(
            dirname(__DIR__, 5) . '/modules/world/vat/config/vat-cz.jsonc',
        );
        return ConfigRuntimeFactory::fromItems($items);
    }

    /**
     * Form nad hlavičkou s registrací DPH (země cz) — mock rozlišuje dotaz
     * na hlavičku a na registraci.
     */
    private function formWithVat(string $docType, ?string $duzp, int $vatMode = 1, int $cashDir = 0): DocRowsForm
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static function (string $sql, mixed ...$params) use ($docType, $duzp, $vatMode, $cashDir): ?array {
                if (str_contains($sql, 'economy_codebooks_vat_registrations')) {
                    return ['country' => 'cz'];
                }
                return [
                    'doc_type'         => $docType,
                    'cash_dir'         => $cashDir,
                    'vat_place'        => 0,
                    'vat_duzp'         => $duzp,
                    'vat_mode'         => $vatMode,
                    'vat_registration' => 5,
                ];
            },
        );
        $db->method('fetchAll')->willReturn([]);
        $form = new DocRowsForm('docs_core_rows');
        $form->setConfig($this->configWithVat());
        $form->setDb($db);
        return $form;
    }

    private function dbWithHead(string $docType, int $cashDir = 0): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'doc_type'  => $docType,
            'cash_dir'  => $cashDir,
            'vat_place' => 0,
            'vat_duzp'  => null,
            'vat_mode'  => 1,
            'vat_registration' => null,
        ]);
        $db->method('fetchAll')->willReturn([]);
        return $db;
    }

    private function form(string $docType, int $cashDir = 0): DocRowsForm
    {
        $form = new DocRowsForm('docs_core_rows');
        $form->setConfig($this->buildConfig());
        $form->setDb($this->dbWithHead($docType, $cashDir));
        return $form;
    }

    /**
     * Form s mapou výchozích pohybů (`docs.core.applyRowOperations`) a kartami
     * položek: 1 služba s cenou a jednotkou, 2 zásoba bez ceny.
     */
    private function formWithOperationMap(string $docType): DocRowsForm
    {
        $items = $this->configItems();
        $items['docs.core.applyRowOperations'] = [
            'invno' => ['byItemType' => ['0' => 'sale.services', '1' => 'sale.goods'], 'default' => 'sale.services'],
            'invni' => ['byItemType' => ['1' => 'purchase.goods'], 'default' => 'acc.entry'],
        ];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static function (string $sql, mixed ...$params) use ($docType): ?array {
                if (str_contains($sql, 'economy_items')) {
                    return [
                        1 => ['name' => 'Správa IT', 'sales_price_no_vat' => 5000.0, 'unit' => 8, 'item_type' => 0],
                        2 => ['name' => 'Kabel', 'sales_price_no_vat' => null, 'unit' => null, 'item_type' => 1],
                    ][(int) ($params[0] ?? 0)] ?? null;
                }
                return [
                    'doc_type' => $docType, 'cash_dir' => 0, 'vat_place' => 0, 'vat_duzp' => null,
                    'vat_mode' => 1, 'vat_registration' => null,
                ];
            },
        );
        $db->method('fetchAll')->willReturn([]);
        $form = new DocRowsForm('docs_core_rows');
        $form->setConfig(ConfigRuntimeFactory::fromItems($items));
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

    public function testOptionsFilteredByDocTypeAndSortedByOrder(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5];
        $def = $this->form('invno')->buildFormDefinition($data, true);

        $el = $this->findElement($def, 'operation');
        $this->assertNotNull($el);
        $this->assertSame(
            ['sale.services', 'sale.goods', 'sale.advanceDeduction', 'acc.entry'],
            array_column($el->options, 'value'),
        );
    }

    public function testOptionsForReceivedInvoice(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5];
        $def = $this->form('invni')->buildFormDefinition($data, true);

        $el = $this->findElement($def, 'operation');
        $this->assertSame(
            ['purchase.goods', 'acc.entry'],
            array_column($el->options, 'value'),
        );
    }

    public function testNewRowGetsDefaultOperationWithLowestOrder(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5];
        $this->form('invno')->applyNewRecordDefaults($data);

        $this->assertSame('sale.services', $data['operation']);
    }

    /**
     * Výchozí pohyb je jedno pravidlo pro formulář i pokyn (#166 U3). Nový
     * řádek položku ještě nemá → první pohyb nabídky, ne výchozí pohyb mapy
     * (*Účetní položka* přijaté faktury bez položky nejde potvrdit).
     */
    public function testNewRowWithoutItemKeepsFirstOfferedOperation(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5];
        $this->formWithOperationMap('invni')->applyNewRecordDefaults($data);

        $this->assertSame('purchase.goods', $data['operation']);
    }

    /** Nový řádek s prefillnutou položkou bere pohyb podle jejího typu. */
    public function testNewRowWithItemTakesOperationByItemType(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5, 'item' => 2];
        $this->formWithOperationMap('invno')->applyNewRecordDefaults($data);

        $this->assertSame('sale.goods', $data['operation']);
        $this->assertSame('Kabel', $data['description']);
    }

    /** Položka = řádek (#166 D42): text, cena a jednotka z karty se přepisují vždy. */
    public function testItemSelectionOverwritesTextPriceAndUnit(): void
    {
        $data = [
            'row_kind' => 1, 'doc_head' => 5, 'item' => 1, 'operation' => 'sale.services',
            'description' => 'starý text', 'unit_price' => 1, 'unit' => 3, 'quantity' => 2,
        ];
        $result = $this->formWithOperationMap('invno')->recalculate('item', $data);

        $this->assertSame('Správa IT', $result->data['description']);
        $this->assertSame(5000.0, $result->data['unit_price']);
        $this->assertSame(8, $result->data['unit']);
        $this->assertSame(2, $result->data['quantity'], 'množství se výběrem položky nemění');
    }

    /** Položka bez ceny a jednotky nechá cenu a jednotku řádku být. */
    public function testItemWithoutPriceKeepsRowPriceAndUnit(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5, 'item' => 2, 'operation' => 'sale.goods', 'unit_price' => 120.0, 'unit' => 3];
        $result = $this->formWithOperationMap('invno')->recalculate('item', $data);

        $this->assertSame('Kabel', $result->data['description']);
        $this->assertSame(120.0, $result->data['unit_price']);
        $this->assertSame(3, $result->data['unit']);
    }

    /** Výběr položky přenastaví automatický pohyb podle typu položky. */
    public function testItemSelectionResetsAutomaticOperationByItemType(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5, 'item' => 2, 'operation' => 'sale.services'];
        $result = $this->formWithOperationMap('invno')->recalculate('item', $data);
        $this->assertSame('sale.goods', $result->data['operation'], 'zásoba → prodej zboží');

        $data = ['row_kind' => 1, 'doc_head' => 5, 'item' => 2, 'operation' => 'acc.entry'];
        $result = $this->formWithOperationMap('invni')->recalculate('item', $data);
        $this->assertSame('purchase.goods', $result->data['operation'], 'výchozí pohyb mapy je automatický');
    }

    /** Ručně zvolený zvláštní pohyb výběr položky nepřepíše. */
    public function testItemSelectionKeepsManuallyChosenOperation(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5, 'item' => 2, 'operation' => 'sale.advanceDeduction'];
        $result = $this->formWithOperationMap('invno')->recalculate('item', $data);

        $this->assertSame('sale.advanceDeduction', $result->data['operation']);
    }

    public function testNewRowDefaultKeepsExplicitPrefill(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'sale.goods'];
        $this->form('invno')->applyNewRecordDefaults($data);

        $this->assertSame('sale.goods', $data['operation']);
    }

    public function testTextRowHidesOperationAndGetsNoDefault(): void
    {
        $data = ['row_kind' => 0, 'doc_head' => 5];
        $this->form('invno')->applyNewRecordDefaults($data);
        $def = $this->form('invno')->buildFormDefinition($data, true);

        $el = $this->findElement($def, 'operation');
        $this->assertTrue($el->hidden);
        $this->assertArrayNotHasKey('operation', $data);
    }

    public function testRecalculateRowKindToTextClearsOperation(): void
    {
        $data = ['row_kind' => 0, 'doc_head' => 5, 'operation' => 'sale.services', 'id' => 9];
        $result = $this->form('invno')->recalculate('row_kind', $data);

        $this->assertNull($result->data['operation']);
    }

    public function testRecalculateRowKindToStandardFillsDefault(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => null, 'id' => 9];
        $result = $this->form('invno')->recalculate('row_kind', $data);

        $this->assertSame('sale.services', $result->data['operation']);
    }

    // ── Pokladní doklad: pohyby dle cash_dir ────────────────────────────────

    public function testCashReceiptOffersOnlyReceiptOperations(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5];
        $def = $this->form('cash', cashDir: 1)->buildFormDefinition($data, true);

        $el = $this->findElement($def, 'operation');
        $this->assertSame(
            ['sale.services', 'sale.goods', 'payment.receivable', 'transfer.in', 'sale.advanceDeduction', 'advance.received', 'acc.entry'],
            array_column($el->options, 'value'),
        );
    }

    /**
     * Zálohy na pokladním dokladu (Task E): odpočet zálohy se chová jako na
     * faktuře — položkový řádek s DPH; hotovostní záloha je kontační bez DPH
     * s partnerem (povinným) a nepovinným VS.
     */
    public function testAdvanceDeductionOnCashKeepsItemLayoutWithVat(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'sale.advanceDeduction'];
        $def = $this->form('cash', cashDir: 1)->buildFormDefinition($data, true);

        $this->assertNotNull($this->findElement($def, 'item'), 'položkový layout');
        $this->assertNotNull($this->findElement($def, 'quantity'));
        $this->assertNotNull($this->findElement($def, 'vat_code'), 'odpočet zdaněné zálohy nese DPH');
        $this->assertNotNull($this->findElement($def, 'payment_reference'), 'číslo zálohového dokladu');
        $this->assertNull($this->findElement($def, 'partner'), 'odpočet partnera řádku nemá');
        $this->assertNull($this->findElement($def, 'acc_side'));
    }

    public function testCashAdvanceUsesContationLayoutWithPartner(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'advance.received'];
        $def = $this->form('cash', cashDir: 1)->buildFormDefinition($data, true);

        $this->assertNull($this->findElement($def, 'vat_code'), 'záloha v hotovosti je bez DPH');
        $this->assertNull($this->findElement($def, 'quantity'));
        $this->assertNull($this->findElement($def, 'item'));
        $this->assertNull($this->findElement($def, 'acc_side'), 'stranu nese krok předpisu');
        $this->assertNotNull($this->findElement($def, 'total_price'));
        $this->assertNotNull($this->findElement($def, 'partner'));
        $this->assertFalse($this->findElement($def, 'payment_reference')->required, 'VS nepovinný');
    }

    public function testCashDisbursementOffersPurchaseOpsAndDefaultsToLowestOrder(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5];
        $form = $this->form('cash', cashDir: 2);
        $def = $form->buildFormDefinition($data, true);

        $el = $this->findElement($def, 'operation');
        $this->assertSame(
            ['purchase.goods', 'payment.payable', 'transfer.out', 'acc.entry'],
            array_column($el->options, 'value'),
        );

        $form->applyNewRecordDefaults($data);
        $this->assertSame('purchase.goods', $data['operation']);
    }

    public function testPaymentRowUsesContationLayoutWithoutVatOrSide(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'payment.receivable'];
        $def = $this->form('cash', cashDir: 1)->buildFormDefinition($data, true);

        $this->assertNull($this->findElement($def, 'vat_code'), 'úhrada je bez DPH bloku');
        $this->assertNull($this->findElement($def, 'quantity'));
        $this->assertNull($this->findElement($def, 'acc_side'), 'stranu nese krok předpisu');
        $this->assertNotNull($this->findElement($def, 'total_price'));
        $this->assertNotNull($this->findElement($def, 'partner'));
        $this->assertNotNull($this->findElement($def, 'payment_reference'));
        $this->assertTrue($this->findElement($def, 'price_calc_mode')->hidden);
    }

    /**
     * Převod peněz (Task D): kontační layout jako úhrada, ale bez partnera
     * (žádný rowPartner) — partnera řídí výhradně vlajka, ne rowSide: 0.
     * payment_reference je nabídnutý, ne povinný.
     */
    public function testTransferRowUsesContationLayoutWithoutPartnerOrVat(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'transfer.out'];
        $def = $this->form('cash', cashDir: 2)->buildFormDefinition($data, true);

        $this->assertNull($this->findElement($def, 'vat_code'), 'převod je bez DPH bloku');
        $this->assertNull($this->findElement($def, 'quantity'));
        $this->assertNull($this->findElement($def, 'item'), 'převod je bez položky');
        $this->assertNull($this->findElement($def, 'acc_side'), 'stranu nese krok předpisu');
        $this->assertNull($this->findElement($def, 'partner'), 'převod nemá partnera (T3)');
        $this->assertTrue($this->findElement($def, 'total_price')->required);
        $ref = $this->findElement($def, 'payment_reference');
        $this->assertNotNull($ref, 'identifikace protistrany převodu');
        $this->assertFalse($ref->required, 'payment_reference u převodu nepovinný');
    }

    // ── Kód DPH nového řádku (#60) ──────────────────────────────────────────

    public function testNewItemRowGetsFirstVatCodeAndPct(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5];
        $this->formWithVat('invno', '2026-05-07')->applyNewRecordDefaults($data);

        $this->assertSame('sale.services', $data['operation']);
        $this->assertSame('cz-120', $data['vat_code'], 'první tuzemský výstupní kód = Základní');
        $this->assertSame(21.0, $data['vat_pct']);
    }

    public function testReceivedInvoiceRowGetsFirstInputVatCode(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5];
        $this->formWithVat('invni', '2026-05-07')->applyNewRecordDefaults($data);

        $this->assertSame('cz-110', $data['vat_code']);
        $this->assertSame(21.0, $data['vat_pct']);
    }

    public function testNewRowWithoutDuzpGetsVatCodeButNoPct(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5];
        $this->formWithVat('invno', null)->applyNewRecordDefaults($data);

        $this->assertSame('cz-120', $data['vat_code']);
        $this->assertArrayNotHasKey('vat_pct', $data, 'bez DUZP zůstává sazba ruční — jako recalculate');
    }

    public function testTextRowGetsNoVatCode(): void
    {
        $data = ['row_kind' => 0, 'doc_head' => 5];
        $this->formWithVat('invno', '2026-05-07')->applyNewRecordDefaults($data);

        $this->assertArrayNotHasKey('vat_code', $data);
        $this->assertArrayNotHasKey('operation', $data);
    }

    public function testNoVatHeadGetsOperationButNoVatCode(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5];
        $this->formWithVat('invno', '2026-05-07', vatMode: 0)->applyNewRecordDefaults($data);

        $this->assertSame('sale.services', $data['operation']);
        $this->assertArrayNotHasKey('vat_code', $data);
    }

    public function testExplicitOperationPrefillNoLongerBlocksVatCode(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'sale.goods'];
        $this->formWithVat('invno', '2026-05-07')->applyNewRecordDefaults($data);

        $this->assertSame('sale.goods', $data['operation']);
        $this->assertSame('cz-120', $data['vat_code']);
    }

    public function testExplicitVatCodeWins(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5, 'vat_code' => 'cz-121'];
        $this->formWithVat('invno', '2026-05-07')->applyNewRecordDefaults($data);

        $this->assertSame('cz-121', $data['vat_code']);
    }

    public function testContationRowGetsNoVatCode(): void
    {
        // payment.receivable má rowSide → kontační layout bez DPH bloku
        $data = ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'payment.receivable'];
        $this->formWithVat('cash', '2026-05-07', cashDir: 1)->applyNewRecordDefaults($data);

        $this->assertSame('payment.receivable', $data['operation']);
        $this->assertArrayNotHasKey('vat_code', $data);
    }

    public function testHeadWithoutRegistrationCountryGetsNoVatCode(): void
    {
        // mock z form(): registrace se nedohledá → žádná nabídka kódů → nic
        $data = ['row_kind' => 1, 'doc_head' => 5];
        $this->form('invno')->applyNewRecordDefaults($data);

        $this->assertSame('sale.services', $data['operation']);
        $this->assertArrayNotHasKey('vat_code', $data);
    }

    public function testRecalculateVatCodeStillDerivesPct(): void
    {
        $result = $this->formWithVat('invno', '2026-05-07')
            ->recalculate('vat_code', ['row_kind' => 1, 'doc_head' => 5, 'operation' => 'sale.services', 'vat_code' => 'cz-120', 'id' => 9]);

        $this->assertSame(21.0, $result->data['vat_pct']);
    }
}
