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

    public function testChoosingKindPrefillsEmptyName(): void
    {
        $form = $this->seriesForm([['id' => 3, 'name' => 'Servis', 'type' => 'project', 'docState' => 40]]);

        $result = $form->recalculate('kind', ['kind' => 3, 'name' => '']);
        $this->assertSame('Servis', $result->data['name']);

        $kept = $form->recalculate('kind', ['kind' => 3, 'name' => 'Moje řada']);
        $this->assertSame('Moje řada', $kept->data['name']);
    }
}
