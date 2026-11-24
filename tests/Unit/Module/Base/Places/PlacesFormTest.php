<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Places;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Base\Places\PlaceKindDocument;
use Shipard\Module\Base\Places\PlaceKindsForm;
use Shipard\Module\Base\Places\PlacesForm;
use Shipard\Module\Base\Places\PlaceTypes;

/**
 * Formuláře míst (#169 D12, D13, D15): adresa podle typu druhu, dynamický
 * filtr lookupu Nadřazené místo z přepočtu, nabídka druhů, zámek typu druhu.
 */
class PlacesFormTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../../../modules/base/places';

    private const KINDS = [
        ['id' => 2, 'name' => 'Budova', 'type' => 'building', 'docState' => 40],
        ['id' => 4, 'name' => 'Podlaží', 'type' => 'floor', 'docState' => 40],
        ['id' => 5, 'name' => 'Kancelář', 'type' => 'room', 'docState' => 10],
        ['id' => 9, 'name' => 'Stará dílna', 'type' => 'room', 'docState' => 70],
    ];

    private function config(): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            [PlaceTypes::CFG_ITEM, JsoncParser::parseFile(self::MODULE . '/config/types.jsonc')],
            [PlaceKindDocument::ICONS_CFG_ITEM, JsoncParser::parseFile(self::MODULE . '/config/icons.jsonc')],
            ['base.places.formTexts', JsoncParser::parseFile(self::MODULE . '/config/formTexts.jsonc')],
            ['world.base.countries', ['cz' => ['name' => 'Czechia'], 'sk' => ['name' => 'Slovakia']]],
            ['core.system.formDefaults', null],
        ]);
        return $config;
    }

    private function placesForm(): PlacesForm
    {
        $form = new class('base_places_places') extends PlacesForm {
            /** @var list<array<string, mixed>> */
            public array $kinds = [];

            protected function loadKinds(int $currentId): array
            {
                return array_values(array_filter(
                    $this->kinds,
                    static fn (array $kind): bool => in_array($kind['docState'], [10, 40, 80], true) || $kind['id'] === $currentId,
                ));
            }
        };
        $form->kinds = self::KINDS;
        $form->setConfig($this->config());
        $form->setTableDef(TableDefinition::fromArray(JsoncParser::parseFile(self::MODULE . '/tables/base_places_places.jsonc')));
        return $form;
    }

    /** Prvek podle sloupce, i uvnitř skupiny `inline`. */
    private function element(FormDefinition $def, string $column): FormElement
    {
        foreach ($def->tabs[0]->sections as $section) {
            foreach ($section->columns as $col) {
                foreach ($col->elements as $el) {
                    foreach ($el->type === 'inline' ? ($el->elements ?? []) : [$el] as $candidate) {
                        if ($candidate->column === $column) {
                            return $candidate;
                        }
                    }
                }
            }
        }
        $this->fail("Element {$column} not found");
    }

    public function testAddressSectionFollowsTheTypeOfTheKind(): void
    {
        $form = $this->placesForm();

        $building = $form->buildFormDefinition(['kind' => 2], true);
        $this->assertSame('Adresa', $building->tabs[0]->sections[1]->title);
        $this->assertFalse($building->tabs[0]->sections[1]->hidden, 'budova adresu má');

        $this->assertTrue($form->buildFormDefinition(['kind' => 4], true)->tabs[0]->sections[1]->hidden, 'podlaží adresu nemá');
        $this->assertTrue($form->buildFormDefinition([], true)->tabs[0]->sections[1]->hidden, 'bez druhu adresa není');
    }

    public function testChangingTheKindRebuildsAddressAndParentFilter(): void
    {
        $form = $this->placesForm();

        $result = $form->recalculate('kind', ['id' => 7, 'name' => 'X', 'kind' => 4]);
        $this->assertTrue($result->formDefinition->tabs[0]->sections[1]->hidden);
        $this->assertSame(
            ['childType' => 'floor', 'exclude' => 7],
            $this->element($result->formDefinition, 'parent')->lookup['filter'],
        );

        $result = $form->recalculate('kind', ['id' => 7, 'name' => 'X', 'kind' => 2]);
        $this->assertFalse($result->formDefinition->tabs[0]->sections[1]->hidden);
        $this->assertSame(
            ['childType' => 'building', 'exclude' => 7],
            $this->element($result->formDefinition, 'parent')->lookup['filter'],
        );
        $this->assertSame('reload', $this->element($result->formDefinition, 'kind')->triggers);
    }

    public function testParentFilterOfANewRecord(): void
    {
        $form = $this->placesForm();

        // Nový záznam nic nevynechává; bez druhu není ani filtr typu.
        $this->assertNull($this->element($form->buildFormDefinition([], true), 'parent')->lookup['filter']);
        // Předvyplněný druh a nadřazené místo (task B, `open_form` s `preset`).
        $preset = $form->buildFormDefinition(['kind' => 5, 'parent' => 3], true);
        $parent = $this->element($preset, 'parent');
        $this->assertSame(['childType' => 'room'], $parent->lookup['filter']);
        $this->assertSame('base_places_places', $parent->lookup['table']);
    }

    public function testKindOptionsShowTypeAndKeepTheStoredRetiredKind(): void
    {
        $form = $this->placesForm();

        $options = $this->element($form->buildFormDefinition([], true), 'kind')->options;
        $this->assertSame(
            [
                ['value' => 2, 'label' => 'Budova (Building)'],
                ['value' => 4, 'label' => 'Podlaží (Floor)'],
                ['value' => 5, 'label' => 'Kancelář (Room)'],
            ],
            $options,
        );

        $withRetired = $this->element($form->buildFormDefinition(['id' => 1, 'kind' => 9], false), 'kind')->options;
        $this->assertSame(['value' => 9, 'label' => 'Stará dílna (Room) — retired kind'], $withRetired[3]);
        $this->assertTrue($this->element($form->buildFormDefinition([], true), 'kind')->required);
    }

    public function testFormHasAttachmentsTabAndCatalogHints(): void
    {
        $def = $this->placesForm()->buildFormDefinition(['kind' => 2], true);

        $this->assertSame('attachments', $def->tabs[1]->type);
        $this->assertSame(467, $def->tabs[1]->tableId);
        // Nápověda kódu je blok `hint` pod řádkem — u pole ve skupině
        // `inline` by ji frontend nevykreslil.
        $hints = [];
        foreach ($def->tabs[0]->sections[0]->columns[0]->elements as $el) {
            foreach ($el->blocks ?? [] as $block) {
                $hints[] = $block['type'] . ': ' . $block['text'];
            }
        }
        $this->assertSame(
            [
                'hint: The short name is shown in the path instead of the name. The code is unique within the parent place.',
                'hint: Order within the parent place. 0 = placed last on save.',
            ],
            $hints,
        );
        $this->assertNull($this->element($def, 'code')->hint);
        $this->assertSame(['cz', 'sk'], array_column($this->element($def, 'country')->options, 'value'));
        $this->assertSame('Místo', $def->title);
        $this->assertSame('Nové místo', $def->titleNew);
    }

    private function kindsForm(): PlaceKindsForm
    {
        $form = new PlaceKindsForm('base_places_kinds');
        $form->setConfig($this->config());
        $form->setTableDef(TableDefinition::fromArray(JsoncParser::parseFile(self::MODULE . '/tables/base_places_kinds.jsonc')));
        return $form;
    }

    public function testKindTypeIsReadOnlyOnlyAfterFirstConfirmation(): void
    {
        $form = $this->kindsForm();

        $new = $this->element($form->buildFormDefinition([], true), 'type');
        $this->assertFalse($new->readOnly);
        $this->assertTrue($new->required);
        $this->assertCount(8, $new->options);

        $draft = $form->buildFormDefinition(['id' => 3, 'type' => 'room', 'docState' => 10], false);
        $this->assertFalse($this->element($draft, 'type')->readOnly, 'koncept typ měnit smí');

        foreach ([40, 80, 70] as $state) {
            $confirmed = $form->buildFormDefinition(['id' => 3, 'type' => 'room', 'docState' => $state], false);
            $this->assertTrue($this->element($confirmed, 'type')->readOnly, "stav {$state}");
        }
    }

    public function testKindIconOffersTheSetWithEmptyChoiceByType(): void
    {
        $icon = $this->element($this->kindsForm()->buildFormDefinition([], true), 'icon');

        $this->assertFalse($icon->required);
        $this->assertSame('By type', $icon->placeholder);
        $this->assertCount(15, $icon->options);
        $this->assertContains('place-office', array_column($icon->options, 'value'));
    }
}
