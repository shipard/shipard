<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Accounting\JournalDimensionSet;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Module\Docs\AccountingDocs\AccountingDocsForm;
use Shipard\Module\Docs\CashDocs\CashDocForm;
use Shipard\Module\Docs\Core\DocRowsForm;
use Shipard\Module\Docs\Core\DocsHeadsForm;
use Shipard\Module\Docs\Core\DocsHeadsFormBase;
use Shipard\Module\Docs\InvoicesIn\ReceivedInvoiceForm;
use Shipard\Module\Docs\InvoicesOut\IssuedInvoiceForm;
use Shipard\Module\Docs\ProformasOut\ProformaOutForm;

/**
 * Pole dimenzí deníku na formulářích dokladů (`journalDimensions[].forms`,
 * assets D59): hlavička i řádek je nabízí jen u deklarovaného typu dokladu
 * a se zapnutým nastavením; sdílený helper volá každý per-typ formulář.
 */
class DimensionFormFieldsTest extends TestCase
{
    private const SETTING = 'economy.accounting.dimension.asset';
    private const SETTING_CC = 'economy.accounting.dimension.costCenter';
    private const SETTING_WO = 'economy.accounting.dimension.workOrder';

    private function config(): ConfigRuntime
    {
        $items = [
            JournalDimensionSet::CFG_ITEM => [
                // Pořadí dimenzí = pořadí polí (#110 D20): středisko před majetkem.
                'costCenter' => [
                    'id' => 'costCenter', 'rowColumn' => 'cost_center', 'headColumn' => 'cost_center',
                    'journalColumn' => 'cost_center', 'table' => 'economy_codebooks_cost_centers',
                    'name' => 'Středisko', 'displayPattern' => '{code} — {name}',
                    'forms' => [
                        'docTypes' => ['invno', 'invpo', 'invni', 'cash', 'cmnbkp'], 'head' => true, 'rows' => true,
                        'enabledBySetting' => self::SETTING_CC,
                    ],
                ],
                'workOrder' => [
                    'id' => 'workOrder', 'rowColumn' => 'work_order', 'headColumn' => 'work_order',
                    'journalColumn' => 'work_order', 'table' => 'economy_work_orders_heads',
                    'name' => 'Zakázka', 'displayPattern' => '{number} — {title}',
                    'forms' => [
                        'docTypes' => ['invno', 'invpo', 'invni', 'cash', 'cmnbkp'], 'head' => true, 'rows' => true,
                        'enabledBySetting' => self::SETTING_WO,
                    ],
                ],
                'asset' => [
                    'id' => 'asset', 'rowColumn' => 'asset', 'headColumn' => 'asset', 'journalColumn' => 'asset',
                    'table' => 'economy_assets_assets', 'name' => 'Majetek', 'rowFlag' => 'rowAsset',
                    'displayPattern' => '{asset_number} — {name}',
                    'forms' => [
                        'docTypes' => ['invni', 'invno', 'cash', 'cmnbkp'], 'head' => true, 'rows' => true,
                        'enabledBySetting' => self::SETTING,
                    ],
                ],
            ],
            'docs.core.docTypes' => [
                'invno' => ['trade_dir' => 1],
                'invpo' => ['trade_dir' => 1, 'tax_document' => false],
                'invni' => ['trade_dir' => 2],
                'cash'  => ['trade_dir' => 0, 'trade_dir_column' => 'cash_dir', 'series_binding' => 'cash_desk'],
            ],
            'docs.core.rowOperations' => [
                'purchase.goods' => ['name' => 'Nákup zboží', 'docTypes' => ['invni' => ['order' => 100]]],
                'purchase.asset' => [
                    'name' => 'Pořízení majetku', 'rowAccount' => 'direct', 'rowAsset' => 'optional',
                    'docTypes' => ['invni' => ['order' => 600]],
                ],
                'acc.record' => [
                    'name' => 'Účetní zápis', 'rowSide' => 1, 'rowAccount' => 'direct',
                    'docTypes' => ['cmnbkp' => ['order' => 100]],
                ],
                'asset.depreciation' => [
                    'name' => 'Odpis majetku', 'rowSide' => 1, 'rowAccount' => 'direct',
                    'rowAsset' => 1, 'system' => 1,
                    'docTypes' => ['cmnbkp' => ['order' => 930]],
                ],
            ],
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn(string $id): mixed => $items[$id] ?? null);
        return $config;
    }

    /**
     * @param array<string, mixed>|null $head hlavička, kterou řádkový formulář načte
     * @param array<string, mixed>|null $headAsset karta na hlavičce (placeholder řádku)
     * @param string|null $costCenterSetting nastavení dimenze Středisko (null = nerozhodnuto)
     */
    private function db(
        ?string $setting,
        ?array $head = null,
        ?array $headAsset = null,
        ?string $costCenterSetting = null,
        ?string $workOrderSetting = null,
    ): DataSourceConnection {
        $settings = [self::SETTING => $setting, self::SETTING_CC => $costCenterSetting, self::SETTING_WO => $workOrderSetting];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturnCallback(
            static function (string $sql, mixed ...$args) use ($settings): mixed {
                $value = $settings[$args[0] ?? ''] ?? null;
                return $value !== null ? json_encode($value) : null;
            },
        );
        $db->method('fetchRow')->willReturnCallback(
            static fn(string $sql): ?array => match (true) {
                str_contains($sql, 'economy_assets_assets')          => $headAsset,
                str_contains($sql, 'economy_codebooks_cost_centers') => null,
                str_contains($sql, 'economy_work_orders_heads')      => null,
                default                                              => $head,
            },
        );
        $db->method('fetchAll')->willReturn([]);
        return $db;
    }

    private function findElement(FormDefinition $def, string $column): ?FormElement
    {
        foreach ($def->tabs as $tab) {
            foreach ($tab->sections as $section) {
                foreach ($section->columns as $col) {
                    foreach ($col->elements as $el) {
                        if ($el->column === $column) {
                            return $el;
                        }
                    }
                }
            }
        }
        return null;
    }

    /** @param class-string<DocsHeadsFormBase> $class */
    private function headDefinition(
        string $class,
        array $data,
        ?string $setting,
        ?string $costCenterSetting = null,
        ?string $workOrderSetting = null,
    ): FormDefinition {
        $form = new $class('docs_core_heads');
        $form->setConfig($this->config());
        $form->setDb($this->db($setting, costCenterSetting: $costCenterSetting, workOrderSetting: $workOrderSetting));
        return $form->buildFormDefinition($data, true);
    }

    /** @return list<string> sloupce polí dimenzí v pořadí na formuláři */
    private function dimensionColumns(FormDefinition $def): array
    {
        $out = [];
        foreach ($def->tabs as $tab) {
            foreach ($tab->sections as $section) {
                foreach ($section->columns as $col) {
                    foreach ($col->elements as $el) {
                        if (in_array($el->column, ['cost_center', 'work_order', 'asset'], true)) {
                            $out[] = $el->column;
                        }
                    }
                }
            }
        }
        return $out;
    }

    /** @return iterable<string, array{class-string<DocsHeadsFormBase>, array<string, mixed>}> */
    public static function headForms(): iterable
    {
        yield 'invni'   => [ReceivedInvoiceForm::class, ['doc_type' => 'invni']];
        yield 'invno'   => [IssuedInvoiceForm::class, ['doc_type' => 'invno']];
        yield 'cash'    => [CashDocForm::class, ['doc_type' => 'cash', 'cash_dir' => 2]];
        yield 'cmnbkp'  => [AccountingDocsForm::class, ['doc_type' => 'cmnbkp']];
        yield 'generic' => [DocsHeadsForm::class, ['doc_type' => 'invni']];
    }

    #[DataProvider('headForms')]
    public function testHeadOffersDimensionOnlyWithSettingOn(string $class, array $data): void
    {
        $field = $this->findElement($this->headDefinition($class, $data, 'yes'), 'asset');
        $this->assertNotNull($field, "{$class} nabízí pole dimenze");
        $this->assertSame('lookup', $field->type);
        $this->assertSame('economy_assets_assets', $field->lookup['table']);
        $this->assertSame('Majetek', $field->label);
        $this->assertFalse($field->required);

        $this->assertNull($this->findElement($this->headDefinition($class, $data, 'no'), 'asset'));
        $this->assertNull($this->findElement($this->headDefinition($class, $data, null), 'asset'));
    }

    public function testProformaSharesLayoutButHasNoDimensionField(): void
    {
        // invpo sdílí buildHeaderTab s FV, ale dimenze ho nedeklaruje.
        $this->assertNull($this->findElement(
            $this->headDefinition(ProformaOutForm::class, ['doc_type' => 'invpo'], 'yes'),
            'asset',
        ));
    }

    public function testProformaOffersCostCenterButNotAsset(): void
    {
        // #110 D23, T3: středisko je i na zálohové faktuře vydané, majetek ne.
        $def = $this->headDefinition(ProformaOutForm::class, ['doc_type' => 'invpo'], 'yes', 'yes');
        $field = $this->findElement($def, 'cost_center');
        $this->assertNotNull($field);
        $this->assertSame('lookup', $field->type);
        $this->assertSame('economy_codebooks_cost_centers', $field->lookup['table']);
        $this->assertSame('Středisko', $field->label);
        $this->assertFalse($field->required);
        $this->assertNull($this->findElement($def, 'asset'));

        $this->assertNull($this->findElement(
            $this->headDefinition(ProformaOutForm::class, ['doc_type' => 'invpo'], 'yes', 'no'),
            'cost_center',
        ));
    }

    #[DataProvider('headForms')]
    public function testHeadOffersDimensionsInDeclaredOrder(string $class, array $data): void
    {
        // Všechny dimenze zapnuté: pole v pořadí dimenzí (středisko →
        // zakázka → majetek); každé řídí jen své nastavení.
        $this->assertSame(
            ['cost_center', 'work_order', 'asset'],
            $this->dimensionColumns($this->headDefinition($class, $data, 'yes', 'yes', 'yes')),
        );
        $this->assertSame(['cost_center', 'asset'], $this->dimensionColumns($this->headDefinition($class, $data, 'yes', 'yes')));
        $this->assertSame(['cost_center'], $this->dimensionColumns($this->headDefinition($class, $data, 'no', 'yes')));
        $this->assertSame(['asset'], $this->dimensionColumns($this->headDefinition($class, $data, 'yes', null)));
        $this->assertSame(['work_order'], $this->dimensionColumns($this->headDefinition($class, $data, 'no', 'no', 'yes')));
    }

    #[DataProvider('headForms')]
    public function testHeadOffersWorkOrderOnlyWithSettingOn(string $class, array $data): void
    {
        // #110 D23: zakázka na hlavičce jako výchozí hodnota pro řádky.
        $field = $this->findElement($this->headDefinition($class, $data, null, null, 'yes'), 'work_order');
        $this->assertNotNull($field, "{$class} nabízí pole Zakázka");
        $this->assertSame('lookup', $field->type);
        $this->assertSame('economy_work_orders_heads', $field->lookup['table']);
        $this->assertSame('Zakázka', $field->label);
        $this->assertFalse($field->required);

        $this->assertNull($this->findElement($this->headDefinition($class, $data, null, null, 'no'), 'work_order'));
        $this->assertNull($this->findElement($this->headDefinition($class, $data, null, null, null), 'work_order'));
    }

    public function testProformaOffersWorkOrder(): void
    {
        // Zálohová faktura vydaná zakázku nese — periodická fakturace z ní
        // vystavuje zálohy (tasks/work-orders-phase1.md §4).
        $field = $this->findElement($this->headDefinition(ProformaOutForm::class, ['doc_type' => 'invpo'], null, null, 'yes'), 'work_order');
        $this->assertNotNull($field);
        $this->assertSame('economy_work_orders_heads', $field->lookup['table']);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $headAsset
     */
    private function rowDefinition(
        string $docType,
        array $data,
        ?string $setting,
        ?array $headAsset = null,
        ?string $costCenterSetting = null,
        ?string $workOrderSetting = null,
    ): FormDefinition {
        $form = new DocRowsForm('docs_core_rows');
        $form->setConfig($this->config());
        $form->setDb($this->db($setting, [
            'doc_type' => $docType, 'vat_place' => 0, 'vat_duzp' => null, 'vat_mode' => 0, 'vat_registration' => null,
        ], $headAsset, $costCenterSetting, $workOrderSetting));
        return $form->buildFormDefinition($data + ['row_kind' => 1, 'doc_head' => 5], true);
    }

    public function testRowOffersCostCenterOnEveryDocumentIncludingAcquisition(): void
    {
        // Středisko na řádku zálohové faktury i na řádku pořízení majetku —
        // vlajka rowAsset se střediska netýká, pole je generické a nepovinné.
        foreach ([['invpo', 'purchase.goods'], ['invni', 'purchase.asset'], ['cmnbkp', 'acc.record']] as [$docType, $operation]) {
            $field = $this->findElement($this->rowDefinition($docType, ['operation' => $operation], 'no', null, 'yes'), 'cost_center');
            $this->assertNotNull($field, "{$docType} / {$operation}");
            $this->assertSame('economy_codebooks_cost_centers', $field->lookup['table']);
            $this->assertFalse($field->required);
            $this->assertNull($this->findElement($this->rowDefinition($docType, ['operation' => $operation], 'no', null, 'no'), 'cost_center'));
        }
    }

    public function testRowOffersWorkOrderOnEveryDocumentIncludingAcquisition(): void
    {
        // Zakázka na řádku zálohové faktury, pořízení majetku i kontace —
        // pole je generické a nepovinné, řídí ho jen vlastní nastavení.
        foreach ([['invpo', 'purchase.goods'], ['invni', 'purchase.asset'], ['cmnbkp', 'acc.record']] as [$docType, $operation]) {
            $field = $this->findElement($this->rowDefinition($docType, ['operation' => $operation], 'no', null, 'no', 'yes'), 'work_order');
            $this->assertNotNull($field, "{$docType} / {$operation}");
            $this->assertSame('economy_work_orders_heads', $field->lookup['table']);
            $this->assertFalse($field->required);
            $this->assertNull($this->findElement($this->rowDefinition($docType, ['operation' => $operation], 'no', null, 'no', 'no'), 'work_order'));
        }
    }

    public function testItemRowOffersDimensionWithSettingOn(): void
    {
        $data = ['operation' => 'purchase.goods'];

        $field = $this->findElement($this->rowDefinition('invni', $data, 'yes'), 'asset');
        $this->assertNotNull($field);
        $this->assertSame('economy_assets_assets', $field->lookup['table']);
        $this->assertFalse($field->required);
        $this->assertFalse($field->hidden);

        $this->assertNull($this->findElement($this->rowDefinition('invni', $data, 'no'), 'asset'));
        // Textový řádek se neúčtuje — pole skryté.
        $text = $this->findElement($this->rowDefinition('invni', ['row_kind' => 0], 'yes'), 'asset');
        $this->assertTrue($text?->hidden);
    }

    public function testRowPlaceholderShowsHeadDefault(): void
    {
        // D60: řádek bez vlastní karty zdědí kartu hlavičky — pole to říká.
        $headAsset = ['id' => 7, 'asset_number' => 'MA0007', 'name' => 'Soustruh'];
        $data = ['operation' => 'purchase.goods'];

        $this->assertSame(
            'Z hlavičky: MA0007 — Soustruh',
            $this->findElement($this->rowDefinition('invni', $data, 'yes', $headAsset), 'asset')?->placeholder,
        );
        $this->assertNull($this->findElement($this->rowDefinition('invni', $data, 'yes'), 'asset')?->placeholder);
    }

    public function testAcquisitionRowAlwaysHasOptionalCardWithoutHeadDefault(): void
    {
        // D61 + rozhodnutí fáze 4: pořízení je věc řádku — pole je vždy,
        // nepovinné, a kartu z hlavičky nenabízí ani jako placeholder.
        $headAsset = ['id' => 7, 'asset_number' => 'MA0007', 'name' => 'Soustruh'];
        foreach (['yes', 'no', null] as $setting) {
            $def = $this->rowDefinition('invni', ['operation' => 'purchase.asset'], $setting, $headAsset);
            $fields = [];
            foreach ($def->tabs[0]->sections as $section) {
                foreach ($section->columns as $col) {
                    foreach ($col->elements as $el) {
                        if ($el->column === 'asset') {
                            $fields[] = $el;
                        }
                    }
                }
            }
            $this->assertCount(1, $fields);
            $this->assertSame('economy_assets_assets', $fields[0]->lookup['table']);
            $this->assertFalse($fields[0]->required);
            $this->assertStringNotContainsString('Z hlavičky', (string) $fields[0]->placeholder);
            // D62: kartu jde z řádku založit s předvyplněním a upravit.
            $this->assertTrue($fields[0]->lookup['create_form']);
            $this->assertTrue($fields[0]->lookup['edit_form']);
            $this->assertTrue($fields[0]->lookup['create_defaults']);
        }
    }

    public function testContationRowOffersDimensionWithSettingOn(): void
    {
        $data = ['operation' => 'acc.record'];

        $this->assertNotNull($this->findElement($this->rowDefinition('cmnbkp', $data, 'yes'), 'asset'));
        $this->assertNull($this->findElement($this->rowDefinition('cmnbkp', $data, 'no'), 'asset'));
    }

    public function testRowAssetOperationKeepsItsOwnRequiredField(): void
    {
        // Systémová operace majetku: pole staví vlajka rowAsset (povinné,
        // read-only) bez ohledu na nastavení a generické pole ho nezdvojí.
        foreach (['yes', 'no'] as $setting) {
            $def = $this->rowDefinition('cmnbkp', ['operation' => 'asset.depreciation'], $setting);
            $fields = [];
            foreach ($def->tabs[0]->sections as $section) {
                foreach ($section->columns as $col) {
                    foreach ($col->elements as $el) {
                        if ($el->column === 'asset') {
                            $fields[] = $el;
                        }
                    }
                }
            }
            $this->assertCount(1, $fields);
            $this->assertTrue($fields[0]->required);
            $this->assertTrue($fields[0]->readOnly);
        }
    }
}
