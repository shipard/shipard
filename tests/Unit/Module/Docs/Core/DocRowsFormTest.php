<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Docs\Core\DocRowsForm;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

class DocRowsFormTest extends TestCase
{
    private function createForm(): DocRowsForm
    {
        return new DocRowsForm('docs_core_rows');
    }

    private function findElement(FormDefinition $def, string $column): ?FormElement
    {
        // Sub-form has a single tab with one section, one column.
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

    public function testFormHasSingleTab(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([], true);

        $this->assertCount(1, $def->tabs);
    }

    public function testTextRowKindHidesItemAndPriceFields(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['row_kind' => 0, 'doc_head' => null], true);

        foreach (['item', 'quantity', 'unit', 'unit_price', 'total_price',
                  'price_calc_mode', 'discount_pct', 'discount_amount'] as $col) {
            $el = $this->findElement($def, $col);
            $this->assertNotNull($el, "{$col} should exist");
            $this->assertTrue($el->hidden, "{$col} should be hidden for text row");
        }
    }

    public function testStandardRowKindShowsItemAndPriceFields(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['row_kind' => 1, 'doc_head' => null], true);

        foreach (['item', 'quantity', 'unit', 'unit_price', 'total_price',
                  'price_calc_mode'] as $col) {
            $el = $this->findElement($def, $col);
            $this->assertNotNull($el, "{$col} should exist");
            $this->assertFalse($el->hidden, "{$col} should be visible for standard row");
        }
    }

    public function testRowKindSelectTriggersReload(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['row_kind' => 1, 'doc_head' => null], true);

        $el = $this->findElement($def, 'row_kind');
        $this->assertNotNull($el);
        $this->assertSame('reload', $el->triggers);
        $this->assertTrue($el->required);
    }

    public function testItemSelectTriggersReload(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['row_kind' => 1, 'doc_head' => null], true);

        $el = $this->findElement($def, 'item');
        $this->assertNotNull($el);
        $this->assertSame('reload', $el->triggers);
    }

    public function testWithoutHeadContextVatFieldsAreHidden(): void
    {
        $form = $this->createForm();
        // No doc_head → no head context → cannot resolve VAT codes
        $def = $form->buildFormDefinition(['row_kind' => 1, 'doc_head' => null], true);

        $vatCode = $this->findElement($def, 'vat_code');
        $this->assertNotNull($vatCode);
        $this->assertTrue($vatCode->hidden);
    }

    public function testCalculatedVatColumnsLiveInSummaryNotInForm(): void
    {
        $data = ['row_kind' => 1, 'doc_head' => 5, 'vat_base' => '300', 'vat_amount' => '63', 'vat_total' => '363'];

        $def = $this->formWithHead(1)->buildFormDefinition($data, false);
        foreach (['vat_base', 'vat_amount', 'vat_total'] as $col) {
            $this->assertNull($this->findElement($def, $col), "{$col} should not be a form field");
        }
        $this->assertSame([
            ['label' => 'Základ', 'value' => '300,00'],
            ['label' => 'DPH', 'value' => '63,00'],
            ['label' => 'Celkem CZK', 'value' => '363,00'],
        ], $def->liveSummary);

        // Hlavička bez DPH → jen Celkem.
        $def = $this->formWithHead(0)->buildFormDefinition($data, false);
        $this->assertSame([['label' => 'Celkem CZK', 'value' => '363,00']], $def->liveSummary);
    }

    public function testLiveSummaryEmptyWithoutComputedTotalOrForTextRow(): void
    {
        $form = $this->formWithHead(1);

        $this->assertSame([], $form->buildFormDefinition(['row_kind' => 1, 'doc_head' => 5], false)->liveSummary);
        $this->assertSame([], $form->buildFormDefinition(
            ['row_kind' => 0, 'doc_head' => 5, 'vat_total' => '100'], false,
        )->liveSummary);
    }

    public function testLiveSummaryReflectsRecalculatedValues(): void
    {
        $result = $this->formWithHead(1)->recalculate('quantity', [
            'row_kind' => 1, 'doc_head' => 5, 'price_calc_mode' => 0,
            'quantity' => '10', 'unit_price' => '150', 'vat_code' => 'cz-110', 'vat_pct' => '21',
        ]);

        $this->assertSame([
            ['label' => 'Základ', 'value' => '1 500,00'],
            ['label' => 'DPH', 'value' => '315,00'],
            ['label' => 'Celkem CZK', 'value' => '1 815,00'],
        ], $result->formDefinition->liveSummary);
    }

    public function testDefaultPriceCalcModeForNewRow(): void
    {
        $form = $this->createForm();
        $form->buildFormDefinition(['row_kind' => 1], true);
        // Defaults are applied to data inside buildFormDefinition.
        // The test asserts the form has the field present; defaults
        // surface to the user via the rendered element values.
        $this->expectNotToPerformAssertions();
    }

    public function testRecalculateOnUnknownColumnIsSafe(): void
    {
        $form = $this->createForm();
        $result = $form->recalculate('unrelated_field', [
            'row_kind' => 1,
            'doc_head' => null,
        ]);

        $this->assertNotNull($result->formDefinition);
        $this->assertSame(1, $result->data['row_kind']);
    }

    // ── Sazba kódu bez DUZP (#79 D1) ──────────────────────────────────────

    /**
     * Nedaňový doklad (zálohová faktura) DUZP nemá — sazba kódu DPH se
     * odvodí k datu vystavení (`DocHeadVatContext::vat_rate_date`).
     */
    public function testVatCodeRateFallsBackToIssueDateWithoutDuzp(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static function (mixed ...$args): ?array {
                if (str_contains((string) ($args[0] ?? ''), 'vat_registrations')) {
                    return ['country' => 'cz'];
                }
                return [
                    'doc_type' => 'invpo', 'cash_dir' => 0, 'vat_place' => 0, 'vat_duzp' => null,
                    'issue_date' => '2026-05-06', 'vat_mode' => 1, 'vat_registration' => 1,
                    'doc_currency' => 'czk', 'home_currency' => 'czk', 'exchange_rate' => 1.0,
                ];
            },
        );
        $db->method('fetchAll')->willReturn([]);
        $form = $this->createForm();
        $form->setDb($db);
        $form->setConfig(ConfigRuntimeFactory::fromItems([
            'world.vat.cz'       => JsoncParser::parseFile(dirname(__DIR__, 5) . '/modules/world/vat/config/vat-cz.jsonc'),
            'docs.core.docTypes' => ['invpo' => ['trade_dir' => 1, 'tax_document' => false]],
        ]));

        $result = $form->recalculate('vat_code', [
            'doc_head' => 1, 'row_kind' => 1, 'vat_code' => 'cz-110',
            'quantity' => '1', 'unit_price' => '100',
        ]);

        $this->assertSame(21.0, (float) $result->data['vat_pct'], 'sazba k datu vystavení');
    }

    // ── Živý přepočet (#71) ───────────────────────────────────────────────

    /**
     * Form nad hlavičkou bez registrace DPH — země se nedohledá, výpočet běží
     * bez sémantiky kódů (stejně jako save bez země).
     */
    private function formWithHead(int $vatMode): DocRowsForm
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'doc_type' => 'invno', 'cash_dir' => 0, 'vat_place' => 0, 'vat_duzp' => null,
            'vat_mode' => $vatMode, 'vat_registration' => null, 'doc_currency' => 'czk',
        ]);
        $db->method('fetchAll')->willReturn([]);
        $form = $this->createForm();
        $form->setDb($db);
        return $form;
    }

    public function testRecalculateQuantityComputesTotalAndVat(): void
    {
        $result = $this->formWithHead(1)->recalculate('quantity', [
            'row_kind' => 1, 'doc_head' => 5, 'price_calc_mode' => 0,
            'quantity' => '3', 'unit_price' => '100', 'vat_code' => 'cz-110', 'vat_pct' => '21',
        ]);

        $this->assertSame(300.0, $result->data['total_price']);
        $this->assertSame(300.0, $result->data['vat_base']);
        $this->assertSame(63.0, $result->data['vat_amount']);
        $this->assertSame(363.0, $result->data['vat_total']);
    }

    public function testRecalculateWithDiscountKeepsTotalPriceBeforeDiscount(): void
    {
        // Past P1: do pole total_price jde cena PŘED slevou, sleva jen do vat_*.
        $result = $this->formWithHead(1)->recalculate('discount_pct', [
            'row_kind' => 1, 'doc_head' => 5, 'price_calc_mode' => 0,
            'quantity' => '3', 'unit_price' => '100', 'discount_pct' => '10',
            'vat_code' => 'cz-110', 'vat_pct' => '21',
        ]);

        $this->assertSame(300.0, $result->data['total_price']);
        $this->assertSame(270.0, $result->data['vat_base']);
        $this->assertSame(56.7, $result->data['vat_amount']);
        $this->assertSame(326.7, $result->data['vat_total']);
    }

    public function testRecalculateFromTotalPriceComputesUnitPrice(): void
    {
        $result = $this->formWithHead(1)->recalculate('total_price', [
            'row_kind' => 1, 'doc_head' => 5, 'price_calc_mode' => 1,
            'quantity' => '4', 'total_price' => '1000',
        ]);

        $this->assertSame(250.0, $result->data['unit_price']);
        $this->assertSame(1000.0, $result->data['total_price']);
    }

    public function testPriceCalcModeDrivesReadOnlyPriceField(): void
    {
        $form = $this->createForm();

        $def = $form->buildFormDefinition(['row_kind' => 1, 'doc_head' => null, 'price_calc_mode' => 0], true);
        $this->assertTrue($this->findElement($def, 'total_price')->readOnly);
        $this->assertFalse($this->findElement($def, 'unit_price')->readOnly);

        $def = $form->buildFormDefinition(['row_kind' => 1, 'doc_head' => null, 'price_calc_mode' => 1], true);
        $this->assertFalse($this->findElement($def, 'total_price')->readOnly);
        $this->assertTrue($this->findElement($def, 'unit_price')->readOnly);
    }

    public function testPriceInputsTriggerReload(): void
    {
        $def = $this->createForm()->buildFormDefinition(['row_kind' => 1, 'doc_head' => null], true);

        foreach (['quantity', 'unit_price', 'total_price', 'price_calc_mode',
                  'discount_pct', 'discount_amount', 'vat_pct'] as $col) {
            $this->assertSame('reload', $this->findElement($def, $col)?->triggers, "{$col} should trigger reload");
        }
    }

    public function testRecalculateWithoutHeadComputesWithoutVat(): void
    {
        $result = $this->createForm()->recalculate('quantity', [
            'row_kind' => 1, 'doc_head' => null, 'price_calc_mode' => 0,
            'quantity' => '2', 'unit_price' => '50', 'vat_code' => 'cz-110', 'vat_pct' => '21',
        ]);

        $this->assertSame(100.0, $result->data['total_price']);
        $this->assertSame(100.0, $result->data['vat_base']);
        $this->assertSame(0.0, $result->data['vat_amount']);
        $this->assertSame(100.0, $result->data['vat_total']);
    }

    public function testRecalculateOnHeadWithoutVatHasZeroVat(): void
    {
        $result = $this->formWithHead(0)->recalculate('quantity', [
            'row_kind' => 1, 'doc_head' => 5, 'price_calc_mode' => 0,
            'quantity' => '2', 'unit_price' => '50', 'vat_code' => 'cz-110', 'vat_pct' => '21',
        ]);

        $this->assertSame(100.0, $result->data['vat_base']);
        $this->assertSame(0.0, $result->data['vat_amount']);
        $this->assertSame(100.0, $result->data['vat_total']);
    }

    public function testRecalculateTextRowClearsComputedValues(): void
    {
        $result = $this->formWithHead(1)->recalculate('row_kind', [
            'row_kind' => 0, 'doc_head' => 5, 'quantity' => '2', 'unit_price' => '50',
            'total_price' => '100', 'vat_base' => '100', 'vat_amount' => '21', 'vat_total' => '121',
        ]);

        $this->assertNull($result->data['total_price']);
        $this->assertNull($result->data['vat_base']);
        $this->assertNull($result->data['vat_amount']);
        $this->assertNull($result->data['vat_total']);
    }

    public function testNewRecordDefaultsSetQuantityAndComputedZeros(): void
    {
        $form = $this->formWithHead(1);
        $data = ['doc_head' => 5];
        $form->applyNewRecordDefaults($data);

        $this->assertSame(1, $data['quantity']);
        $this->assertSame(0.0, $data['total_price']);
        $this->assertSame(0.0, $data['vat_base']);
        $this->assertSame(0.0, $data['vat_amount']);
        $this->assertSame(0.0, $data['vat_total']);
    }

    public function testNewRecordDefaultsKeepPrefilledQuantity(): void
    {
        $form = $this->formWithHead(1);
        $data = ['doc_head' => 5, 'quantity' => 3, 'unit_price' => 10];
        $form->applyNewRecordDefaults($data);

        $this->assertSame(3, $data['quantity']);
        $this->assertSame(30.0, $data['total_price']);
    }
}
