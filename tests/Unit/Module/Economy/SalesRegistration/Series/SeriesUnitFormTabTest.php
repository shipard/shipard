<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Series;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\ExtensionDefinition;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Database\TableMerger;
use Shipard\Core\Form\ModuleFormTabs;
use Shipard\Core\Module\ModuleExtensionContext;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\SalesRegistration\Series\SeriesUnitFormTab;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;

/**
 * Záložka Evidence tržeb ve formuláři číselné řady (#171 D21) — první
 * skutečná `formTabs` ve stromu: select jednotek nad sloupcem z rozšíření,
 * který projde pravidly záložky z modulu.
 */
class SeriesUnitFormTabTest extends TestCase
{
    use SalesRegistrationConfig;

    private const ROOT = __DIR__ . '/../../../../../../modules';

    private function seriesDefinition(): TableDefinition
    {
        $table = TableDefinition::fromArray(JsoncParser::parseFile(self::ROOT . '/docs/core/tables/docs_core_number_series.jsonc'));
        $extension = ExtensionDefinition::fromArray(
            JsoncParser::parseFile(self::ROOT . '/economy/salesRegistration/extensions/docs_core_number_series.jsonc'),
        );
        return TableMerger::merge($table, $extension);
    }

    /** @param list<array<string, mixed>> $units */
    private function tab(array $units): SeriesUnitFormTab
    {
        $tab = new class extends SeriesUnitFormTab {
            /** @var list<array<string, mixed>> */
            public array $units = [];
            public ?int $selected = null;

            protected function loadUnits(ModuleExtensionContext $context, int $selected): array
            {
                $this->selected = $selected;
                return $this->units;
            }
        };
        $tab->units = $units;
        return $tab;
    }

    private function context(): ModuleExtensionContext
    {
        return new ModuleExtensionContext(
            'docs_core_number_series',
            $this->createMock(DataSourceConnection::class),
            $this->salesRegistrationConfig(),
            null,
            'cs',
            $this->seriesDefinition(),
        );
    }

    public function testExtensionColumnIsEditableByTheTab(): void
    {
        $column = null;
        foreach ($this->seriesDefinition()->columns as $candidate) {
            if ($candidate->id === SeriesUnitFormTab::COLUMN) {
                $column = $candidate;
            }
        }

        $this->assertNotNull($column, 'sloupec přidává extension doplňku');
        $this->assertFalse($column->system, 'systémový sloupec by záložka z modulu editovat nesměla');
        $this->assertTrue($column->nullable);
        $this->assertSame('economy_sales_registration_units', $column->reference);
    }

    public function testOffersUnitsWithRegistrationAndUnitNumber(): void
    {
        $tab = $this->tab([
            ['id' => 5, 'unit_id' => '181', 'name' => 'Prodejna', 'registration_name' => 'EET hlavní'],
            ['id' => 6, 'unit_id' => '182', 'name' => null, 'registration_name' => 'EET hlavní'],
        ]);

        $built = $tab->build(['id' => 3, 'sales_registration_unit' => 6], false, $this->context());

        $this->assertNotNull($built);
        $this->assertSame(6, $tab->selected, 'vybraná jednotka zůstává v nabídce i po archivaci registrace');
        $this->assertSame('Evidence tržeb', $built->label);
        $select = $built->sections[0]->columns[0]->elements[0];
        $this->assertSame('select', $select->type);
        $this->assertSame(SeriesUnitFormTab::COLUMN, $select->column);
        $this->assertSame(
            [
                ['value' => 5, 'label' => 'EET hlavní — 181 Prodejna'],
                ['value' => 6, 'label' => 'EET hlavní — 182'],
            ],
            $select->options,
        );
        $this->assertStringContainsString('výchozí jednotka registrace', (string) $select->hint);
    }

    public function testTabPassesTheRulesOfModuleFormTabs(): void
    {
        $tab = $this->tab([['id' => 5, 'unit_id' => '181', 'name' => 'Prodejna', 'registration_name' => 'EET']]);
        $context = $this->context();
        $built = $tab->build([], true, $context);
        $this->assertNotNull($built);

        // Kontrola, kterou záložce z modulu dělá sběr (typ záložky, jen
        // povolené prvky, jen zapisovatelné sloupce) — výjimka = záložka
        // by se ve formuláři tiše neukázala.
        $check = new \ReflectionMethod(ModuleFormTabs::class, 'check');
        $check->invoke(null, $built, $context);
        $this->addToAssertionCount(1);
    }

    public function testWithoutUnitsThereIsNoTab(): void
    {
        $this->assertNull($this->tab([])->build([], true, $this->context()));
    }
}
