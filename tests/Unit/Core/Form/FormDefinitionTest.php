<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\FormColumn;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Form\FormHeaderInfo;
use Shipard\Core\Form\FormSection;
use Shipard\Core\Form\FormTab;

class FormDefinitionTest extends TestCase
{
    private function singleFieldTab(string $id = 'basic', string $label = 'Basic'): FormTab
    {
        return new FormTab(
            id: $id,
            label: $label,
            sections: [
                new FormSection([
                    new FormColumn([
                        new FormElement(type: 'input', column: 'name', label: 'Name'),
                    ]),
                ]),
            ],
        );
    }

    public function testToArrayProducesSnakeCaseKeys(): void
    {
        $def = new FormDefinition(
            table: 'test_table',
            title: 'Edit Record',
            titleNew: 'New Record',
            tabs: [$this->singleFieldTab()],
        );

        $arr = $def->toArray();

        $this->assertSame('test_table', $arr['table']);
        $this->assertSame('Edit Record', $arr['title']);
        $this->assertSame('New Record', $arr['title_new']);
        $this->assertCount(1, $arr['tabs']);
        $this->assertSame('basic', $arr['tabs'][0]['id']);
        $this->assertSame('fields', $arr['tabs'][0]['type']);
        $this->assertArrayNotHasKey('doc_states', $arr);
    }

    public function testDocStatesOmittedWhenNull(): void
    {
        $def = new FormDefinition(
            table: 'test',
            title: 'Test',
            titleNew: 'New',
            tabs: [$this->singleFieldTab()],
        );

        $arr = $def->toArray();

        $this->assertArrayNotHasKey('doc_states', $arr);
    }

    public function testWithDocStatesReturnsNewInstanceWithDocStates(): void
    {
        $def = new FormDefinition(
            table: 'test',
            title: 'Test',
            titleNew: 'New',
            tabs: [$this->singleFieldTab()],
        );

        $docStates = [
            'currentState' => 10,
            'stateName'    => 'Koncept',
            'stateStyle'   => 'concept',
            'readOnly'     => false,
            'transitions'  => [],
        ];

        $enriched = $def->withDocStates($docStates);

        $this->assertNotSame($def, $enriched);
        $this->assertNull($def->docStates);
        $this->assertSame($docStates, $enriched->docStates);
        $this->assertSame($docStates, $enriched->toArray()['doc_states']);
    }

    public function testFullStructureSerialization(): void
    {
        $def = new FormDefinition(
            table: 'base_persons_persons',
            title: 'Osoba',
            titleNew: 'Nová osoba',
            tabs: [
                new FormTab(
                    id: 'basic',
                    label: 'Základní údaje',
                    sections: [
                        new FormSection([
                            new FormColumn([
                                new FormElement(type: 'input', column: 'person_id', label: 'ID', required: true),
                            ]),
                        ]),
                        new FormSection(
                            columns: [
                                new FormColumn([new FormElement(type: 'input', column: 'company_id', label: 'IČO')]),
                                new FormColumn([new FormElement(type: 'input', column: 'tax_id', label: 'DIČ')]),
                            ],
                            title: 'Identifikace firmy',
                        ),
                    ],
                ),
                new FormTab(
                    id: 'contacts',
                    label: 'Kontakty',
                    type: 'subtable',
                    subtable: [
                        'table'      => 'base_persons_contacts',
                        'foreignKey' => 'person',
                        'formId'     => 'base.persons.contacts',
                    ],
                ),
                new FormTab(
                    id: 'attachments',
                    label: 'Přílohy',
                    type: 'attachments',
                    tableId: 110,
                ),
            ],
        );

        $arr = $def->toArray();

        // tab 0 — fields with 2 sections
        $tab0 = $arr['tabs'][0];
        $this->assertSame('fields', $tab0['type']);
        $this->assertCount(2, $tab0['sections']);
        $this->assertNull($tab0['sections'][0]['title']);
        $this->assertSame('Identifikace firmy', $tab0['sections'][1]['title']);
        $this->assertCount(2, $tab0['sections'][1]['columns']);

        // tab 1 — subtable
        $tab1 = $arr['tabs'][1];
        $this->assertSame('subtable', $tab1['type']);
        $this->assertSame('base_persons_contacts', $tab1['subtable']['table']);
        $this->assertSame('person', $tab1['subtable']['foreign_key']);
        $this->assertSame('base.persons.contacts', $tab1['subtable']['form_id']);

        // tab 2 — attachments
        $tab2 = $arr['tabs'][2];
        $this->assertSame('attachments', $tab2['type']);
        $this->assertSame(110, $tab2['table_id']);
    }

    public function testFieldsTabRejectsEmptySections(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must have at least one section');

        new FormTab(id: 'basic', label: 'Basic');
    }

    public function testSubtableTabRequiresSubtable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires subtable');

        new FormTab(id: 'contacts', label: 'Kontakty', type: 'subtable');
    }

    public function testAttachmentsTabRequiresTableId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires tableId');

        new FormTab(id: 'att', label: 'Přílohy', type: 'attachments');
    }

    public function testFieldsTabRejectsSubtable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FormTab(
            id: 'basic',
            label: 'Basic',
            sections: [new FormSection([new FormColumn([])])],
            subtable: ['table' => 't', 'foreignKey' => 'fk', 'formId' => null],
        );
    }

    public function testSubtableTabRejectsSections(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FormTab(
            id: 'contacts',
            label: 'Contacts',
            type: 'subtable',
            sections: [new FormSection([new FormColumn([])])],
            subtable: ['table' => 't', 'foreignKey' => 'fk', 'formId' => null],
        );
    }

    public function testHeaderInfoNullByDefaultAndIncludedInToArray(): void
    {
        $def = new FormDefinition(
            table: 'test',
            title: 'Test',
            titleNew: 'New',
            tabs: [$this->singleFieldTab()],
        );

        $this->assertNull($def->headerInfo);
        $arr = $def->toArray();
        $this->assertArrayHasKey('header_info', $arr);
        $this->assertNull($arr['header_info']);
    }

    public function testWithHeaderInfoReturnsNewInstanceWithHeaderInfo(): void
    {
        $def = new FormDefinition(
            table: 'test',
            title: 'Test',
            titleNew: 'New',
            tabs: [$this->singleFieldTab()],
        );

        $headerInfo = new FormHeaderInfo(
            title: 'Beta Software, a.s.',
            info: [['label' => 'IČO', 'value' => '68253848']],
        );

        $enriched = $def->withHeaderInfo($headerInfo);

        $this->assertNotSame($def, $enriched);
        $this->assertNull($def->headerInfo);
        $this->assertSame($headerInfo, $enriched->headerInfo);

        $arr = $enriched->toArray();
        $this->assertSame('Beta Software, a.s.', $arr['header_info']['title']);
        $this->assertSame(
            [['label' => 'IČO', 'value' => '68253848']],
            $arr['header_info']['info'],
        );
    }

    public function testWithHeaderInfoAcceptsNullToClear(): void
    {
        $def = new FormDefinition(
            table: 'test',
            title: 'Test',
            titleNew: 'New',
            tabs: [$this->singleFieldTab()],
            headerInfo: new FormHeaderInfo(title: 'X'),
        );

        $cleared = $def->withHeaderInfo(null);

        $this->assertNotNull($def->headerInfo);
        $this->assertNull($cleared->headerInfo);
        $this->assertNull($cleared->toArray()['header_info']);
    }

    public function testIconIncludedInToArray(): void
    {
        $tab = new FormTab(
            id: 'basic',
            label: 'Basic',
            sections: [new FormSection([new FormColumn([])])],
            icon: 'user',
        );
        $this->assertSame('user', $tab->toArray()['icon']);
    }

    public function testLiveSummaryOmittedWhenEmpty(): void
    {
        $def = new FormDefinition(
            table: 'test',
            title: 'Test',
            titleNew: 'New',
            tabs: [$this->singleFieldTab()],
        );

        $this->assertSame([], $def->liveSummary);
        $this->assertArrayNotHasKey('live_summary', $def->toArray());
    }

    public function testLiveSummaryIncludedWhenSet(): void
    {
        $items = [
            ['label' => 'Základ', 'value' => '1 000,00'],
            ['label' => 'DPH', 'value' => '210,00'],
            ['label' => 'Celkem CZK', 'value' => '1 210,00'],
        ];
        $def = new FormDefinition(
            table: 'test',
            title: 'Test',
            titleNew: 'New',
            tabs: [$this->singleFieldTab()],
            liveSummary: $items,
        );

        $this->assertSame($items, $def->toArray()['live_summary']);
    }

    public function testWithAppendedTabsAddsTabsAtTheEndAndKeepsTheRest(): void
    {
        $section = [new FormSection([new FormColumn([new FormElement('separator')])])];
        $definition = (new FormDefinition('t', 'Title', 'New', [new FormTab('basic', 'Basic', $section)], liveSummary: [['label' => 'A', 'value' => '1']]))
            ->withDocStates(['currentState' => 10]);

        $this->assertSame($definition, $definition->withAppendedTabs([]), 'bez záložek tatáž instance');

        $extended = $definition->withAppendedTabs([new FormTab('mod.one', 'One', $section), new FormTab('mod.two', 'Two', $section)]);

        $this->assertSame(['basic', 'mod.one', 'mod.two'], array_map(static fn (FormTab $t): string => $t->id, $extended->tabs));
        $this->assertCount(1, $definition->tabs, 'původní definice se nemění');
        $this->assertSame('Title', $extended->title);
        $this->assertSame('New', $extended->titleNew);
        $this->assertSame(['currentState' => 10], $extended->docStates);
        $this->assertSame([['label' => 'A', 'value' => '1']], $extended->liveSummary);
    }
}
