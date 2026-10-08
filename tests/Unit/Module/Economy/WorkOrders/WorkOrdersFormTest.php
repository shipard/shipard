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
 * nadřazená jen jednorázová), řada jen u konceptu, defaulty z tabu řady,
 * vyprázdnění skrytých polí při změně řady, tab Přílohy (P6).
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

    private function form(): WorkOrdersForm
    {
        $form = new class('economy_work_orders_heads') extends WorkOrdersForm {
            protected function seriesInfo(int $seriesId): ?array
            {
                return WorkOrdersFormTest::SERIES[$seriesId] ?? null;
            }

            protected function homeCurrency(): string
            {
                return 'czk';
            }
        };
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([
            ['id' => 1, 'name' => 'Projekty 2026', 'docState' => 40, 'kind_name' => 'Projekty'],
            ['id' => 2, 'name' => 'Režie', 'docState' => 40, 'kind_name' => 'Režie'],
        ]);
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            [WorkOrderTypes::CFG_ITEM, self::TYPES],
            [WorkOrdersForm::CURRENCIES_CFG_ITEM, ['czk' => ['name' => 'Česká koruna', 'alpha3' => 'CZK'], 'eur' => ['name' => 'Euro', 'alpha3' => 'EUR']]],
        ]);
        $form->setDb($db);
        $form->setConfig($config);
        $form->setTableDef(TableDefinition::fromArray(JsoncParser::parseFile(self::MODULE . '/tables/economy_work_orders_heads.jsonc')));
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
        ]);

        $this->assertSame(12, $result->data['kind']);
        $this->assertSame('overhead', $result->data['type']);
        $this->assertNull($result->data['customer']);
        $this->assertNull($result->data['currency']);
        $this->assertNull($result->data['payment_reference']);
        $this->assertNull($result->data['parent']);

        // Periodická: strana ano, nadřazená ne.
        $result = $form->recalculate('number_series', ['number_series' => 3, 'customer' => 50, 'parent' => 7]);
        $this->assertSame(50, $result->data['customer']);
        $this->assertNull($result->data['parent']);
        $this->assertSame('czk', $result->data['currency']);
    }
}
