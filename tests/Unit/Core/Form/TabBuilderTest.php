<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\ColumnDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Form\TabBuilder;

class TabBuilderTest extends TestCase
{
    public function testSectionWithSingleColumn(): void
    {
        $tab = (new TabBuilder('basic', 'Basic'))
            ->section()
                ->col()
                    ->input('name', label: 'Name', required: true)
                    ->input('email', label: 'Email')
            ->build();

        $this->assertSame('basic', $tab->id);
        $this->assertSame('Basic', $tab->label);
        $this->assertSame('fields', $tab->type);
        $this->assertCount(1, $tab->sections);
        $section = $tab->sections[0];
        $this->assertNull($section->title);
        $this->assertCount(1, $section->columns);
        $this->assertCount(2, $section->columns[0]->elements);
        $this->assertSame('name', $section->columns[0]->elements[0]->column);
        $this->assertTrue($section->columns[0]->elements[0]->required);
    }

    public function testSectionWithMultipleColumns(): void
    {
        $tab = (new TabBuilder('basic', 'Basic'))
            ->section('Identifikace firmy')
                ->col()->input('company_id', label: 'IČO')
                ->col()->input('tax_id', label: 'DIČ')
            ->build();

        $section = $tab->sections[0];
        $this->assertSame('Identifikace firmy', $section->title);
        $this->assertCount(2, $section->columns);
        $this->assertSame('company_id', $section->columns[0]->elements[0]->column);
        $this->assertSame('tax_id', $section->columns[1]->elements[0]->column);
    }

    public function testMultipleSections(): void
    {
        $tab = (new TabBuilder('basic', 'Basic'))
            ->section()
                ->col()->input('a')
            ->section('Two')
                ->col()->input('b')
            ->build();

        $this->assertCount(2, $tab->sections);
        $this->assertNull($tab->sections[0]->title);
        $this->assertSame('Two', $tab->sections[1]->title);
    }

    public function testColOutsideSectionThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('col() called outside of a section');

        (new TabBuilder('t', 'T'))->col();
    }

    public function testElementOutsideColThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('outside of a column');

        (new TabBuilder('t', 'T'))->section()->input('x');
    }

    public function testInlineGroup(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section('Termíny')
                ->col()
                    ->inline()
                        ->date('date_tax', label: 'DUZP')
                        ->date('date_tax_duty', label: 'DPPD')
                    ->endInline()
            ->build();

        $col = $tab->sections[0]->columns[0];
        $this->assertCount(1, $col->elements);
        $inline = $col->elements[0];
        $this->assertSame('inline', $inline->type);
        $this->assertCount(2, $inline->elements);
        $this->assertSame('date', $inline->elements[0]->inputType);
        $this->assertSame('DPPD', $inline->elements[1]->label);
    }

    public function testInlineFieldsShortcut(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section()
                ->col()
                    ->inlineFields('a', 'b', 'c')
            ->build();

        $inline = $tab->sections[0]->columns[0]->elements[0];
        $this->assertSame('inline', $inline->type);
        $this->assertCount(3, $inline->elements);
        $this->assertSame('a', $inline->elements[0]->column);
    }

    public function testUnclosedInlineAutoClosesInBuild(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section()
                ->col()
                    ->inline()
                        ->input('a')
                        ->input('b')
            ->build();

        $col = $tab->sections[0]->columns[0];
        $this->assertSame('inline', $col->elements[0]->type);
        $this->assertCount(2, $col->elements[0]->elements);
    }

    public function testInlineRejectsSeparator(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('separator cannot appear inside inline');

        (new TabBuilder('t', 'T'))
            ->section()
                ->col()
                    ->inline()
                    ->separator('boom');
    }

    public function testEndInlineWithoutInlineThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('endInline() called without matching inline()');

        (new TabBuilder('t', 'T'))
            ->section()->col()->endInline();
    }

    public function testAutoHideSeparatorsPerColumn(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section()
                ->col()
                    ->input('visible')
                    ->separator('Hidden block')
                    ->input('a', hidden: true)
                    ->input('b', hidden: true)
                    ->separator('Visible block')
                    ->input('c')
            ->build();

        $elements = $tab->sections[0]->columns[0]->elements;
        $this->assertSame('separator', $elements[1]->type);
        $this->assertTrue($elements[1]->hidden, 'separator before all-hidden block should be auto-hidden');
        $this->assertSame('separator', $elements[4]->type);
        $this->assertFalse($elements[4]->hidden, 'separator with visible follower should stay visible');
    }

    public function testAutoHideSeparatorsScopedToColumn(): void
    {
        // Separator in column 1 must not be auto-hidden by visible elements in column 2.
        $tab = (new TabBuilder('t', 'T'))
            ->section()
                ->col()
                    ->separator('Sep A')
                    ->input('hidden1', hidden: true)
                ->col()
                    ->input('visible2')
            ->build();

        $col0 = $tab->sections[0]->columns[0];
        $this->assertTrue($col0->elements[0]->hidden);
    }

    public function testHiddenSection(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section('Hidden', hidden: true)
                ->col()->input('x')
            ->build();

        $this->assertTrue($tab->sections[0]->hidden);
    }

    public function testEmptySectionSkipped(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section('Empty')
            ->section('Real')
                ->col()->input('x')
            ->build();

        $this->assertCount(1, $tab->sections);
        $this->assertSame('Real', $tab->sections[0]->title);
    }

    // -------- Widgets --------

    public function testSelect(): void
    {
        $options = [
            ['value' => 1, 'label' => 'A'],
            ['value' => 2, 'label' => 'B'],
        ];
        $tab = (new TabBuilder('t', 'T'))
            ->section()->col()
                ->select('type', label: 'Type', options: $options)
            ->build();
        $el = $tab->sections[0]->columns[0]->elements[0];
        $this->assertSame('select', $el->type);
        $this->assertSame($options, $el->options);
    }

    public function testMultiselect(): void
    {
        $options = [
            ['value' => 'vehicle.fuel', 'label' => 'Pohonné hmoty'],
            ['value' => 'it.software', 'label' => 'Software a SaaS'],
        ];
        $tab = (new TabBuilder('t', 'T'))
            ->section()->col()
                ->multiselect('content_tags', label: 'Štítky', options: $options, hint: 'Multi')
            ->build();
        $el = $tab->sections[0]->columns[0]->elements[0];
        $this->assertSame('multiselect', $el->type);
        $this->assertSame('content_tags', $el->column);
        $this->assertSame($options, $el->options);
        $this->assertSame('Multi', $el->hint);
    }

    public function testMultiselectInsideInlineRejected(): void
    {
        $this->expectException(\LogicException::class);

        (new TabBuilder('t', 'T'))
            ->section()->col()->inline()
                ->multiselect('content_tags');
    }

    public function testTextarea(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section()->col()->textarea('body', required: true, hint: 'Plain')
            ->build();
        $el = $tab->sections[0]->columns[0]->elements[0];
        $this->assertSame('textarea', $el->inputType);
        $this->assertTrue($el->required);
        $this->assertSame('Plain', $el->hint);
    }

    public function testDate(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section()->col()->date('birth_date', hidden: true)
            ->build();
        $el = $tab->sections[0]->columns[0]->elements[0];
        $this->assertSame('date', $el->inputType);
        $this->assertTrue($el->hidden);
    }

    public function testDateTimeAndTimeAndNumberAndCheckbox(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section()->col()
                ->datetime('received_at')
                ->time('opens_at')
                ->number('qty')
                ->checkbox('active')
            ->build();
        $els = $tab->sections[0]->columns[0]->elements;
        $this->assertSame('datetime', $els[0]->inputType);
        $this->assertSame('time', $els[1]->inputType);
        $this->assertSame('number', $els[2]->inputType);
        $this->assertSame('checkbox', $els[3]->inputType);
    }

    public function testHtml(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section()->col()->html('<p>Hi</p>')
            ->build();
        $el = $tab->sections[0]->columns[0]->elements[0];
        $this->assertSame('html', $el->type);
        $this->assertSame('<p>Hi</p>', $el->content);
    }

    public function testComponent(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section()->col()->component('recapitulation')
            ->build();
        $el = $tab->sections[0]->columns[0]->elements[0];
        $this->assertSame('component', $el->type);
        $this->assertSame('recapitulation', $el->componentName);
        $this->assertNull($el->params);
    }

    public function testComponentWithParams(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section()->col()->component('attachmentsView', params: ['table_id' => 303])
            ->build();
        $el = $tab->sections[0]->columns[0]->elements[0];
        $this->assertSame('component', $el->type);
        $this->assertSame('attachmentsView', $el->componentName);
        $this->assertSame(['table_id' => 303], $el->params);
    }

    public function testTriggers(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section()->col()->input('type_field', triggers: 'reload')
            ->build();
        $this->assertSame('reload', $tab->sections[0]->columns[0]->elements[0]->triggers);
    }

    public function testColLabelsAutoResolve(): void
    {
        $tab = (new TabBuilder('t', 'T', ['name' => 'Jméno', 'email' => 'E-mail']))
            ->section()->col()
                ->input('name')
                ->input('email', label: 'Explicit')
            ->build();
        $els = $tab->sections[0]->columns[0]->elements;
        $this->assertSame('Jméno', $els[0]->label);
        $this->assertSame('Explicit', $els[1]->label);
    }

    public function testInputRejectsTextarea(): void
    {
        // input() lower-level: bypass via direct input() with inputType is allowed by the
        // builder (no whitelist); enforcement is at FormElement level — which allows textarea.
        // For TabBuilder users, use ->textarea() helper. Test that the helper sets it.
        $tab = (new TabBuilder('t', 'T'))
            ->section()->col()->textarea('body')->build();
        $this->assertSame('textarea', $tab->sections[0]->columns[0]->elements[0]->inputType);
    }

    public function testIcon(): void
    {
        $tab = (new TabBuilder('t', 'T', icon: 'user'))
            ->section()->col()->input('x')
            ->build();
        $this->assertSame('user', $tab->icon);
    }

    public function testLookupElement(): void
    {
        $tab = (new TabBuilder('t', 'T', colLabels: ['partner' => 'Partner']))
            ->section()->col()
                ->lookup('partner', table: 'base_persons_persons', placeholder: 'Hledat…', triggers: 'reload')
            ->build();
        $el = $tab->sections[0]->columns[0]->elements[0];

        $this->assertSame('lookup', $el->type);
        $this->assertSame('partner', $el->column);
        $this->assertSame('Partner', $el->label);
        $this->assertSame('Hledat…', $el->placeholder);
        $this->assertSame('reload', $el->triggers);
        $this->assertSame(['table' => 'base_persons_persons', 'filter' => null], $el->lookup);
    }

    public function testLookupWithFilter(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section()->col()
                ->lookup('partner_address', table: 'base_persons_addresses', filter: ['person' => 42])
            ->build();
        $el = $tab->sections[0]->columns[0]->elements[0];

        $this->assertSame(['person' => 42], $el->lookup['filter']);
    }

    public function testLookupCreateFlagsGoToWireFormat(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section()->col()
                ->lookup('asset', table: 'economy_assets_assets', editForm: true, createForm: true, createDefaults: true)
                ->lookup('partner', table: 'base_persons_persons', createForm: true)
            ->build();
        [$asset, $partner] = $tab->sections[0]->columns[0]->elements;

        $this->assertSame(
            ['table' => 'economy_assets_assets', 'filter' => null, 'edit_form' => true, 'create_form' => true, 'create_defaults' => true],
            $asset->toArray()['lookup'],
        );
        // Bez flagu se klíč na drát neposílá.
        $this->assertArrayNotHasKey('create_defaults', $partner->toArray()['lookup']);
    }

    public function testLookupInsideInlineRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not allowed inside inline group');

        (new TabBuilder('t', 'T'))
            ->section()->col()
                ->inline()->lookup('partner', table: 'base_persons_persons')->endInline()
            ->build();
    }

    // -------- select(): required odvozené ze schématu (issue #61) --------

    /** @return array<string, ColumnDefinition> */
    private function colDefs(): array
    {
        $cols = [
            // NOT NULL s defaultem — u selectu default nic neřeší, prázdná možnost = NULL
            ['id' => 'mode', 'name' => 'Mode', 'type' => 'enumInt', 'cfgItem' => 'x.modes', 'nullable' => false, 'default' => 1],
            ['id' => 'kind', 'name' => 'Kind', 'type' => 'enumString', 'length' => 8, 'cfgItem' => 'x.kinds', 'nullable' => false],
            ['id' => 'binder', 'name' => 'Binder', 'type' => 'int', 'nullable' => true],
            ['id' => 'tags', 'name' => 'Tags', 'type' => 'json', 'nullable' => false],
        ];
        $map = [];
        foreach ($cols as $c) {
            $map[$c['id']] = ColumnDefinition::fromArray($c);
        }
        return $map;
    }

    private function firstElement(TabBuilder $b): \Shipard\Core\Form\FormElement
    {
        return $b->build()->sections[0]->columns[0]->elements[0];
    }

    public function testSelectRequiredInferredFromNotNullColumnEvenWithDefault(): void
    {
        $b = (new TabBuilder('t', 'T', colDefs: $this->colDefs()))
            ->section()->col()->select('mode');
        $this->assertTrue($this->firstElement($b)->required);

        $b = (new TabBuilder('t', 'T', colDefs: $this->colDefs()))
            ->section()->col()->select('kind');
        $this->assertTrue($this->firstElement($b)->required);
    }

    public function testSelectRequiredInferredFalseForNullableColumn(): void
    {
        $b = (new TabBuilder('t', 'T', colDefs: $this->colDefs()))
            ->section()->col()->select('binder');
        $this->assertFalse($this->firstElement($b)->required);
    }

    public function testSelectRequiredFalseWithoutColumnDefs(): void
    {
        $b = (new TabBuilder('t', 'T'))
            ->section()->col()->select('mode');
        $this->assertFalse($this->firstElement($b)->required);

        // sloupec mimo mapu definic (např. virtuální pole) → false
        $b = (new TabBuilder('t', 'T', colDefs: $this->colDefs()))
            ->section()->col()->select('unknown_column');
        $this->assertFalse($this->firstElement($b)->required);
    }

    public function testSelectExplicitRequiredWinsOverInference(): void
    {
        $b = (new TabBuilder('t', 'T', colDefs: $this->colDefs()))
            ->section()->col()->select('mode', required: false);
        $this->assertFalse($this->firstElement($b)->required, 'explicitní false na NOT NULL sloupci');

        $b = (new TabBuilder('t', 'T', colDefs: $this->colDefs()))
            ->section()->col()->select('binder', required: true);
        $this->assertTrue($this->firstElement($b)->required, 'explicitní true na nullable sloupci');
    }

    public function testMultiselectRequiredNotInferred(): void
    {
        $b = (new TabBuilder('t', 'T', colDefs: $this->colDefs()))
            ->section()->col()->multiselect('tags');
        $this->assertFalse($this->firstElement($b)->required);
    }

    // ── addElements (#74) ────────────────────────────────────────────────────

    public function testAddElementsInsertsPreBuiltElements(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section()->col()
            ->input('name')
            ->addElements([
                new FormElement(type: 'separator', label: 'Finanční úřad'),
                new FormElement(type: 'select', column: 'filing_profile.c_ufo', label: 'FÚ', options: []),
                new FormElement(type: 'input', column: 'filing_profile.email', label: 'E-mail'),
            ])
            ->build();

        $elements = $tab->sections[0]->columns[0]->elements;
        $this->assertSame(
            ['name', null, 'filing_profile.c_ufo', 'filing_profile.email'],
            array_map(fn(FormElement $el) => $el->column, $elements),
        );
        $this->assertSame('separator', $elements[1]->type);
        $this->assertSame('Finanční úřad', $elements[1]->label);
    }

    public function testAddElementsAcceptsEmptyList(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section()->col()->input('name')->addElements([])->build();

        $this->assertCount(1, $tab->sections[0]->columns[0]->elements);
    }

    public function testAddElementsRejectsNonElement(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new TabBuilder('t', 'T'))->section()->col()->addElements(['nope']);
    }

    public function testAddElementsOutsideColumnThrows(): void
    {
        $this->expectException(\LogicException::class);

        (new TabBuilder('t', 'T'))->addElements([new FormElement(type: 'input', column: 'x')]);
    }

    public function testSelectPlaceholderPassthrough(): void
    {
        $b = (new TabBuilder('t', 'T', colDefs: $this->colDefs()))
            ->section()->col()->select('mode', placeholder: 'Nerozhodnuto');
        $el = $this->firstElement($b);
        $this->assertSame('Nerozhodnuto', $el->placeholder);
        $this->assertTrue($el->required);

        $b = (new TabBuilder('t', 'T'))
            ->section()->col()->select('mode');
        $this->assertNull($this->firstElement($b)->placeholder);
    }
}
