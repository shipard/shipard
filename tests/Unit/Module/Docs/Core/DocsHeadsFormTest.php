<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Form\FormTab;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Docs\Core\DocsHeadsForm;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsForm;

class DocsHeadsFormTest extends TestCase
{
    private function createForm(): DocsHeadsForm
    {
        return new DocsHeadsForm('docs_core_heads');
    }

    /** @return string[] */
    private function tabIds(FormDefinition $def): array
    {
        return array_map(static fn(FormTab $tab) => $tab->id, $def->tabs);
    }

    private function findElement(FormDefinition $def, string $tabId, string $column): ?FormElement
    {
        foreach ($def->tabs as $tab) {
            if ($tab->id !== $tabId) {
                continue;
            }
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

    private function findTab(FormDefinition $def, string $tabId): ?FormTab
    {
        foreach ($def->tabs as $tab) {
            if ($tab->id === $tabId) {
                return $tab;
            }
        }
        return null;
    }

    /** Find the first element of a given type in a tab (e.g. 'component'). */
    private function findElementByType(FormDefinition $def, string $tabId, string $type): ?FormElement
    {
        $tab = $this->findTab($def, $tabId);
        if ($tab === null) {
            return null;
        }
        foreach ($tab->sections as $section) {
            foreach ($section->columns as $col) {
                foreach ($col->elements as $el) {
                    if ($el->type === $type) {
                        return $el;
                    }
                }
            }
        }
        return null;
    }

    // ── Tab presence ─────────────────────────────────────────────────────────

    public function testNewDocumentHasBaseTabsWithoutSnapshots(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([], true);

        $this->assertSame(
            ['basic', 'rows', 'recap', 'notes', 'attachments', 'settings'],
            $this->tabIds($def),
        );
    }

    // ── cash_desk (pokladna při platbě v hotovosti, #59 D4) ─────────────────

    public function testCashDeskHiddenUnlessCashPaymentAndPaymentMethodReloads(): void
    {
        $form = $this->createForm();

        // nový doklad: payment_method default 1 (převodem) → pokladna skrytá
        $def = $form->buildFormDefinition([], true);
        $cashDesk = $this->findElement($def, 'basic', 'cash_desk');
        $this->assertNotNull($cashDesk);
        $this->assertSame('lookup', $cashDesk->type);
        $this->assertTrue($cashDesk->hidden);
        $this->assertSame('reload', $this->findElement($def, 'basic', 'payment_method')->triggers);

        // hotovost bez pokladny → viditelná s hintem na chybějící výchozí pokladnu
        $def = $form->buildFormDefinition(['payment_method' => 0, 'doc_currency' => 'eur'], true);
        $cashDesk = $this->findElement($def, 'basic', 'cash_desk');
        $this->assertFalse($cashDesk->hidden);
        $this->assertStringContainsString('EUR', (string) $cashDesk->hint);

        // hotovost s pokladnou → bez hintu
        $def = $form->buildFormDefinition(['payment_method' => 0, 'cash_desk' => 7], true);
        $this->assertNull($this->findElement($def, 'basic', 'cash_desk')->hint);
    }

    public function testRecalculatePaymentMethodDefaultsAndClearsCashDesk(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);
        $db->method('fetchRow')->willReturnCallback(
            static function (string $sql, mixed ...$params): ?array {
                if (str_contains($sql, 'economy_codebooks_cash_desks')) {
                    return $params[0] === 'czk' ? ['id' => 7] : null;
                }
                return null;
            },
        );
        $form = $this->createForm();
        $form->setDb($db);

        // převodem → hotovost: výchozí pokladna měny dokladu
        $result = $form->recalculate('payment_method', [
            'payment_method' => 0, 'doc_currency' => 'czk', 'home_currency' => 'czk',
        ]);
        $this->assertSame(7, $result->data['cash_desk']);

        // měna bez výchozí pokladny → zůstane prázdná
        $result = $form->recalculate('payment_method', [
            'payment_method' => 0, 'doc_currency' => 'eur', 'home_currency' => 'czk',
        ]);
        $this->assertNull($result->data['cash_desk']);

        // ručně vybraná pokladna se nepřepisuje
        $result = $form->recalculate('payment_method', [
            'payment_method' => 0, 'doc_currency' => 'czk', 'cash_desk' => 3,
        ]);
        $this->assertSame(3, $result->data['cash_desk']);

        // hotovost → převodem: pokladna se smaže (validace by ji odmítla)
        $result = $form->recalculate('payment_method', [
            'payment_method' => 1, 'doc_currency' => 'czk', 'cash_desk' => 7,
        ]);
        $this->assertNull($result->data['cash_desk']);
        $this->assertTrue($this->findElement($result->formDefinition, 'basic', 'cash_desk')->hidden);
    }

    public function testSupplierSnapshotAddsSnapshotsTab(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([
            'supplier_snapshot' => ['name' => 'ACME s.r.o.'],
        ], false);

        $this->assertContains('snapshots', $this->tabIds($def));
    }

    public function testCustomerSnapshotAloneAlsoAddsSnapshotsTab(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([
            'customer_snapshot' => ['name' => 'Zákazník s.r.o.'],
        ], false);

        $this->assertContains('snapshots', $this->tabIds($def));
    }

    public function testEmptySnapshotArrayDoesNotAddTab(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([
            'supplier_snapshot' => [],
            'customer_snapshot' => null,
        ], false);

        $this->assertNotContains('snapshots', $this->tabIds($def));
    }

    public function testFormTitled(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([], true);

        $this->assertSame('Doklad', $def->title);
        $this->assertSame('Nový doklad', $def->titleNew);
        $this->assertSame('docs_core_heads', $def->table);
    }

    // ── Rounding mode selects (#63) ──────────────────────────────────────────

    /** Form s reálnými jsonc cfgItems zaokrouhlení (ostatní cfgItem → null). */
    private function createFormWithRoundingCfg(): DocsHeadsForm
    {
        $root = dirname(__DIR__, 5);
        $items = [
            'docs.core.roundingModes'    => JsoncParser::parseFile($root . '/modules/docs/core/config/roundingModes.jsonc'),
            'docs.core.vatRoundingModes' => JsoncParser::parseFile($root . '/modules/docs/core/config/vatRoundingModes.jsonc'),
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $items[$id] ?? null,
        );
        $form = $this->createForm();
        $form->setConfig($config);
        return $form;
    }

    /** @return list<int> */
    private function optionValues(FormElement $el): array
    {
        $values = array_map(static fn (array $o): int => (int) $o['value'], $el->options ?? []);
        sort($values);
        return $values;
    }

    public function testTotalRoundingModeOptionsHaveNoModeTwoAndOfferFiveCents(): void
    {
        $def = $this->createFormWithRoundingCfg()->buildFormDefinition(['vat_mode' => 1], true);
        $el = $this->findElement($def, 'basic', 'total_rounding_mode');
        $this->assertNotNull($el);

        $values = $this->optionValues($el);
        $this->assertNotContains(2, $values, 'mode 2 merged into 0 (#63)');
        $this->assertContains(5, $values, 'mode 5 = round to 0.05');
        $this->assertSame([0, 1, 3, 4, 5], $values);
    }

    public function testVatRoundingModeOptionsAreOnlyCentsAndUnit(): void
    {
        $def = $this->createFormWithRoundingCfg()->buildFormDefinition(['vat_mode' => 1], true);
        $el = $this->findElement($def, 'basic', 'vat_rounding_mode');
        $this->assertNotNull($el);

        $this->assertSame([0, 1], $this->optionValues($el));
    }

    public function testNewDocumentDefaultsRoundingModes(): void
    {
        // #73 D3: doklad bez automatiky má mód Na haléře (dřívější 1 se v UI
        // přes schéma default 0 stejně neuplatnilo), DPH 0 (#63/3).
        $form = new TestableDocsHeadsForm('docs_core_heads');
        $data = [];
        $form->applyClientDefaultsPub($data, true);

        $this->assertSame(0, $data['total_rounding_mode']);
        $this->assertSame(0, $data['vat_rounding_mode']);
        $this->assertSame(0, $data['total_rounding_auto'], 'bez typu / konfigurace automatika vypnutá');
    }

    // ── Automatické zaokrouhlení podle způsobu úhrady (#73) ──────────────────

    /**
     * Form s reálnými cfgItems pro automatiku, zemí zdroje dat (null = bez
     * země) a prázdnou DB (žádná výchozí pokladna, žádné registrace).
     */
    private function createFormWithAutoCfg(?string $country = 'cz', ?DocsHeadsForm $form = null): DocsHeadsForm
    {
        $root = dirname(__DIR__, 5);
        $items = [
            'docs.core.docTypes'         => JsoncParser::parseFile($root . '/modules/docs/core/config/docTypes.jsonc'),
            'docs.core.paymentMethods'   => JsoncParser::parseFile($root . '/modules/docs/core/config/paymentMethods.jsonc'),
            'docs.core.roundingModes'    => JsoncParser::parseFile($root . '/modules/docs/core/config/roundingModes.jsonc'),
            'docs.core.vatRoundingModes' => JsoncParser::parseFile($root . '/modules/docs/core/config/vatRoundingModes.jsonc'),
            'world.base.cashRounding'    => JsoncParser::parseFile($root . '/modules/world/base/config/cashRounding.jsonc'),
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $items[$id] ?? null);
        $form ??= $this->createForm();
        $form->setConfig($config);
        if ($country !== null) {
            $ds = $this->createMock(DataSourceConfig::class);
            $ds->method('getCountry')->willReturn($country);
            $form->setDsConfig($ds);
        }
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);
        $db->method('fetchRow')->willReturn(null);
        $form->setDb($db);
        return $form;
    }

    public function testAutoCheckboxShowsForSalesTypesAndLocksModeSelect(): void
    {
        $form = $this->createFormWithAutoCfg();

        $def = $form->buildFormDefinition(['doc_type' => 'invno', 'total_rounding_auto' => 1, 'vat_mode' => 1], true);
        $checkbox = $this->findElement($def, 'basic', 'total_rounding_auto');
        $this->assertNotNull($checkbox);
        $this->assertFalse($checkbox->hidden);
        $this->assertSame('reload', $checkbox->triggers);
        $this->assertTrue($this->findElement($def, 'basic', 'total_rounding_mode')->readOnly, 'zapnutá automatika → mód jen ke čtení');

        $def = $form->buildFormDefinition(['doc_type' => 'invno', 'total_rounding_auto' => 0, 'vat_mode' => 1], true);
        $this->assertFalse($this->findElement($def, 'basic', 'total_rounding_mode')->readOnly, 'vypnutá → jde upravit');

        $def = $form->buildFormDefinition(['doc_type' => 'invni', 'total_rounding_auto' => 1, 'vat_mode' => 1], true);
        $this->assertTrue($this->findElement($def, 'basic', 'total_rounding_auto')->hidden, 'přijatá faktura automatiku nemá');
        $this->assertFalse($this->findElement($def, 'basic', 'total_rounding_mode')->readOnly);

        $def = $this->createForm()->buildFormDefinition(['total_rounding_auto' => 1], true);
        $this->assertTrue($this->findElement($def, 'basic', 'total_rounding_auto')->hidden, 'bez konfigurace a typu skryté');
    }

    public function testRecalculateKeepsModeInSyncWithAutomation(): void
    {
        $form = $this->createFormWithAutoCfg();
        $base = [
            'doc_type' => 'invno', 'doc_currency' => 'czk', 'home_currency' => 'czk', 'vat_mode' => 1,
            'payment_method' => 0, 'total_rounding_auto' => 1, 'total_rounding_mode' => 0,
        ];

        $result = $form->recalculate('payment_method', $base);
        $this->assertSame(1, $result->data['total_rounding_mode'], 'hotovost → na koruny');
        $this->assertTrue($this->findElement($result->formDefinition, 'basic', 'total_rounding_mode')->readOnly);

        $result = $form->recalculate('payment_method', ['payment_method' => 2, 'total_rounding_auto' => true, 'total_rounding_mode' => 1] + $base);
        $this->assertSame(0, $result->data['total_rounding_mode'], 'kartou → na haléře (příznak jako bool z klienta)');

        $result = $form->recalculate('payment_method', ['doc_currency' => 'eur', 'total_rounding_mode' => 1] + $base);
        $this->assertSame(0, $result->data['total_rounding_mode'], 'EUR hotově na českém zdroji → centy');

        $result = $form->recalculate('total_rounding_auto', ['total_rounding_auto' => 0, 'total_rounding_mode' => 1] + $base);
        $this->assertSame(1, $result->data['total_rounding_mode'], 'po vypnutí zůstává poslední mód');
        $this->assertFalse($this->findElement($result->formDefinition, 'basic', 'total_rounding_mode')->readOnly);

        $result = $form->recalculate('total_rounding_auto', $base);
        $this->assertSame(1, $result->data['total_rounding_mode'], 'po zapnutí se mód dopočítá');

        $result = $form->recalculate('issue_date', ['issue_date' => '2026-05-06'] + $base);
        $this->assertSame(1, $result->data['total_rounding_mode'], 'běží při každém přepočtu');

        $result = $this->createFormWithAutoCfg(country: null)->recalculate('payment_method', $base);
        $this->assertSame(0, $result->data['total_rounding_mode'], 'bez země zdroje dat se mód nemění');
    }

    public function testClientDefaultsTurnAutomationOnForSalesTypes(): void
    {
        $form = new TestableDocsHeadsForm('docs_core_heads');
        $this->createFormWithAutoCfg(form: $form);

        $data = ['doc_type' => 'invno'];
        $form->applyClientDefaultsPub($data, true);
        $this->assertSame(1, $data['total_rounding_auto']);
        $this->assertSame(0, $data['total_rounding_mode'], 'mód do dat propisuje applyNewRecordDefaults, tady jen renderování');

        $data = ['doc_type' => 'invni'];
        $form->applyClientDefaultsPub($data, true);
        $this->assertSame(0, $data['total_rounding_auto']);
        $this->assertSame(0, $data['total_rounding_mode']);

        $data = ['doc_type' => 'invno', 'total_rounding_auto' => 0, 'total_rounding_mode' => 3];
        $form->applyClientDefaultsPub($data, true);
        $this->assertSame(0, $data['total_rounding_auto'], 'explicitní hodnota vyhrává');
        $this->assertSame(3, $data['total_rounding_mode']);
    }

    // ── VAT mode visibility ──────────────────────────────────────────────────

    public function testVatModeZeroHidesVatFields(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['vat_mode' => 0], true);

        $hiddenWhenNoVat = ['vat_calc_source', 'vat_place', 'vat_registration',
            'vat_duzp', 'vat_dppd', 'vat_rounding_mode'];

        foreach ($hiddenWhenNoVat as $col) {
            $el = $this->findElement($def, 'basic', $col);
            $this->assertNotNull($el, "Column {$col} should exist");
            $this->assertTrue($el->hidden, "Column {$col} should be hidden when vat_mode=0");
        }
    }

    public function testVatModeOneShowsVatFields(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['vat_mode' => 1], true);

        $visibleWhenVat = ['vat_calc_source', 'vat_place', 'vat_registration',
            'vat_duzp', 'vat_dppd', 'vat_rounding_mode'];

        foreach ($visibleWhenVat as $col) {
            $el = $this->findElement($def, 'basic', $col);
            $this->assertNotNull($el);
            $this->assertFalse($el->hidden, "Column {$col} should be visible when vat_mode=1");
        }
    }

    // ── Foreign currency / exchange_rate visibility ──────────────────────────

    public function testHomeCurrencyMatchesDocCurrencyHidesExchangeRate(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([
            'doc_currency'  => 'czk',
            'home_currency' => 'czk',
        ], false);

        $el = $this->findElement($def, 'basic', 'exchange_rate');
        $this->assertNotNull($el);
        $this->assertTrue($el->hidden);
    }

    public function testForeignCurrencyShowsExchangeRate(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([
            'doc_currency'  => 'eur',
            'home_currency' => 'czk',
        ], false);

        $el = $this->findElement($def, 'basic', 'exchange_rate');
        $this->assertNotNull($el);
        $this->assertFalse($el->hidden);
    }

    // ── Identity tab readonly ────────────────────────────────────────────────

    public function testNumberSeriesEditableForNewDocument(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([], true);

        $el = $this->findElement($def, 'basic', 'number_series');
        $this->assertNotNull($el);
        $this->assertFalse($el->readOnly);
    }

    public function testNumberSeriesReadOnlyForExistingDocument(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['id' => 7], false);

        $el = $this->findElement($def, 'basic', 'number_series');
        $this->assertNotNull($el);
        $this->assertTrue($el->readOnly);
    }

    public function testDocNumberAlwaysReadOnly(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([], true);

        $el = $this->findElement($def, 'basic', 'doc_number');
        $this->assertNotNull($el);
        $this->assertTrue($el->readOnly);
    }

    // ── Recalculate (no DB needed for issue_date branch) ─────────────────────

    public function testIssueDateRecalcPropagatesDefaults(): void
    {
        $form = $this->createForm();
        $result = $form->recalculate('issue_date', [
            'issue_date' => '2026-05-07',
        ]);

        $this->assertSame('2026-05-07', $result->data['accounting_date']);
        $this->assertSame('2026-05-07', $result->data['vat_duzp']);
    }

    /** Nový doklad (bez id): Účetní datum a DUZP jdou za Datem vystavení (#24 D7). */
    public function testIssueDateOnNewDocumentOverwritesDerivedDates(): void
    {
        $form = $this->createForm();
        $result = $form->recalculate('issue_date', [
            'issue_date'      => '2026-05-07',
            'accounting_date' => '2026-04-30',
            'vat_duzp'        => '2026-04-30',
        ]);

        $this->assertSame('2026-05-07', $result->data['accounting_date']);
        $this->assertSame('2026-05-07', $result->data['vat_duzp']);
    }

    /** Uložený doklad (s id): doplňují se jen prázdná pole. */
    public function testIssueDateOnSavedDocumentKeepsFilledDates(): void
    {
        $form = $this->createForm();
        $result = $form->recalculate('issue_date', [
            'id'              => 42,
            'issue_date'      => '2026-05-07',
            'accounting_date' => '2026-04-30',
        ]);

        $this->assertSame('2026-04-30', $result->data['accounting_date']);
        $this->assertSame('2026-05-07', $result->data['vat_duzp']);
    }

    // ── Triggers on user-driven reload selects ───────────────────────────────

    public function testCriticalSelectsTriggerReload(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([], true);

        foreach (['partner', 'vat_mode', 'vat_place', 'vat_registration',
                  'doc_currency', 'issue_date', 'accounting_date'] as $col) {
            $el = $this->findElement($def, 'basic', $col);
            $this->assertNotNull($el, "Column {$col} should exist");
            $this->assertSame('reload', $el->triggers,
                "Column {$col} should trigger reload");
        }
    }

    // ── Partner lookups ──────────────────────────────────────────────────────

    public function testPartnerIsLookupAgainstPersons(): void
    {
        $el = $this->findElement(
            $this->createForm()->buildFormDefinition([], true),
            'basic',
            'partner',
        );

        $this->assertNotNull($el);
        $this->assertSame('lookup', $el->type);
        $this->assertSame('base_persons_persons', $el->lookup['table']);
        $this->assertNull($el->lookup['filter']);
        $this->assertSame('reload', $el->triggers);
    }

    public function testPartnerAddressLookupWithoutPartnerIsReadOnly(): void
    {
        $el = $this->findElement(
            $this->createForm()->buildFormDefinition([], true),
            'basic',
            'partner_address',
        );

        $this->assertNotNull($el);
        $this->assertSame('lookup', $el->type);
        $this->assertSame('base_persons_addresses', $el->lookup['table']);
        $this->assertNull($el->lookup['filter']);
        $this->assertTrue($el->readOnly);
    }

    public function testPartnerAddressLookupWithPartnerHasFilter(): void
    {
        $el = $this->findElement(
            $this->createForm()->buildFormDefinition(['partner' => 42], false),
            'basic',
            'partner_address',
        );

        $this->assertNotNull($el);
        $this->assertSame('lookup', $el->type);
        $this->assertSame(['person' => 42], $el->lookup['filter']);
        $this->assertFalse($el->readOnly);
    }

    public function testPartnerBankLookupWithPartnerHasFilter(): void
    {
        $el = $this->findElement(
            $this->createForm()->buildFormDefinition(['partner' => 42], false),
            'basic',
            'partner_bank',
        );

        $this->assertNotNull($el);
        $this->assertSame('lookup', $el->type);
        $this->assertSame('base_persons_bank_accounts', $el->lookup['table']);
        $this->assertSame(['person' => 42], $el->lookup['filter']);
    }

    // ── Subtable in rows tab ─────────────────────────────────────────────────

    public function testRowsTabHoldsSubtable(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([], true);

        $rowsTab = $this->findTab($def, 'rows');
        $this->assertNotNull($rowsTab);
        $this->assertSame('subtable', $rowsTab->type);
        $this->assertSame('docs_core_rows', $rowsTab->subtable['table']);
        $this->assertSame('doc_head', $rowsTab->subtable['foreignKey']);
        $this->assertSame('docs.core.rows', $rowsTab->subtable['formId']);
    }

    // ── Recap tab always present, never html (#150 D82, D86) ─────────────────

    public function testRecapTabOfNewDocumentIsContentElement(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([], true);

        $this->assertNotNull($this->findElementByType($def, 'recap', 'content'));
        $this->assertNull($this->findElementByType($def, 'recap', 'component'));
    }

    /** Žádný tab formuláře dokladu nenese prvek `html` (#150 D82). */
    public function testNoTabCarriesHtmlElement(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([
            'doc_currency' => 'czk', 'home_currency' => 'czk',
            'supplier_snapshot' => ['name' => 'Naše firma s.r.o.'],
            'vatRecap' => [['vat_code' => 'std', 'vat_pct' => 21, 'base' => 1, 'tax' => 0.21, 'total' => 1.21]],
        ], false);

        foreach ($def->tabs as $tab) {
            $this->assertNull($this->findElementByType($def, $tab->id, 'html'), "tab {$tab->id}");
        }
    }

    /** #75: převzatá rekapitulace je editovatelná sub-tabulka, ne přehled. */
    public function testDeclaredRecapTabIsEditableSubtable(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['id' => 1, 'vat_recap_source' => 1], false);

        $tab = $this->findTab($def, 'recap');
        $this->assertNotNull($tab);
        $this->assertSame('subtable', $tab->type);
        $this->assertSame('docs_core_vat_recap', $tab->subtable['table']);
        $this->assertSame('doc_head', $tab->subtable['foreignKey']);
        $this->assertSame('docs.core.vatRecap', $tab->subtable['formId']);
        $this->assertSame('order_pos', $tab->subtable['orderColumn']);
        $this->assertNull($this->findElementByType($def, 'recap', 'content'));
        $this->assertNull($this->findElementByType($def, 'recap', 'component'));
    }

    public function testComputedRecapTabStaysReadOnlyOverview(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['id' => 1, 'vat_recap_source' => 0], false);

        $tab = $this->findTab($def, 'recap');
        $this->assertNotNull($tab);
        $this->assertNotSame('subtable', $tab->type);
        $this->assertNotNull($this->findElementByType($def, 'recap', 'content'));
    }

    public function testRecapSourceSelectIsInVatSection(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['vat_mode' => 1], true);

        $el = $this->findElement($def, 'basic', 'vat_recap_source');
        $this->assertNotNull($el);
        $this->assertFalse($el->hidden);
        $this->assertStringContainsString('po uložení', (string) $el->hint);
    }

    /**
     * Neuložené přepnutí na Převzatou tab ještě nepřepne — sub-tabulka
     * pracuje s tím, co je v DB, a startovní převzatá rekapitulace vzniká
     * kopií přepočítané až při uložení (I3).
     */
    public function testUnsavedSwitchToDeclaredKeepsOverviewTab(): void
    {
        $form = new DocsHeadsForm('docs_core_heads');
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturn(0);   // v DB je pořád přepočítaná
        $db->method('fetchAll')->willReturn([]);
        $form->setDb($db);

        $def = $form->buildFormDefinition(['id' => 1, 'vat_recap_source' => 1], false);

        $tab = $this->findTab($def, 'recap');
        $this->assertNotSame('subtable', $tab->type);
        $this->assertNotNull($this->findElementByType($def, 'recap', 'content'));
    }

    public function testRecapWithoutRowsShowsEmptyState(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([], true);

        // Blok `empty` s textem z katalogu (bez konfigurace anglický fallback).
        $el = $this->findElementByType($def, 'recap', 'content');
        $this->assertNotNull($el);
        $this->assertSame(
            [[
                'type' => 'empty',
                'text' => 'The document has no VAT recap yet — add rows and save the document to recalculate.',
            ]],
            $el->blocks,
        );
    }

    /** Params komponenty `docVatRecap` (#150 D86). */
    private function recapParams(FormDefinition $def): array
    {
        $el = $this->findElementByType($def, 'recap', 'component');
        $this->assertNotNull($el);
        $this->assertSame('docVatRecap', $el->componentName);
        return $el->params;
    }

    public function testRecapWithVatRecapSendsTableParams(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([
            'doc_currency'  => 'czk',
            'home_currency' => 'czk',
            'total_base'    => 1000,
            'total_vat'     => 210,
            'total_amount'  => 1210,
            'vatRecap'      => [
                [
                    'vat_code' => 'std',
                    'vat_pct'  => 21,
                    'base'     => 1000,
                    'tax'      => 210,
                    'total'    => 1210,
                    'sum_base' => 1, 'sum_tax' => 1, 'sum_total' => 1,
                    'is_reverse_pair' => 0,
                ],
            ],
        ], false);

        // Doklad v domácí měně: žádný řádek v domácí měně, žádný kurz.
        $this->assertSame([
            'currency'     => 'CZK',
            'homeCurrency' => null,
            'rows'         => [[
                'label' => 'std',
                'pct'   => '21 %',
                'base'  => '1 000,00',
                'tax'   => '210,00',
                'total' => '1 210,00',
                'sum'   => ['base' => true, 'tax' => true, 'total' => true],
                'dom'   => null,
            ]],
            'totals'       => [
                'base'  => '1 000,00',
                'tax'   => '210,00',
                'total' => '1 210,00',
                'rate'  => null,
                'dom'   => null,
            ],
        ], $this->recapParams($def));
    }

    /** Přenesená povinnost: nesčítané hodnoty nesou `sum: false` (šedě), sazba s předmětem plnění. */
    public function testReverseChargeRowCarriesSumFlagsAndSubject(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([
            'doc_currency'  => 'czk',
            'home_currency' => 'czk',
            'vatRecap'      => [
                [
                    'vat_code' => 'rcv',
                    'vat_pct'  => 21,
                    'reverse_charge_code' => '4a',
                    'base'     => 1000,
                    'tax'      => 210,
                    'total'    => 1210,
                    'sum_base' => 1, 'sum_tax' => 0, 'sum_total' => 0,
                    'is_reverse_pair' => 1,
                ],
                [
                    'vat_code' => 'low',
                    'vat_pct'  => 12.5,
                    'base'     => 100,
                    'tax'      => 12.5,
                    'total'    => 112.5,
                ],
            ],
        ], false);

        $rows = $this->recapParams($def)['rows'];
        $this->assertSame(['base' => true, 'tax' => false, 'total' => false], $rows[0]['sum']);
        $this->assertSame('rcv · subject 4a', $rows[0]['label']);
        $this->assertSame('21 %', $rows[0]['pct']);
        // Řádek bez příznaků se nesčítá; sazba s desetinnou částí bez zaokrouhlení.
        $this->assertSame(['base' => false, 'tax' => false, 'total' => false], $rows[1]['sum']);
        $this->assertSame('low', $rows[1]['label']);
        $this->assertSame('12,5 %', $rows[1]['pct']);
    }

    public function testForeignCurrencyRecapCarriesDomesticRowsAndRate(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([
            'doc_currency'  => 'eur',
            'home_currency' => 'czk',
            'exchange_rate' => 24.5,
            'total_base'        => 100,
            'total_vat'         => 21,
            'total_amount'      => 121,
            'total_base_dom'    => 2450,
            'total_vat_dom'     => 514.50,
            'total_amount_dom'  => 2964.50,
            'vatRecap'      => [
                [
                    'vat_code' => 'std',
                    'vat_pct'  => 21,
                    'base'     => 100,    'tax' => 21,    'total' => 121,
                    'base_dom' => 2450,   'tax_dom' => 514.50, 'total_dom' => 2964.50,
                    'sum_base' => 1, 'sum_tax' => 1, 'sum_total' => 1,
                    'is_reverse_pair' => 0,
                ],
            ],
        ], false);

        $params = $this->recapParams($def);
        $this->assertSame('EUR', $params['currency']);
        $this->assertSame('CZK', $params['homeCurrency']);
        $this->assertSame('100,00', $params['rows'][0]['base']);
        $this->assertSame(
            ['base' => '2 450,00', 'tax' => '514,50', 'total' => '2 964,50'],
            $params['rows'][0]['dom'],
        );
        $this->assertSame([
            'base'  => '100,00',
            'tax'   => '21,00',
            'total' => '121,00',
            'rate'  => '1 EUR = 24,5 CZK',
            'dom'   => ['base' => '2 450,00', 'tax' => '514,50', 'total' => '2 964,50'],
        ], $params['totals']);
    }

    // ── Snapshots tab content (#150 D85) ────────────────────────────────────

    /** Params komponenty `docParties`. */
    private function partiesParams(FormDefinition $def): array
    {
        $el = $this->findElementByType($def, 'snapshots', 'component');
        $this->assertNotNull($el);
        $this->assertSame('docParties', $el->componentName);
        return $el->params;
    }

    public function testSnapshotsTabSendsBothParties(): void
    {
        $supplier = ['name' => 'Naše firma s.r.o.', 'company_id' => '12345678', 'tax_id' => 'CZ12345678'];
        $customer = ['name' => 'Zákazník a.s.', 'company_id' => '87654321'];
        $form = $this->createForm();
        $def = $form->buildFormDefinition(['supplier_snapshot' => $supplier, 'customer_snapshot' => $customer], false);

        // Zmrazené snapshoty beze změny tvaru — kreslí je PartyCard jako detail.
        $this->assertSame(['supplier' => $supplier, 'customer' => $customer], $this->partiesParams($def));
    }

    public function testSnapshotJsonStringIsDecodedAndMissingPartyIsNull(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([
            'supplier_snapshot' => json_encode(['name' => 'JSON Supplier']),
        ], false);

        $this->assertSame(
            ['supplier' => ['name' => 'JSON Supplier'], 'customer' => null],
            $this->partiesParams($def),
        );
    }

    /** Text jde ven jako data, ne jako HTML — neescapuje se, frontend ho vkládá jako text. */
    public function testSnapshotTextIsNotHtmlEscaped(): void
    {
        $form = $this->createForm();
        $def = $form->buildFormDefinition([
            'supplier_snapshot' => ['name' => '<script>x</script>', 'person_id' => 5],
        ], false);

        $params = $this->partiesParams($def);
        $this->assertSame('<script>x</script>', $params['supplier']['name']);
        $this->assertArrayNotHasKey('person_id', $params['supplier'], 'jméno ve formuláři není odkaz');
    }

    // ── Neplátce DPH — economy.vatAgenda (vat-payer-01, ds-setup D10) ────────

    /**
     * @param list<string>|null $queries zachytávané SQL dotazy fetchAll
     */
    private function createFormWithVatAgenda(?bool $vatAgenda, ?array &$queries = null): TestableDocsHeadsForm
    {
        return $this->createFormWithSettings(['economy.vatAgenda' => $vatAgenda], $queries);
    }

    /**
     * @param array<string, mixed> $settings hodnoty settings klíčů (null = nerozhodnutý klíč)
     * @param list<string>|null $queries zachytávané SQL dotazy fetchAll
     */
    private function createFormWithSettings(array $settings, ?array &$queries = null): TestableDocsHeadsForm
    {
        $form = new TestableDocsHeadsForm('docs_core_heads');
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturnCallback(
            static function (mixed ...$args) use ($settings): mixed {
                $key = $args[1] ?? null;
                if (is_string($key) && array_key_exists($key, $settings) && $settings[$key] !== null) {
                    return json_encode($settings[$key]);
                }
                return null;
            },
        );
        $db->method('fetchAll')->willReturnCallback(
            static function (mixed ...$args) use (&$queries): array {
                if ($queries !== null) {
                    $queries[] = (string) $args[0];
                }
                return [];
            },
        );
        $form->setDb($db);
        return $form;
    }

    private function findSeparator(FormDefinition $def, string $tabId, string $label): ?FormElement
    {
        $tab = $this->findTab($def, $tabId);
        if ($tab === null) {
            return null;
        }
        foreach ($tab->sections as $section) {
            foreach ($section->columns as $col) {
                foreach ($col->elements as $el) {
                    if ($el->type === 'separator' && $el->label === $label) {
                        return $el;
                    }
                }
            }
        }
        return null;
    }

    public function testNonPayerRewritesSchemaDefaultVatMode(): void
    {
        $form = $this->createFormWithVatAgenda(false);
        $data = ['vat_mode' => 1]; // column default ze schématu (FormController prefill)

        $form->applyNewRecordDefaults($data);

        $this->assertSame(0, $data['vat_mode']);
    }

    public function testPayerKeepsSchemaDefaultVatMode(): void
    {
        $form = $this->createFormWithVatAgenda(true);
        $data = ['vat_mode' => 1];

        $form->applyNewRecordDefaults($data);

        $this->assertSame(1, $data['vat_mode']);
    }

    public function testUndecidedVatAgendaKeepsSchemaDefaultVatMode(): void
    {
        // Nerozhodnutý klíč (null) se nesmí chovat jako false.
        $form = $this->createFormWithVatAgenda(null);
        $data = ['vat_mode' => 1];

        $form->applyNewRecordDefaults($data);

        $this->assertSame(1, $data['vat_mode']);
    }

    public function testExplicitNonDefaultVatModeSurvivesNonPayerDefaults(): void
    {
        $form = $this->createFormWithVatAgenda(false);
        $data = ['vat_mode' => 2];

        $form->applyNewRecordDefaults($data);

        $this->assertSame(2, $data['vat_mode']);
    }

    public function testNonPayerNewDocumentBuildDerivesNoVat(): void
    {
        // Build cesta bez prefillu (applyClientDefaults) odvozuje default z klíče.
        $form = $this->createFormWithVatAgenda(false);
        $def = $form->buildFormDefinition([], true);

        $this->assertTrue($this->findSeparator($def, 'basic', 'DPH')->hidden);
        $this->assertTrue($this->findElement($def, 'basic', 'vat_mode')->hidden);
    }

    public function testVatSectionHiddenForNonPayerNoVatDocument(): void
    {
        $form = $this->createFormWithVatAgenda(false);
        $def = $form->buildFormDefinition(['vat_mode' => 0], false);

        $this->assertTrue($this->findSeparator($def, 'basic', 'DPH')->hidden);
        $this->assertTrue($this->findElement($def, 'basic', 'vat_mode')->hidden);
    }

    public function testVatSectionVisibleForHistoricalVatDocumentOfNonPayer(): void
    {
        // D10: doklad z doby plátcovství ukazuje svá DPH data, i když je
        // firma dnes neplátcem — build cesta hodnotu v datech nepřepisuje.
        $form = $this->createFormWithVatAgenda(false);
        $def = $form->buildFormDefinition(['vat_mode' => 1], false);

        $this->assertFalse($this->findSeparator($def, 'basic', 'DPH')->hidden);
        $this->assertFalse($this->findElement($def, 'basic', 'vat_mode')->hidden);
        $this->assertFalse($this->findElement($def, 'basic', 'vat_registration')->hidden);
    }

    public function testVatSectionVisibleWhenUndecided(): void
    {
        $form = $this->createFormWithVatAgenda(null);
        $def = $form->buildFormDefinition(['vat_mode' => 0], false);

        $this->assertFalse($this->findSeparator($def, 'basic', 'DPH')->hidden);
        $this->assertFalse($this->findElement($def, 'basic', 'vat_mode')->hidden);
    }

    public function testHiddenVatSectionSkipsRegistrationQuery(): void
    {
        $queries = [];
        $form = $this->createFormWithVatAgenda(false, $queries);

        $form->buildFormDefinition(['vat_mode' => 0], false);

        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('vat_registrations', $sql);
        }
    }

    // ── Domácí měna — economy.homeCurrency (ds-setup Task 04) ────────────────

    public function testDecidedHomeCurrencyFillsBothCurrenciesOnNewDocument(): void
    {
        $form = $this->createFormWithSettings(['economy.homeCurrency' => 'eur']);
        $data = [];

        $form->applyClientDefaultsPub($data, true);

        $this->assertSame('eur', $data['home_currency']);
        $this->assertSame('eur', $data['doc_currency']);
    }

    public function testUndecidedHomeCurrencyDefaultsToCzk(): void
    {
        // Nerozhodnutý klíč (null) = dnešní chování.
        $form = $this->createFormWithSettings(['economy.homeCurrency' => null]);
        $data = [];

        $form->applyClientDefaultsPub($data, true);

        $this->assertSame('czk', $data['home_currency']);
        $this->assertSame('czk', $data['doc_currency']);
    }

    public function testExplicitDocCurrencySurvivesHomeCurrencyDefault(): void
    {
        // Explicitní doc_currency (import, kopie dokladu) se nepřebíjí.
        $form = $this->createFormWithSettings(['economy.homeCurrency' => 'eur']);
        $data = ['doc_currency' => 'usd'];

        $form->applyClientDefaultsPub($data, true);

        $this->assertSame('eur', $data['home_currency']);
        $this->assertSame('usd', $data['doc_currency']);
    }
}
