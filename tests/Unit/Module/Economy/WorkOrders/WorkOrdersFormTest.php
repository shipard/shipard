<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\WorkOrders\WorkOrdersForm;
use Shipard\Module\Economy\WorkOrders\WorkOrderTypes;

/**
 * Formulář zakázky: pole podle typu z řady (zákazník, měna, VS jen externí;
 * nadřazená jen jednorázová; fakturační předpis jen periodická), řada jen
 * u konceptu, defaulty z tabu řady, vyprázdnění skrytých polí při změně
 * řady, placeholdery „Z druhu“, tab Řádky a Přílohy (P6).
 */
class WorkOrdersFormTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../../../modules/economy/workOrders';

    private const TYPES = [
        'periodic' => ['name' => 'Periodická', 'external' => true, 'oneOff' => false, 'invoicing' => 'periodic'],
        'project'  => ['name' => 'Externí jednorázová', 'external' => true, 'oneOff' => true, 'invoicing' => null],
        'overhead' => ['name' => 'Interní průběžná', 'external' => false, 'oneOff' => false, 'invoicing' => null],
    ];

    public const SERIES = [
        1 => ['id' => 1, 'kind' => 11, 'type' => 'project', 'kind_name' => 'Projekty'],
        2 => ['id' => 2, 'kind' => 12, 'type' => 'overhead', 'kind_name' => 'Režie'],
        3 => ['id' => 3, 'kind' => 13, 'type' => 'periodic', 'kind_name' => 'Smlouvy'],
    ];

    public const KINDS = [
        13 => [
            'id' => 13, 'type' => 'periodic', 'inv_doc_type' => 'invno', 'inv_number_series' => 5,
            'inv_due_days' => 14, 'inv_timing' => 'start', 'inv_vat_mode' => 1, 'inv_payment_method' => 1, 'inv_bank_account' => 3,
        ],
    ];

    private function form(): WorkOrdersForm
    {
        $form = new class('economy_work_orders_heads') extends WorkOrdersForm {
            protected function seriesInfo(int $seriesId): ?array
            {
                return WorkOrdersFormTest::SERIES[$seriesId] ?? null;
            }

            protected function loadKind(int $kindId): ?array
            {
                return WorkOrdersFormTest::KINDS[$kindId] ?? null;
            }

            protected function homeCurrency(): string
            {
                return 'czk';
            }
        };
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(static function (string $sql): array {
            if (str_contains($sql, 'docs_core_number_series')) {
                return [['id' => 5, 'name' => 'FV 2026'], ['id' => 7, 'name' => 'FV pobočka']];
            }
            if (str_contains($sql, 'economy_codebooks_bank_accounts')) {
                return [['id' => 3, 'code' => 'HLAVNI', 'name' => 'Provozní účet', 'currency' => 'czk']];
            }
            if (str_contains($sql, 'core_units')) {
                return [['id' => 1, 'name' => 'měsíc', 'shortcut' => 'měs']];
            }
            return [
                ['id' => 1, 'name' => 'Projekty 2026', 'docState' => 40, 'kind_name' => 'Projekty'],
                ['id' => 2, 'name' => 'Režie', 'docState' => 40, 'kind_name' => 'Režie'],
            ];
        });
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            [WorkOrderTypes::CFG_ITEM, self::TYPES],
            [WorkOrdersForm::CURRENCIES_CFG_ITEM, ['czk' => ['name' => 'Česká koruna', 'alpha3' => 'CZK'], 'eur' => ['name' => 'Euro', 'alpha3' => 'EUR']]],
            ['docs.core.docTypes', ['invno' => ['name' => 'Faktura vydaná'], 'invpo' => ['name' => 'Zálohová faktura vydaná'], 'invni' => ['name' => 'Faktura přijatá']]],
            ['economy.workOrders.periodicities', ['month' => ['name' => 'Měsíčně'], 'quarter' => ['name' => 'Čtvrtletně']]],
            ['economy.workOrders.invoiceTimings', ['start' => ['name' => 'Na počátku období'], 'end' => ['name' => 'Na konci období']]],
            ['docs.core.vatModes', ['0' => ['name' => 'Bez DPH'], '1' => ['name' => 'Ze základu'], '2' => ['name' => 'Z ceny celkem']]],
            ['docs.core.paymentMethods', ['0' => ['name' => 'Hotovost'], '1' => ['name' => 'Převodem']]],
        ]);
        $form->setDb($db);
        $form->setConfig($config);
        $form->setTableDef(TableDefinition::fromArray(JsoncParser::parseFile(self::MODULE . '/tables/economy_work_orders_heads.jsonc')));
        $form->setTables([
            'economy_work_orders_rows' => TableDefinition::fromArray(JsoncParser::parseFile(self::MODULE . '/tables/economy_work_orders_rows.jsonc')),
        ]);
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

    public function testNewWorkOrderFromSeriesTabGetsKindTypeAndHomeCurrency(): void
    {
        $form = $this->form();
        $data = ['number_series' => 1];
        $form->applyNewRecordDefaults($data);

        $this->assertSame(11, $data['kind']);
        $this->assertSame('project', $data['type']);
        $this->assertSame('czk', $data['currency']);

        $def = $form->buildFormDefinition($data, true);
        $this->assertSame(['basic', 'attachments'], array_map(static fn($t) => $t->id, $def->tabs));
        $this->assertSame('attachments', $def->tabs[1]->type);
        $series = $this->element($def, 'number_series');
        $this->assertFalse($series->readOnly);
        $this->assertSame('reload', $series->triggers);
        $this->assertSame(['Projekty 2026 — Projekty', 'Režie — Režie'], array_column($series->options, 'label'));
        $this->assertFalse($this->element($def, 'customer')->hidden);
        $this->assertFalse($this->element($def, 'currency')->hidden);
        $this->assertSame(['czk', 'eur'], array_column($this->element($def, 'currency')->options, 'value'));
        $this->assertFalse($this->element($def, 'parent')->hidden);
        $this->assertSame(['table' => 'economy_work_orders_heads', 'filter' => ['role' => 'parent']], $this->element($def, 'parent')->lookup);
        $this->assertTrue($this->element($def, 'number')->readOnly);
        $this->assertTrue($this->element($def, 'type')->readOnly);
        $this->assertTrue($this->element($def, 'inv_periodicity')->hidden);
        $this->assertTrue($this->element($def, 'inv_doc_type')->hidden);
    }

    public function testInternalOngoingHidesPartyAndParent(): void
    {
        $def = $this->form()->buildFormDefinition(['number_series' => 2, 'type' => 'overhead'], true);

        $this->assertTrue($this->element($def, 'customer')->hidden);
        $this->assertTrue($this->element($def, 'currency')->hidden);
        $this->assertTrue($this->element($def, 'payment_reference')->hidden);
        $this->assertTrue($this->element($def, 'parent')->hidden);
        $this->assertFalse($this->element($def, 'cost_center')->hidden);
    }

    public function testWithoutSeriesOnlyGenericFieldsShowAndTypeIsHidden(): void
    {
        $def = $this->form()->buildFormDefinition([], true);

        $this->assertTrue($this->element($def, 'type')->hidden);
        $this->assertTrue($this->element($def, 'customer')->hidden);
        $this->assertTrue($this->element($def, 'parent')->hidden);
        $this->assertFalse($this->element($def, 'title')->hidden);
    }

    public function testConfirmedWorkOrderKeepsSeriesReadOnly(): void
    {
        $def = $this->form()->buildFormDefinition(['id' => 5, 'number_series' => 1, 'type' => 'project', 'docState' => 80], false);
        $this->assertTrue($this->element($def, 'number_series')->readOnly);
        $this->assertSame('Zakázka', $def->title);
    }

    public function testChangingSeriesClearsFieldsTheNewTypeDoesNotHave(): void
    {
        $form = $this->form();
        $result = $form->recalculate('number_series', [
            'number_series' => 2, 'customer' => 50, 'currency' => 'eur', 'payment_reference' => '42', 'parent' => 7,
            'inv_periodicity' => 'month', 'inv_from' => '2026-08-01', 'inv_doc_type' => 'invno', 'inv_due_days' => 14,
        ]);

        $this->assertSame(12, $result->data['kind']);
        $this->assertSame('overhead', $result->data['type']);
        $this->assertNull($result->data['customer']);
        $this->assertNull($result->data['currency']);
        $this->assertNull($result->data['payment_reference']);
        $this->assertNull($result->data['parent']);
        $this->assertNull($result->data['inv_periodicity']);
        $this->assertNull($result->data['inv_from']);
        $this->assertNull($result->data['inv_doc_type']);
        $this->assertNull($result->data['inv_due_days']);

        // Periodická: strana ano, nadřazená ne, předpis zůstává.
        $result = $form->recalculate('number_series', ['number_series' => 3, 'customer' => 50, 'parent' => 7, 'inv_periodicity' => 'month']);
        $this->assertSame(50, $result->data['customer']);
        $this->assertNull($result->data['parent']);
        $this->assertSame('czk', $result->data['currency']);
        $this->assertSame('month', $result->data['inv_periodicity']);
    }

    // --- fakturační předpis (fáze 2, D3) ----------------------------------------

    public function testPeriodicShowsInvoicingSectionRowsTabAndKindPlaceholders(): void
    {
        $form = $this->form();
        $def = $form->buildFormDefinition(['id' => 9, 'number_series' => 3, 'kind' => 13, 'type' => 'periodic', 'docState' => 10], false);

        $this->assertSame(['basic', 'rows', 'attachments'], array_map(static fn($t) => $t->id, $def->tabs));
        $rows = $def->tabs[1];
        $this->assertSame('subtable', $rows->type);
        $this->assertSame('economy_work_orders_rows', $rows->subtable['table']);
        $this->assertSame('work_order', $rows->subtable['foreignKey']);
        $this->assertSame('economy.workOrders.rows', $rows->subtable['formId']);
        $this->assertSame('order_pos', $rows->subtable['orderColumn']);
        // Řádky se řídí stavem zakázky (tasks/work-orders-rows-readonly.md) —
        // bez independentRows sub-tabulka převezme read-only rodiče.
        $this->assertFalse($rows->subtable['independentRows']);
        $this->assertArrayNotHasKey('independent_rows', $rows->toArray()['subtable']);

        $this->assertFalse($this->element($def, 'inv_periodicity')->hidden);
        $this->assertSame(['month', 'quarter'], array_column($this->element($def, 'inv_periodicity')->options, 'value'));
        $this->assertFalse($this->element($def, 'inv_from')->hidden);
        $this->assertFalse($this->element($def, 'inv_doc_text')->hidden);

        $docType = $this->element($def, 'inv_doc_type');
        $this->assertSame(['invno', 'invpo'], array_column($docType->options, 'value'));
        $this->assertSame('Z druhu: Faktura vydaná', $docType->placeholder);
        $this->assertSame('reload', $docType->triggers);
        $this->assertFalse($docType->required);

        $series = $this->element($def, 'inv_number_series');
        $this->assertSame([5, 7], array_column($series->options, 'value'));
        $this->assertSame('Z druhu: FV 2026', $series->placeholder);
        $this->assertSame('Z druhu: 14 dní', $this->element($def, 'inv_due_days')->hint);
        $this->assertSame('Z druhu: Na počátku období', $this->element($def, 'inv_timing')->placeholder);
        $this->assertSame('Z druhu: Ze základu', $this->element($def, 'inv_vat_mode')->placeholder);
        $this->assertSame('Z druhu: Převodem', $this->element($def, 'inv_payment_method')->placeholder);
        $this->assertSame('Z druhu: HLAVNI — Provozní účet (CZK)', $this->element($def, 'inv_bank_account')->placeholder);
    }

    public function testPeriodicWithoutKindDefaultsHasNoPlaceholdersAndNoSeriesUntilDocType(): void
    {
        $form = $this->form();
        $def = $form->buildFormDefinition(['number_series' => 3, 'kind' => 99, 'type' => 'periodic'], true);

        $this->assertNull($this->element($def, 'inv_doc_type')->placeholder);
        $this->assertSame([], $this->element($def, 'inv_number_series')->options);
        $this->assertSame('Nejdřív vyber typ dokladu — tady nebo na druhu.', $this->element($def, 'inv_number_series')->hint);
        $this->assertSame('Prázdné = 14 dní.', $this->element($def, 'inv_due_days')->hint);

        $def = $form->buildFormDefinition(['number_series' => 3, 'kind' => 99, 'type' => 'periodic', 'inv_doc_type' => 'invpo'], true);
        $this->assertSame([5, 7], array_column($this->element($def, 'inv_number_series')->options, 'value'));
    }

    public function testChangingDocTypeClearsSeriesOverride(): void
    {
        $result = $this->form()->recalculate('inv_doc_type', [
            'number_series' => 3, 'kind' => 13, 'type' => 'periodic', 'inv_doc_type' => 'invpo', 'inv_number_series' => 5,
        ]);
        $this->assertNull($result->data['inv_number_series']);
        $this->assertSame('invpo', $result->data['inv_doc_type']);
    }

    public function testRowsSubtableRendersPrescriptionColumns(): void
    {
        $form = $this->form();
        $def = $form->buildFormDefinition(['id' => 9, 'number_series' => 3, 'kind' => 13, 'type' => 'periodic'], false);
        $rendered = $form->renderSubtable($def->tabs[1], [
            ['id' => 1, 'description' => 'Nájem', 'quantity' => '1.0000', 'unit' => 1, 'unit_price' => '12000.0000', 'vat_code' => 'out21', 'valid_from' => '2026-08-01', 'valid_to' => null],
        ], ['id' => 9]);

        $this->assertSame(
            ['description', 'quantity', 'unit', 'unit_price', 'vat_code', 'valid_from', 'valid_to'],
            array_column($rendered['columns'], 'id'),
        );
        $this->assertSame('order_pos', $rendered['order_column']);
        $cells = $rendered['rows'][0]['cells'];
        $this->assertSame('Nájem', $cells['description']);
        $this->assertSame('1', $cells['quantity']);
        $this->assertSame('měs', $cells['unit']);
        $this->assertSame('12 000,00', $cells['unit_price']);
        $this->assertSame('out21', $cells['vat_code']);
        $this->assertSame('01.08.2026', $cells['valid_from']);
        $this->assertSame('', $cells['valid_to']);
    }
}
