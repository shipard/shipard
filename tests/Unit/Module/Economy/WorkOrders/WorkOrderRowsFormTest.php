<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\WorkOrders\WorkOrderRowsForm;

/**
 * Sub-formulář řádku předpisu: pohyby cílového dokladu zakázky (bez
 * systémových), položka dosadí popis / cenu / jednotku, pole přispěvatele
 * jen s registrovaným přispěvatelem.
 */
class WorkOrderRowsFormTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../../../modules/economy/workOrders';

    private const OPERATIONS = [
        'sale.service' => ['name' => 'Prodej služby', 'docTypes' => ['invno' => ['order' => 2]]],
        'sale.goods'   => ['name' => 'Prodej zboží', 'docTypes' => ['invno' => ['order' => 1], 'invpo' => ['order' => 1]]],
        'advance'      => ['name' => 'Záloha', 'docTypes' => ['invpo' => ['order' => 2]]],
        'asset.sale'   => ['name' => 'Prodej majetku', 'system' => true, 'docTypes' => ['invno' => ['order' => 9]]],
    ];

    /** @param list<array{value: string, label: string}> $contributors */
    private function form(?string $docType = 'invno', array $contributors = []): WorkOrderRowsForm
    {
        $form = new class($docType, $contributors) extends WorkOrderRowsForm {
            public function __construct(private readonly ?string $docType, private readonly array $contributors)
            {
                parent::__construct('economy_work_orders_rows');
            }

            protected function loadParentContext(mixed $workOrderId): ?array
            {
                return $workOrderId === null ? null
                    : ['doc_type' => $this->docType, 'country' => null, 'direction' => 'output', 'place' => 'domestic'];
            }

            protected function loadItem(int $itemId): ?array
            {
                return $itemId === 42 ? ['name' => 'Nájem kanceláře', 'sales_price_no_vat' => '12000.0000', 'unit' => 3] : null;
            }

            protected function unitOptions(): array
            {
                return [['value' => 3, 'label' => 'měsíc (měs)']];
            }

            protected function contributorOptions(): array
            {
                return $this->contributors;
            }
        };
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([['docs.core.rowOperations', self::OPERATIONS]]);
        $form->setConfig($config);
        $form->setTableDef(TableDefinition::fromArray(JsoncParser::parseFile(self::MODULE . '/tables/economy_work_orders_rows.jsonc')));
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

    public function testOperationsFollowTargetDocTypeWithoutSystemOnes(): void
    {
        $def = $this->form()->buildFormDefinition(['work_order' => 9], true);
        $this->assertSame(['sale.goods', 'sale.service'], array_column($this->element($def, 'operation')->options, 'value'));
        $this->assertFalse($this->element($def, 'operation')->required);

        $def = $this->form('invpo')->buildFormDefinition(['work_order' => 9], true);
        $this->assertSame(['sale.goods', 'advance'], array_column($this->element($def, 'operation')->options, 'value'));

        // Uložená systémová operace v nabídce zůstává, jiná ne.
        $def = $this->form()->buildFormDefinition(['id' => 1, 'work_order' => 9, 'operation' => 'asset.sale'], false);
        $this->assertContains('asset.sale', array_column($this->element($def, 'operation')->options, 'value'));

        // Bez typu dokladu (zakázka ani druh ho nemají) je nabídka prázdná.
        $def = $this->form(null)->buildFormDefinition(['work_order' => 9], true);
        $this->assertSame([], $this->element($def, 'operation')->options);
        $this->assertSame([], $this->element($def, 'vat_code')->options);
    }

    public function testItemFillsDescriptionPriceAndUnit(): void
    {
        $result = $this->form()->recalculate('item', ['work_order' => 9, 'item' => 42, 'description' => '']);
        $this->assertSame('Nájem kanceláře', $result->data['description']);
        $this->assertSame(12000.0, $result->data['unit_price']);
        $this->assertSame(3, $result->data['unit']);
        $this->assertSame([3], array_column($this->element($result->formDefinition, 'unit')->options, 'value'));

        $kept = $this->form()->recalculate('item', ['work_order' => 9, 'item' => 99, 'description' => 'Ručně']);
        $this->assertSame('Ručně', $kept->data['description']);
    }

    public function testContributorOptionsComeFromCompiledRegistry(): void
    {
        $form = new WorkOrderRowsForm('economy_work_orders_rows');
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn(string $id): mixed => match ($id) {
            'economy.workOrders.invoiceContributors' => ['energy.consumption' => ['class' => 'X', 'name' => 'Spotřeba energií']],
            default => null,
        });
        $form->setConfig($config);
        $form->setTableDef(TableDefinition::fromArray(JsoncParser::parseFile(self::MODULE . '/tables/economy_work_orders_rows.jsonc')));

        $def = $form->buildFormDefinition([], true);
        $contributor = $this->element($def, 'contributor');
        $this->assertFalse($contributor->hidden);
        $this->assertSame([['value' => 'energy.consumption', 'label' => 'Spotřeba energií']], $contributor->options);
    }

    public function testContributorFieldOnlyWithRegisteredContributors(): void
    {
        $def = $this->form()->buildFormDefinition(['work_order' => 9], true);
        $this->assertTrue($this->element($def, 'contributor')->hidden);

        $def = $this->form(contributors: [['value' => 'energy.consumption', 'label' => 'Spotřeba energií']])
            ->buildFormDefinition(['work_order' => 9], true);
        $contributor = $this->element($def, 'contributor');
        $this->assertFalse($contributor->hidden);
        $this->assertSame(['energy.consumption'], array_column($contributor->options, 'value'));
        $this->assertSame('Nový řádek zakázky', $def->titleNew);
    }
}
