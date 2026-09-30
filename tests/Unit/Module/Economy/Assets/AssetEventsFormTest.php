<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\FormDefinition;
use Shipard\Module\Economy\Assets\AssetEventsForm;
use Shipard\Module\Economy\Assets\AssetPlanService;

/** Pole formuláře události podle druhu a výchozí hodnoty z presetu. */
class AssetEventsFormTest extends TestCase
{
    private TestAssetPlanService $service;

    private function form(): AssetEventsForm
    {
        $this->service = new TestAssetPlanService();
        $this->service->cards[4] = ['id' => 4, 'category' => 'tangible', 'tax_method' => 'straight', 'tax_rule' => 'cz-2', 'acc_method' => 'as_tax', 'docState' => 40];
        $this->service->events = [
            ['id' => 1, 'asset' => 4, 'event_kind' => 'activation', 'scope' => 'both', 'event_date' => '2022-03-15', 'amount' => 100000, 'docState' => 40],
        ];
        $service = $this->service;
        $form = new class('economy_assets_events', $service) extends AssetEventsForm {
            public function __construct(string $table, private readonly AssetPlanService $service)
            {
                parent::__construct($table);
            }

            protected function planService(): AssetPlanService
            {
                return $this->service;
            }
        };
        $form->setConfig(TestAssetPlanService::config());
        return $form;
    }

    /** @return list<string> viditelné sloupce */
    private function visible(FormDefinition $def): array
    {
        $out = [];
        foreach ($def->tabs[0]->sections[0]->columns[0]->elements as $el) {
            if ($el->column !== null && !$el->hidden) {
                $out[] = $el->column;
            }
        }
        return $out;
    }

    public function testFieldsFollowEventKind(): void
    {
        $form = $this->form();
        $base = ['asset' => 4, 'scope' => 'both'];

        $this->assertSame(
            ['asset', 'event_kind', 'event_date', 'amount', 'note'],
            $this->visible($form->buildFormDefinition($base + ['event_kind' => 'activation'], true)),
        );
        $this->assertSame(
            ['asset', 'event_kind', 'scope', 'event_date', 'original_date', 'amount', 'accumulated', 'units_done', 'price_increased', 'note'],
            $this->visible($form->buildFormDefinition(['asset' => 4, 'scope' => 'tax', 'event_kind' => 'opening'], true)),
        );
        $this->assertSame(
            ['asset', 'event_kind', 'scope', 'period_begin', 'period_end', 'event_date', 'amount', 'half_year', 'note'],
            $this->visible($form->buildFormDefinition(['asset' => 4, 'scope' => 'acc', 'event_kind' => 'depreciation'], true)),
        );
        $this->assertSame(
            ['asset', 'event_kind', 'scope', 'event_date', 'note'],
            $this->visible($form->buildFormDefinition(['asset' => 4, 'scope' => 'tax', 'event_kind' => 'interruption'], true)),
        );
        $this->assertSame('Vyřazení majetku', $form->buildFormDefinition($base + ['event_kind' => 'disposal'], true)->title);
    }

    public function testDisposalOffersHalfYearOnlyWhenAllowed(): void
    {
        $form = $this->form();

        $data = ['asset' => 4, 'scope' => 'both', 'event_kind' => 'disposal', 'half_year' => 0];
        $form->applyNewRecordDefaults($data);
        $this->assertSame('2024-06-30', $data['event_date']);
        $this->assertSame(1, $data['half_year']);
        $this->assertContains('half_year', $this->visible($form->buildFormDefinition($data, true)));

        // Zařazeno v roce vyřazení → polovina se nenabízí.
        $this->service->events[0]['event_date'] = '2024-02-10';
        $data = ['asset' => 4, 'scope' => 'both', 'event_kind' => 'disposal', 'half_year' => 0];
        $form->applyNewRecordDefaults($data);
        $this->assertSame(0, $data['half_year']);
        $this->assertNotContains('half_year', $this->visible($form->buildFormDefinition($data, true)));
    }

    public function testOpeningBalanceDefaultsToFirstDayOfFiscalYear(): void
    {
        $form = $this->form();
        $data = ['asset' => 4, 'scope' => 'tax', 'event_kind' => 'opening'];
        $form->applyNewRecordDefaults($data);
        $this->assertSame('2024-01-01', $data['event_date']);
    }
}
