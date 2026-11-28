<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Facility\Buildings;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Core\Viewer\ViewerRegistry;
use Shipard\Module\Economy\PeriodicInvoicing\PeriodRepository;
use Shipard\Module\Economy\PeriodicInvoicing\Rows\AbstractPrescriptionRowsForm;
use Shipard\Module\Economy\PeriodicInvoicing\Source\PeriodicInvoicingSource;
use Shipard\Module\Facility\Buildings\ContractDocument;
use Shipard\Module\Facility\Buildings\ContractInvoicingSource;
use Shipard\Module\Facility\Buildings\ContractKindRowsForm;
use Shipard\Module\Facility\Buildings\ContractKindsForm;
use Shipard\Module\Facility\Buildings\ContractRowsForm;
use Shipard\Module\Facility\Buildings\ContractsForm;
use Shipard\Module\Facility\Buildings\ContractsViewer;

/** Viewer smluv s evidencí období a druhem z polí. */
final class InvoicingContractsViewer extends ContractsViewer
{
    /** @var array<string, mixed>|null druh smlouvy (inv_*) */
    public ?array $kind = null;
    /** @var list<array<string, mixed>> období (nejnovější nahoře) */
    public array $periods = [];
    /** @var array<int, array<string, mixed>> doklady období podle id */
    public array $docs = [];
    public string $today = '2027-03-08';

    protected function periodRepository(): PeriodRepository
    {
        $viewer = $this;
        return new class($viewer) extends PeriodRepository {
            public function __construct(private readonly InvoicingContractsViewer $viewer)
            {
            }

            public function listFor(int $recordId): array
            {
                return $this->viewer->periods;
            }

            public function docInfo(array $docIds): array
            {
                return array_intersect_key($this->viewer->docs, array_flip(array_map('intval', $docIds)));
            }
        };
    }

    protected function invoicingSource(): PeriodicInvoicingSource
    {
        $viewer = $this;
        return new class($viewer) extends ContractInvoicingSource {
            public function __construct(private readonly InvoicingContractsViewer $viewer)
            {
            }

            public function kind(array $record): ?array
            {
                return $this->viewer->kind;
            }
        };
    }

    protected function today(): string
    {
        return $this->today;
    }
}

/**
 * Fakturace smluv v UI (#174 D29, D30, D32, D34, D37): formulář smlouvy
 * (sekce Fakturace, přepisy se zástupným textem z druhu, záložka Řádky),
 * formulář druhu (výchozí hodnoty, záložka Šablona řádků), sub-formuláře
 * řádků nad společným základem a záložka Fakturace v detailu smlouvy
 * s periodicitou.
 */
final class ContractInvoicingUiTest extends TestCase
{
    use BuildingsConfig;

    private const KINDS = [
        ['id' => 1, 'name' => 'Kancelář', 'type' => 'office'],
        ['id' => 2, 'name' => 'Ostatní', 'type' => 'other'],
    ];

    private const KIND_ROWS = [
        1 => ['id' => 1, 'inv_doc_type' => 'invno', 'inv_number_series' => 5, 'inv_due_days' => 21, 'inv_timing' => 'end'],
        2 => ['id' => 2],
    ];

    private static function tableDef(string $table): TableDefinition
    {
        return TableDefinition::fromArray(self::czech('facility/buildings/tables/' . $table . '.jsonc'));
    }

    private function config(): ConfigRuntime
    {
        return $this->buildingsConfig([
            'facility.buildings.formTexts' => self::czech('facility/buildings/config/formTexts.jsonc'),
            'facility.buildings.resetScopes' => self::czech('facility/buildings/config/resetScopes.jsonc'),
            'economy.periodicInvoicing.periodicities' => ['month' => ['name' => 'Měsíčně'], 'quarter' => ['name' => 'Čtvrtletně']],
            'economy.periodicInvoicing.invoiceTimings' => ['start' => ['name' => 'Na počátku období'], 'end' => ['name' => 'Na konci období']],
            'docs.core.docTypes' => ['invno' => ['name' => 'Faktura vydaná'], 'invpo' => ['name' => 'Zálohová faktura vydaná']],
            'world.base.currencies' => ['czk' => ['name' => 'Kč'], 'eur' => ['name' => 'Euro']],
        ]);
    }

    private function db(): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(static function (string $sql): array {
            if (str_contains($sql, 'docs_core_number_series')) {
                return [['id' => 5, 'name' => 'FV 2027'], ['id' => 7, 'name' => 'FV pobočka']];
            }
            if (str_contains($sql, 'economy_codebooks_bank_accounts')) {
                return [['id' => 3, 'code' => 'HLAVNI', 'name' => 'Provozní účet', 'currency' => 'czk']];
            }
            if (str_contains($sql, 'core_units')) {
                return [['id' => 1, 'name' => 'měsíc', 'shortcut' => 'měs']];
            }
            return [];
        });
        return $db;
    }

    private function contractsForm(): ContractsForm
    {
        $form = new class('facility_buildings_contracts') extends ContractsForm {
            protected function loadKinds(int $currentId): array
            {
                return ContractInvoicingUiTest::kinds();
            }

            protected function loadKindRow(int $kindId): ?array
            {
                return ContractInvoicingUiTest::kindRow($kindId);
            }
        };
        $form->setConfig($this->config());
        $form->setDb($this->db());
        $form->setLanguage('cs');
        $form->setTableDef(self::tableDef('facility_buildings_contracts'));
        $form->setTables([
            'facility_buildings_contract_places' => self::tableDef('facility_buildings_contract_places'),
            'facility_buildings_contract_rows' => self::tableDef('facility_buildings_contract_rows'),
        ]);
        return $form;
    }

    private function kindsForm(): ContractKindsForm
    {
        $form = new class('facility_buildings_contract_kinds') extends ContractKindsForm {
            protected function isKindUsed(int $kindId): bool
            {
                return false;
            }
        };
        $form->setConfig($this->config());
        $form->setDb($this->db());
        $form->setLanguage('cs');
        $form->setTableDef(self::tableDef('facility_buildings_contract_kinds'));
        $form->setTables([
            'facility_buildings_contract_kind_rows' => self::tableDef('facility_buildings_contract_kind_rows'),
        ]);
        return $form;
    }

    /** @return list<array<string, mixed>> */
    public static function kinds(): array
    {
        return self::KINDS;
    }

    /** @return array<string, mixed>|null */
    public static function kindRow(int $kindId): ?array
    {
        return self::KIND_ROWS[$kindId] ?? null;
    }

    private function element(FormDefinition $def, string $column): FormElement
    {
        foreach ($def->tabs[0]->sections[0]->columns[0]->elements as $element) {
            if ($element->column === $column) {
                return $element;
            }
        }
        $this->fail("Element {$column} not found");
    }

    // ── Formulář smlouvy ────────────────────────────────────────────────────

    public function testContractHasInvoicingFieldsAndRowsTabBeforeAttachments(): void
    {
        $def = $this->contractsForm()->buildFormDefinition(['id' => 9, 'kind' => 2, 'docState' => 10], false);

        $this->assertSame(['basic', 'rows', 'attachments'], array_map(static fn ($tab) => $tab->id, $def->tabs));
        $this->assertSame('subtable', $def->tabs[1]->type);
        $this->assertSame('Řádky', $def->tabs[1]->label);

        $this->assertSame(['month', 'quarter'], array_column($this->element($def, 'inv_periodicity')->options, 'value'));
        $this->assertFalse($this->element($def, 'inv_periodicity')->required, 'periodicita je nepovinná (D36)');
        $this->assertSame('Kalendářní období; prázdné = smlouva se nefakturuje.', $this->element($def, 'inv_periodicity')->hint);
        $this->assertSame(['czk', 'eur'], array_column($this->element($def, 'currency')->options, 'value'));
        $this->assertSame('Prázdné = název smlouvy a období', $this->element($def, 'inv_doc_text')->placeholder);
        $this->assertStringContainsString('{období}', (string) $this->element($def, 'inv_doc_text')->hint);
        foreach (['inv_from', 'payment_reference', 'cost_center', 'inv_vat_mode', 'inv_payment_method', 'inv_bank_account'] as $column) {
            $this->assertFalse($this->element($def, $column)->hidden, $column);
        }
    }

    public function testEmptyOverridesShowTheValueOfTheKind(): void
    {
        $def = $this->contractsForm()->buildFormDefinition(['id' => 9, 'kind' => 1, 'docState' => 10], false);

        $this->assertSame('Z druhu: Faktura vydaná', $this->element($def, 'inv_doc_type')->placeholder);
        $this->assertSame('Z druhu: FV 2027', $this->element($def, 'inv_number_series')->placeholder);
        $this->assertSame([5, 7], array_column($this->element($def, 'inv_number_series')->options, 'value'), 'řady typu dokladu z druhu');
        $this->assertNull($this->element($def, 'inv_number_series')->hint);
        $this->assertSame('Z druhu: 21 dní', $this->element($def, 'inv_due_days')->hint);
        $this->assertSame('Z druhu: Na konci období', $this->element($def, 'inv_timing')->placeholder);
        $this->assertNull($this->element($def, 'inv_vat_mode')->placeholder, 'druh hodnotu nemá');
    }

    public function testWithoutDocTypeTheSeriesCannotBeChosenYet(): void
    {
        $def = $this->contractsForm()->buildFormDefinition(['id' => 9, 'kind' => 2, 'docState' => 10], false);

        $this->assertNull($this->element($def, 'inv_doc_type')->placeholder);
        $this->assertSame([], $this->element($def, 'inv_number_series')->options);
        $this->assertSame('Nejdřív vyber typ dokladu — tady nebo na druhu.', $this->element($def, 'inv_number_series')->hint);
        $this->assertSame('Prázdné = 14 dní.', $this->element($def, 'inv_due_days')->hint);

        // Přepis typu dokladu na smlouvě nabídku řad otevře.
        $def = $this->contractsForm()->buildFormDefinition(['id' => 9, 'kind' => 2, 'docState' => 10, 'inv_doc_type' => 'invpo'], false);
        $this->assertSame([5, 7], array_column($this->element($def, 'inv_number_series')->options, 'value'));
    }

    public function testChangingTheDocTypeClearsTheSeriesOverride(): void
    {
        $result = $this->contractsForm()->recalculate('inv_doc_type', [
            'id' => 9, 'kind' => 1, 'inv_doc_type' => 'invpo', 'inv_number_series' => 5,
        ]);
        $this->assertNull($result->data['inv_number_series']);
        $this->assertSame('invpo', $result->data['inv_doc_type']);
    }

    public function testRowsSubtableRendersPrescriptionColumnsWithValidity(): void
    {
        $form = $this->contractsForm();
        $def = $form->buildFormDefinition(['id' => 9, 'kind' => 1, 'docState' => 10], false);
        $rendered = $form->renderSubtable($def->tabs[1], [
            ['id' => 1, 'description' => 'Nájem', 'quantity' => '1.0000', 'unit' => 1, 'unit_price' => '12000.0000', 'vat_code' => 'out21', 'valid_from' => '2027-01-01', 'valid_to' => null],
        ], ['id' => 9]);

        $this->assertSame(
            ['description', 'quantity', 'unit', 'unit_price', 'vat_code', 'valid_from', 'valid_to'],
            array_column($rendered['columns'], 'id'),
        );
        $this->assertSame('Platnost od', $rendered['columns'][5]['label'], 'popisek z definice tabulky řádků');
        $this->assertSame('order_pos', $rendered['order_column']);
        $this->assertSame('měs', $rendered['rows'][0]['cells']['unit']);
        $this->assertSame('01.01.2027', $rendered['rows'][0]['cells']['valid_from']);
    }

    // ── Formulář druhu ──────────────────────────────────────────────────────

    public function testKindHasInvoicingDefaultsAndTheTemplateTab(): void
    {
        $form = $this->kindsForm();
        $def = $form->buildFormDefinition(['id' => 1, 'name' => 'Kancelář', 'type' => 'office', 'docState' => 10], false);

        $this->assertSame(['basic', 'template'], array_map(static fn ($tab) => $tab->id, $def->tabs));
        $this->assertSame('subtable', $def->tabs[1]->type);
        $this->assertSame('Šablona řádků', $def->tabs[1]->label);

        $this->assertSame(['invno', 'invpo'], array_column($this->element($def, 'inv_doc_type')->options, 'value'));
        $this->assertSame([], $this->element($def, 'inv_number_series')->options);
        $this->assertSame('Nejdřív vyber typ dokladu.', $this->element($def, 'inv_number_series')->hint);
        $this->assertSame('Prázdné = 14 dní.', $this->element($def, 'inv_due_days')->hint);
        foreach (['inv_doc_type', 'inv_number_series', 'inv_timing', 'inv_vat_mode', 'inv_payment_method', 'inv_bank_account'] as $column) {
            $this->assertFalse($this->element($def, $column)->required, $column);
        }

        $def = $form->buildFormDefinition(['id' => 1, 'type' => 'office', 'docState' => 10, 'inv_doc_type' => 'invno'], false);
        $this->assertSame([5, 7], array_column($this->element($def, 'inv_number_series')->options, 'value'));
        $this->assertSame('Řada typu dokladu.', $this->element($def, 'inv_number_series')->hint);

        $result = $form->recalculate('inv_doc_type', ['id' => 1, 'type' => 'office', 'inv_doc_type' => 'invpo', 'inv_number_series' => 5]);
        $this->assertNull($result->data['inv_number_series']);
    }

    public function testTemplateSubtableHasNoValidityColumns(): void
    {
        $form = $this->kindsForm();
        $def = $form->buildFormDefinition(['id' => 1, 'type' => 'office', 'docState' => 10], false);
        $rendered = $form->renderSubtable($def->tabs[1], [
            ['id' => 4, 'description' => 'Nájem', 'quantity' => '1.0000', 'unit' => 1, 'unit_price' => '9500.0000', 'vat_code' => 'cz-114'],
        ], ['id' => 1]);

        $this->assertSame(['description', 'quantity', 'unit', 'unit_price', 'vat_code'], array_column($rendered['columns'], 'id'));
        $this->assertSame('9 500,00', $rendered['rows'][0]['cells']['unit_price']);
        $this->assertSame('cz-114', $rendered['rows'][0]['cells']['vat_code']);
        $this->assertSame('order_pos', $rendered['order_column']);
    }

    // ── Sub-formuláře řádků ─────────────────────────────────────────────────

    public function testRowFormsBuildOnTheSharedBaseAndMatchTheModuleDeclaration(): void
    {
        $forms = [];
        foreach (JsoncParser::parseFile(self::modulesDir() . '/facility/buildings/module.jsonc')['forms'] as $form) {
            $forms[$form['id'] ?? $form['table']] = $form;
        }
        $this->assertSame(
            ['table' => ContractDocument::ROWS_TABLE, 'id' => ContractsForm::ROWS_FORM_ID, 'class' => ContractRowsForm::class],
            $forms[ContractsForm::ROWS_FORM_ID],
        );
        $this->assertSame(
            ['table' => ContractDocument::KIND_ROWS_TABLE, 'id' => ContractKindsForm::TEMPLATE_FORM_ID, 'class' => ContractKindRowsForm::class],
            $forms[ContractKindsForm::TEMPLATE_FORM_ID],
        );

        $row = new ContractRowsForm(ContractDocument::ROWS_TABLE);
        $row->setTableDef(self::tableDef('facility_buildings_contract_rows'));
        $this->assertInstanceOf(AbstractPrescriptionRowsForm::class, $row);
        $def = $row->buildFormDefinition([], true);
        $this->assertSame('Nový řádek smlouvy', $def->titleNew);
        $this->assertSame('Řádek smlouvy', $def->title);
        $columns = array_map(static fn ($e) => $e->column, $def->tabs[0]->sections[0]->columns[0]->elements);
        $this->assertContains('valid_from', $columns);

        $template = new ContractKindRowsForm(ContractDocument::KIND_ROWS_TABLE);
        $template->setTableDef(self::tableDef('facility_buildings_contract_kind_rows'));
        $def = $template->buildFormDefinition([], true);
        $this->assertSame('Nový řádek šablony', $def->titleNew);
        $columns = array_map(static fn ($e) => $e->column, $def->tabs[0]->sections[0]->columns[0]->elements);
        $this->assertNotContains('valid_from', $columns);
        $this->assertNotContains('valid_to', $columns);
        $this->assertContains('vat_code', $columns);
    }

    // ── Detail smlouvy ──────────────────────────────────────────────────────

    /** @param array<string, mixed> $record */
    private function viewer(array $record): InvoicingContractsViewer
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);
        $db->method('fetchRow')->willReturn($record);

        $registry = new ViewerRegistry();
        $registry->loadFromModules([ModuleLoader::loadModule(self::modulesDir() . '/facility/buildings')], 'cs');

        $viewer = new InvoicingContractsViewer($db, 'facility_buildings_contracts');
        $viewer->setConfig($this->buildingsConfig([
            'facility.buildings.viewerLabels' => self::czech('facility/buildings/config/viewerLabels.jsonc'),
            'facility.buildings.docStates' => self::czech('facility/buildings/config/docStates.jsonc'),
            'core.system.viewerDetailLabels' => self::czech('core/system/config/viewerDetailLabels.jsonc'),
            'economy.periodicInvoicing.viewerLabels' => self::czech('economy/periodicInvoicing/config/viewerLabels.jsonc'),
            'economy.periodicInvoicing.periodStates' => ['planned' => ['name' => 'Naplánováno'], 'waiting' => ['name' => 'Čeká na podklady'], 'issued' => ['name' => 'Vystaveno'], 'stopped' => ['name' => 'Zastaveno']],
            'economy.periodicInvoicing.periodicities' => ['month' => ['name' => 'Měsíčně']],
            'economy.periodicInvoicing.invoiceTimings' => ['start' => ['name' => 'Na počátku období']],
        ]));
        $viewer->setLanguage('cs');
        $viewer->setTables(['facility_buildings_contracts' => self::tableDef('facility_buildings_contracts')]);
        $viewer->setDefinition($registry->get('facility.buildings.contracts') ?? throw new \RuntimeException('viewer'));
        return $viewer;
    }

    /** @return array<string, mixed> */
    private static function contract(array $overrides = []): array
    {
        return $overrides + [
            'id' => 6, 'number' => 'KA-2027-0006', 'title' => 'Nájem kanceláře 201', 'kind' => 1, 'type' => 'office',
            'kind_name' => 'Kancelář', 'customer' => 2, 'customer_name' => 'Nájemce', 'date_start' => '2027-01-01', 'date_end' => null,
            'internal_note' => null, 'docState' => 40, 'docStateMain' => 2, 'currency' => 'czk', 'payment_reference' => null,
            'cost_center' => null, 'inv_periodicity' => 'month', 'inv_from' => '2027-01-01', 'inv_doc_text' => null,
            'inv_doc_type' => null, 'inv_number_series' => null, 'inv_due_days' => null, 'inv_timing' => null,
            'inv_vat_mode' => null, 'inv_payment_method' => null, 'inv_bank_account' => null,
        ];
    }

    public function testContractWithPeriodicityHasTheInvoicingTab(): void
    {
        $viewer = $this->viewer(self::contract());
        $viewer->kind = ['id' => 1, 'inv_doc_type' => 'invno', 'inv_number_series' => 5, 'inv_timing' => 'start'];
        $viewer->periods = [
            ['id' => 2, 'contract' => 6, 'period_from' => '2027-02-01', 'period_to' => '2027-02-28', 'state' => 'issued', 'doc' => 702, 'result' => null, 'message' => null],
            ['id' => 1, 'contract' => 6, 'period_from' => '2027-01-01', 'period_to' => '2027-01-31', 'state' => 'issued', 'doc' => 701, 'result' => null, 'message' => null],
        ];
        $viewer->docs = [
            702 => ['id' => 702, 'docState' => 10, 'doc_type' => 'invno', 'doc_number' => '!0000000702', 'total_amount' => '14520.00', 'doc_currency' => 'czk'],
            701 => ['id' => 701, 'docState' => 40, 'doc_type' => 'invno', 'doc_number' => 'FV270001', 'total_amount' => '14520.00', 'doc_currency' => 'czk'],
        ];

        $detail = $viewer->renderDetail(6);

        $this->assertSame(['overview', 'invoicing'], array_column($detail['tabs'], 'id'));
        $this->assertSame('Fakturace', $detail['tabs'][1]['label']);
        $encoded = json_encode($detail['tabs'][1], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('FV270001', $encoded);
        $this->assertStringContainsString('Vystaveno', $encoded);
        $this->assertStringContainsString('Měsíčně', $encoded);
    }

    public function testContractWithoutPeriodicityHasNoInvoicingTab(): void
    {
        $detail = $this->viewer(self::contract(['inv_periodicity' => null, 'inv_from' => null]))->renderDetail(6);
        $this->assertSame(['overview'], array_column($detail['tabs'], 'id'));
    }
}
