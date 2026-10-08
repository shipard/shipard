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
use Shipard\Module\Economy\WorkOrders\KindsForm;
use Shipard\Module\Economy\WorkOrders\WorkOrderSeriesDocument;
use Shipard\Module\Economy\WorkOrders\WorkOrderSeriesForm;
use Shipard\Module\Economy\WorkOrders\WorkOrderTypes;

/**
 * Formuláře Nastavení → Zakázky: typ druhu a druh řady jen ke čtení po
 * založení, výchozí vzorec a restart nové řady, nabídka druhů s typem.
 */
class WorkOrderSettingsFormsTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../../../modules/economy/workOrders';

    private const TYPES = [
        'project'  => ['name' => 'Externí jednorázová', 'external' => true, 'oneOff' => true, 'invoicing' => null],
        'overhead' => ['name' => 'Interní průběžná', 'external' => false, 'oneOff' => false, 'invoicing' => null],
    ];

    private function config(): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            [WorkOrderTypes::CFG_ITEM, self::TYPES],
            ['docs.core.resetScopes', ['none' => ['name' => 'Průběžně'], 'fiscal_year' => ['name' => 'Po roce']]],
        ]);
        return $config;
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

    public function testKindTypeIsEditableOnlyForNewKind(): void
    {
        $form = new KindsForm('economy_work_orders_kinds');
        $form->setConfig($this->config());
        $form->setTableDef(TableDefinition::fromArray(JsoncParser::parseFile(self::MODULE . '/tables/economy_work_orders_kinds.jsonc')));

        $new = $form->buildFormDefinition([], true);
        $type = $this->element($new, 'type');
        $this->assertFalse($type->readOnly);
        $this->assertTrue($type->required);
        $this->assertSame(['project', 'overhead'], array_column($type->options, 'value'));

        $existing = $form->buildFormDefinition(['id' => 3, 'type' => 'project'], false);
        $this->assertTrue($this->element($existing, 'type')->readOnly);
        $this->assertSame('Druh zakázky', $existing->title);
    }

    /** @param list<array<string, mixed>> $kinds */
    private function seriesForm(array $kinds): WorkOrderSeriesForm
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn($kinds);
        $db->method('fetchRow')->willReturn(['name' => 'Servis']);
        $form = new WorkOrderSeriesForm('economy_work_orders_number_series');
        $form->setDb($db);
        $form->setConfig($this->config());
        $form->setTableDef(TableDefinition::fromArray(JsoncParser::parseFile(self::MODULE . '/tables/economy_work_orders_number_series.jsonc')));
        return $form;
    }

    public function testNewSeriesGetsDefaultPatternAndYearlyReset(): void
    {
        $form = $this->seriesForm([['id' => 3, 'name' => 'Servis', 'type' => 'project', 'docState' => 40]]);

        $data = [];
        $form->applyNewRecordDefaults($data);
        $this->assertSame(WorkOrderSeriesDocument::DEFAULT_PATTERN, $data['number_pattern']);
        $this->assertSame('fiscal_year', $data['reset_scope']);

        $def = $form->buildFormDefinition($data, true);
        $kind = $this->element($def, 'kind');
        $this->assertFalse($kind->readOnly);
        $this->assertSame('reload', $kind->triggers);
        $this->assertSame([['value' => 3, 'label' => 'Servis (Externí jednorázová)']], $kind->options);
        $this->assertSame(['none', 'fiscal_year'], array_column($this->element($def, 'reset_scope')->options, 'value'));
        $this->assertTrue($this->element($def, 'number_pattern')->required);
    }

    public function testExistingSeriesKeepsKindReadOnlyAndArchivedKindInOptions(): void
    {
        // Druh v archivu (70) v nabídce zůstává s poznámkou — select by ho jinak vyprázdnil.
        $form = $this->seriesForm([['id' => 3, 'name' => 'Servis', 'type' => 'project', 'docState' => 70]]);
        $def = $form->buildFormDefinition(['id' => 9, 'kind' => 3, 'number_pattern' => '%C%4'], false);

        $kind = $this->element($def, 'kind');
        $this->assertTrue($kind->readOnly);
        $this->assertSame('Servis (Externí jednorázová) — neplatný druh', $kind->options[0]['label']);
    }

    // --- výchozí hodnoty fakturace periodického druhu (fáze 2, D3) -------------

    private function periodicKindsForm(): KindsForm
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            [WorkOrderTypes::CFG_ITEM, self::TYPES + ['periodic' => ['name' => 'Periodická', 'external' => true, 'oneOff' => false, 'invoicing' => 'periodic']]],
            ['docs.core.docTypes', ['invno' => ['name' => 'Faktura vydaná'], 'invpo' => ['name' => 'Zálohová faktura vydaná']]],
            ['economy.workOrders.invoiceTimings', ['start' => ['name' => 'Na počátku období'], 'end' => ['name' => 'Na konci období']]],
            ['docs.core.vatModes', ['0' => ['name' => 'Bez DPH'], '1' => ['name' => 'Ze základu'], '2' => ['name' => 'Z ceny celkem']]],
            ['docs.core.paymentMethods', ['0' => ['name' => 'Hotovost'], '1' => ['name' => 'Převodem']]],
        ]);
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(static function (string $sql): array {
            if (str_contains($sql, 'docs_core_number_series')) {
                return [['id' => 5, 'name' => 'FV 2026']];
            }
            if (str_contains($sql, 'economy_codebooks_bank_accounts')) {
                return [['id' => 3, 'code' => 'HLAVNI', 'name' => 'Provozní účet', 'currency' => 'czk']];
            }
            return [];
        });
        $form = new KindsForm('economy_work_orders_kinds');
        $form->setDb($db);
        $form->setConfig($config);
        $form->setTableDef(TableDefinition::fromArray(JsoncParser::parseFile(self::MODULE . '/tables/economy_work_orders_kinds.jsonc')));
        return $form;
    }

    public function testPeriodicKindShowsInvoicingDefaultsAndSeriesFollowsDocType(): void
    {
        $form = $this->periodicKindsForm();

        $project = $form->buildFormDefinition(['type' => 'project'], true);
        $this->assertTrue($this->element($project, 'inv_doc_type')->hidden);
        $this->assertSame('reload', $this->element($project, 'type')->triggers);

        $periodic = $form->buildFormDefinition(['type' => 'periodic'], true);
        $docType = $this->element($periodic, 'inv_doc_type');
        $this->assertFalse($docType->hidden);
        $this->assertSame(['invno', 'invpo'], array_column($docType->options, 'value'));
        $this->assertSame(['Faktura vydaná', 'Zálohová faktura vydaná'], array_column($docType->options, 'label'));
        $this->assertSame('reload', $docType->triggers);
        $this->assertSame([], $this->element($periodic, 'inv_number_series')->options);
        $this->assertSame('Nejdřív vyber typ dokladu.', $this->element($periodic, 'inv_number_series')->hint);
        $this->assertSame(['start', 'end'], array_column($this->element($periodic, 'inv_timing')->options, 'value'));
        $this->assertSame([0, 1, 2], array_column($this->element($periodic, 'inv_vat_mode')->options, 'value'));
        $this->assertSame([3], array_column($this->element($periodic, 'inv_bank_account')->options, 'value'));

        $withType = $form->buildFormDefinition(['type' => 'periodic', 'inv_doc_type' => 'invno'], true);
        $this->assertSame([['value' => 5, 'label' => 'FV 2026']], $this->element($withType, 'inv_number_series')->options);
    }

    public function testKindRecalculateClearsSeriesOnDocTypeChangeAndDefaultsOnNonPeriodicType(): void
    {
        $form = $this->periodicKindsForm();

        $result = $form->recalculate('inv_doc_type', ['type' => 'periodic', 'inv_doc_type' => 'invpo', 'inv_number_series' => 5]);
        $this->assertNull($result->data['inv_number_series']);

        $result = $form->recalculate('type', ['type' => 'project', 'inv_doc_type' => 'invno', 'inv_number_series' => 5, 'inv_due_days' => 14]);
        $this->assertNull($result->data['inv_doc_type']);
        $this->assertNull($result->data['inv_number_series']);
        $this->assertNull($result->data['inv_due_days']);

        $kept = $form->recalculate('type', ['type' => 'periodic', 'inv_doc_type' => 'invno', 'inv_number_series' => 5]);
        $this->assertSame(5, $kept->data['inv_number_series']);
    }

    public function testChoosingKindPrefillsEmptyName(): void
    {
        $form = $this->seriesForm([['id' => 3, 'name' => 'Servis', 'type' => 'project', 'docState' => 40]]);

        $result = $form->recalculate('kind', ['kind' => 3, 'name' => '']);
        $this->assertSame('Servis', $result->data['name']);

        $kept = $form->recalculate('kind', ['kind' => 3, 'name' => 'Moje řada']);
        $this->assertSame('Moje řada', $kept->data['name']);
    }
}
