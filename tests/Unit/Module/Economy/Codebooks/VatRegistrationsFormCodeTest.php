<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\FormElement;
use Shipard\Module\Economy\Codebooks\VatRegistrationsForm;

/** Pole Kód registrace ve formuláři registrace DPH (#166 D17, U6). */
class VatRegistrationsFormCodeTest extends TestCase
{
    use VatRegistrationDbTrait;

    /** @param list<array<string, mixed>> $registrations */
    private function form(array $registrations = []): VatRegistrationsForm
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getDibiConnection')->willReturn($this->registrationsDb($registrations));

        $form = new VatRegistrationsForm('economy_codebooks_vat_registrations');
        $form->setDb($db);
        return $form;
    }

    private function element(VatRegistrationsForm $form, string $columnId, bool $isNew): FormElement
    {
        $first = $form->buildFormDefinition($isNew ? [] : ['id' => 1], $isNew)->tabs[0]->sections[0];
        foreach ($first->columns as $column) {
            foreach ($column->elements as $element) {
                if ($element->column === $columnId) {
                    return $element;
                }
            }
        }
        $this->fail("Pole {$columnId} v první sekci formuláře není");
    }

    public function testCodeFieldSitsNextToTheNameWithAHint(): void
    {
        $form = $this->form();
        $elements = $form->buildFormDefinition([], true)->tabs[0]->sections[0]->columns[0]->elements;

        $this->assertSame(['name', 'code'], [$elements[0]->column, $elements[1]->column]);
        $this->assertSame('input', $elements[1]->type);
        $this->assertFalse($elements[1]->required, 'prázdný kód doplní Document');
        $this->assertNotSame('', (string) $elements[1]->hint);
    }

    public function testCodeIsEditableInReadOnlyState(): void
    {
        $this->assertSame(['tax_office_person', 'code'], $this->form()->getReadOnlyEditableColumns());
    }

    public function testNewRegistrationGetsSuggestedCode(): void
    {
        $data = [];
        $this->form()->applyNewRecordDefaults($data);
        $this->assertSame('cz', $data['code'], 'výchozí země formuláře');

        $data = ['country' => 'sk'];
        $this->form()->applyNewRecordDefaults($data);
        $this->assertSame('sk', $data['code'], 'země z průvodce nastavením');

        $data = [];
        $this->form([['id' => 1, 'country' => 'cz', 'code' => 'cz', 'docState' => 40]])->applyNewRecordDefaults($data);
        $this->assertSame('cz-2', $data['code'], 'druhá registrace země');

        $data = ['code' => 'hlavni'];
        $this->form()->applyNewRecordDefaults($data);
        $this->assertSame('hlavni', $data['code'], 'vyplněný kód se nepřepisuje');
    }

    public function testChangingCountrySuggestsTheCodeAgain(): void
    {
        $form = $this->form();

        $data = $form->recalculate('country', ['country' => 'sk', 'code' => 'cz'])->data;
        $this->assertSame('sk', $data['code']);

        $data = $form->recalculate('region', ['country' => 'sk', 'code' => 'moje'])->data;
        $this->assertSame('moje', $data['code'], 'jiný sloupec kód nemění');

        $data = $form->recalculate('country', ['id' => 1, 'country' => 'sk', 'code' => 'cz'])->data;
        $this->assertSame('cz', $data['code'], 'existující registrace si kód drží');
    }

    public function testCountryReloadsTheFormOnlyForNewRegistration(): void
    {
        $this->assertSame('reload', $this->element($this->form(), 'country', true)->triggers);
        $this->assertNull($this->element($this->form(), 'country', false)->triggers);
    }
}
