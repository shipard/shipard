<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Facility\Buildings;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Form\MultiLookupBinding;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Facility\Buildings\ContractKindsForm;
use Shipard\Module\Facility\Buildings\ContractsForm;
use Shipard\Module\Facility\Buildings\ContractTypes;

/**
 * Formuláře doplňku Budovy (#174 D18, D24): druh smlouvy (typ jen
 * v nepoužitém konceptu, číslování) a smlouva (druh jen v Konceptu, pole
 * Místa podle vlastností typu — filtr typů míst, hvězdička, skrytí).
 */
class BuildingsFormsTest extends TestCase
{
    use BuildingsConfig;

    public const KINDS = [
        ['id' => 1, 'name' => 'Kancelář', 'type' => 'office'],
        ['id' => 2, 'name' => 'Ostatní', 'type' => 'other'],
        ['id' => 3, 'name' => 'Parkování', 'type' => 'parking'],
        ['id' => 4, 'name' => 'Služby', 'type' => 'service'],
    ];

    private static function tableDef(string $table): TableDefinition
    {
        return TableDefinition::fromArray(
            JsoncParser::parseFile(self::modulesDir() . '/facility/buildings/tables/' . $table . '.jsonc'),
        );
    }

    private function contractsForm(): ContractsForm
    {
        $form = new class('facility_buildings_contracts') extends ContractsForm {
            protected function loadKinds(int $currentId): array
            {
                return BuildingsFormsTest::KINDS;
            }
        };
        $types = self::czech('facility/buildings/config/contractTypes.jsonc')
            + ['service' => ['name' => 'Služba', 'places' => 'none', 'order' => 90]];
        $form->setConfig($this->buildingsConfig([
            ContractTypes::CFG_ITEM => $types,
            'facility.buildings.formTexts' => self::czech('facility/buildings/config/formTexts.jsonc'),
        ]));
        $form->setTableDef(self::tableDef('facility_buildings_contracts'));
        $form->setTables([
            'facility_buildings_contract_places' => self::tableDef('facility_buildings_contract_places'),
        ]);
        return $form;
    }

    private function kindsForm(bool $used = false): ContractKindsForm
    {
        $form = new class('facility_buildings_contract_kinds', $used) extends ContractKindsForm {
            public function __construct(string $table, private readonly bool $used)
            {
                parent::__construct($table);
            }

            protected function isKindUsed(int $kindId): bool
            {
                return $this->used;
            }
        };
        $form->setConfig($this->buildingsConfig([
            'facility.buildings.formTexts' => self::czech('facility/buildings/config/formTexts.jsonc'),
            'facility.buildings.resetScopes' => self::czech('facility/buildings/config/resetScopes.jsonc'),
            'core.system.formDefaults' => ['generalTabLabel' => 'Obecné'],
        ]));
        $form->setTableDef(self::tableDef('facility_buildings_contract_kinds'));
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

    // ── Smlouva ─────────────────────────────────────────────────────────────

    public function testNewContractOffersLiveKindsAndAnyPlaces(): void
    {
        $def = $this->contractsForm()->buildFormDefinition([], true);

        $this->assertSame(['basic', 'attachments'], array_map(static fn ($tab) => $tab->id, $def->tabs));
        $this->assertSame('attachments', $def->tabs[1]->type);
        $this->assertSame(
            ['kind', 'number', 'title', 'customer', 'date_start', 'date_end', 'places', 'internal_note'],
            array_map(static fn (FormElement $el): ?string => $el->column, $def->tabs[0]->sections[0]->columns[0]->elements),
        );

        $kind = $this->element($def, 'kind');
        $this->assertSame(['Kancelář', 'Ostatní', 'Parkování', 'Služby'], array_column($kind->options, 'label'));
        $this->assertTrue($kind->required);
        $this->assertFalse($kind->readOnly);
        $this->assertSame('reload', $kind->triggers);
        $this->assertTrue($this->element($def, 'number')->readOnly);
        $this->assertSame('Přidělí se při potvrzení', $this->element($def, 'number')->placeholder);
        $this->assertSame(['table' => 'base_persons_persons'], array_intersect_key($this->element($def, 'customer')->lookup, ['table' => 1]));

        // Bez druhu: libovolné místo, nepovinné.
        $places = $this->element($def, 'places');
        $this->assertSame('multilookup', $places->type);
        $this->assertSame('Místa', $places->label);
        $this->assertSame('base_places_places', $places->lookup['table']);
        $this->assertArrayNotHasKey('filter', array_filter($places->lookup, static fn ($v): bool => $v !== null));
        $this->assertFalse($places->required);
        $this->assertFalse($places->hidden);
        $this->assertSame('Místa libovolného typu.', $places->hint);
    }

    public function testPlacesFollowTheTypeOfTheKind(): void
    {
        $form = $this->contractsForm();

        $office = $this->element($form->buildFormDefinition(['kind' => 1], true), 'places');
        $this->assertSame(['types' => 'room,section'], $office->lookup['filter']);
        $this->assertTrue($office->required, 'povinná místa = hvězdička');
        $this->assertFalse($office->hidden);
        $this->assertSame('Povolené typy míst: Místnost, Část budovy.', $office->hint);

        $parking = $this->element($form->buildFormDefinition(['kind' => 3], true), 'places');
        $this->assertSame(['types' => 'spot,outdoor'], $parking->lookup['filter']);

        $other = $this->element($form->buildFormDefinition(['kind' => 2], true), 'places');
        $this->assertNull($other->lookup['filter'] ?? null, 'libovolný typ = bez filtru');
        $this->assertFalse($other->required);
    }

    public function testPlacesAreHiddenForATypeWithoutPlaces(): void
    {
        $places = $this->element($this->contractsForm()->buildFormDefinition(['kind' => 4], true), 'places');

        $this->assertTrue($places->hidden);
        $this->assertFalse($places->required);
    }

    public function testChangingTheKindRebuildsTheFilterAndKeepsThePlaces(): void
    {
        $form = $this->contractsForm();
        $data = ['kind' => 3, 'type' => 'office', 'title' => 'Stání', 'places' => [201, 202]];

        $result = $form->recalculate('kind', $data);

        $this->assertSame('parking', $result->data['type']);
        $this->assertSame([201, 202], $result->data['places'], 'místa přepočet nemaže — ohlásí je validace');
        $this->assertSame(['types' => 'spot,outdoor'], $this->element($result->formDefinition, 'places')->lookup['filter']);

        $cleared = $form->recalculate('kind', ['kind' => null, 'type' => 'office']);
        $this->assertNull($cleared->data['type']);
    }

    public function testKindIsReadOnlyOutsideDraftAndTheStoredTypeDecides(): void
    {
        $form = $this->contractsForm();
        // Druh mezitím někdo přepnul — potvrzená smlouva se řídí uloženým typem.
        $def = $form->buildFormDefinition(['id' => 5, 'kind' => 2, 'type' => 'office', 'docState' => 80], false);

        $this->assertTrue($this->element($def, 'kind')->readOnly);
        $this->assertNull($this->element($def, 'kind')->hint);
        $this->assertNull($this->element($def, 'number')->placeholder);
        $this->assertSame(['types' => 'room,section'], $this->element($def, 'places')->lookup['filter']);
    }

    public function testStoredKindOutsideTheOfferStaysSelectable(): void
    {
        $def = $this->contractsForm()->buildFormDefinition(['id' => 5, 'kind' => 99, 'type' => 'office', 'docState' => 40], false);

        $this->assertContains(['value' => 99, 'label' => '#99'], $this->element($def, 'kind')->options);
    }

    public function testNewRecordWithPresetKindGetsItsType(): void
    {
        $data = ['kind' => 3];
        $this->contractsForm()->applyNewRecordDefaults($data);

        $this->assertSame('parking', $data['type']);
    }

    public function testPlacesElementBindsToTheContractPlacesTable(): void
    {
        $form = $this->contractsForm();
        $element = $this->element($form->buildFormDefinition(['kind' => 1], true), 'places');
        $tables = [
            'facility_buildings_contracts' => self::tableDef('facility_buildings_contracts'),
            'facility_buildings_contract_places' => self::tableDef('facility_buildings_contract_places'),
        ];

        $binding = MultiLookupBinding::forElement($element, 'facility_buildings_contracts', $tables['facility_buildings_contracts'], $tables);

        $this->assertNotNull($binding);
        $this->assertSame(
            [['place' => 7, 'order_pos' => 1], ['id' => 31, 'place' => 5, 'order_pos' => 2]],
            $binding->toRows([7, 5], [['id' => 31, 'place' => 5, 'order_pos' => 0]]),
        );
    }

    // ── Druh smlouvy ────────────────────────────────────────────────────────

    public function testNewKindGetsTheDefaultPatternAndYearlyReset(): void
    {
        $form = $this->kindsForm();
        $data = [];
        $form->applyNewRecordDefaults($data);

        $this->assertSame(['number_pattern' => '%C-%Y-%4', 'reset_scope' => 'yearly'], $data);

        $def = $form->buildFormDefinition($data, true);
        $type = $this->element($def, 'type');
        $this->assertFalse($type->readOnly);
        $this->assertCount(7, $type->options);
        $this->assertStringContainsString('jaká místa smí smlouva', (string) $type->hint);
        $this->assertSame(['continuous', 'yearly'], array_column($this->element($def, 'reset_scope')->options, 'value'));
        $this->assertSame(['Průběžně', 'Ročně'], array_column($this->element($def, 'reset_scope')->options, 'label'));
        $this->assertStringContainsString('%C kód druhu', (string) $this->element($def, 'number_pattern')->hint);
        $this->assertTrue($this->element($def, 'number_pattern')->required);
    }

    public function testTypeIsReadOnlyForAConfirmedOrUsedKind(): void
    {
        $confirmed = $this->kindsForm()->buildFormDefinition(['id' => 1, 'type' => 'office', 'docState' => 40], false);
        $this->assertTrue($this->element($confirmed, 'type')->readOnly);
        $this->assertSame('Typ potvrzeného druhu nejde změnit.', $this->element($confirmed, 'type')->hint);

        $used = $this->kindsForm(used: true)->buildFormDefinition(['id' => 1, 'type' => 'office', 'docState' => 10], false);
        $this->assertTrue($this->element($used, 'type')->readOnly);
        $this->assertSame('Typ nejde změnit, dokud druh používají smlouvy.', $this->element($used, 'type')->hint);

        $draft = $this->kindsForm()->buildFormDefinition(['id' => 1, 'type' => 'office', 'docState' => 10], false);
        $this->assertFalse($this->element($draft, 'type')->readOnly);
    }
}
