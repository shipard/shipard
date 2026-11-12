<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\PersonsForm;
use Shipard\Module\Base\Persons\PersonType;

class PersonsFormHeaderInfoTest extends TestCase
{
    private function createForm(): PersonsForm
    {
        return new PersonsForm('base_persons_persons');
    }

    public function testEmptyFullNameReturnsNull(): void
    {
        $form = $this->createForm();

        $this->assertNull($form->buildHeaderInfo([
            'person_type' => PersonType::Company->value,
            'full_name'   => '',
            'company_id'  => '68253848',
        ]));
    }

    public function testWhitespaceOnlyFullNameReturnsNull(): void
    {
        $form = $this->createForm();

        $this->assertNull($form->buildHeaderInfo([
            'person_type' => PersonType::Company->value,
            'full_name'   => '   ',
        ]));
    }

    public function testUndefinedPersonTypeReturnsNull(): void
    {
        $form = $this->createForm();

        $this->assertNull($form->buildHeaderInfo([
            'person_type' => PersonType::Undefined->value,
            'full_name'   => 'Něco',
            'person_id'   => 'X-001',
        ]));
    }

    public function testMissingPersonTypeReturnsNull(): void
    {
        $form = $this->createForm();

        $this->assertNull($form->buildHeaderInfo([
            'full_name' => 'Něco',
            'person_id' => 'X-001',
        ]));
    }

    public function testCompanyWithAllFields(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'person_type' => PersonType::Company->value,
            'full_name'   => 'Beta Software, a.s.',
            'company_id'  => '68253848',
            'person_id'   => 'TEST-0098',
        ]);

        $this->assertNotNull($info);
        $this->assertSame('Beta Software, a.s.', $info->title);
        $this->assertSame(
            [
                ['label' => 'IČO',       'value' => '68253848'],
                ['label' => 'Kód osoby', 'value' => 'TEST-0098'],
            ],
            $info->info,
        );
        $this->assertSame('company', $info->icon);
        $this->assertSame([], $info->summary);
    }

    public function testCompanyWithoutCompanyId(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'person_type' => PersonType::Company->value,
            'full_name'   => 'Bez IČO, s.r.o.',
            'company_id'  => '',
            'person_id'   => 'TEST-0099',
        ]);

        $this->assertNotNull($info);
        $this->assertSame('Bez IČO, s.r.o.', $info->title);
        $this->assertSame(
            [['label' => 'Kód osoby', 'value' => 'TEST-0099']],
            $info->info,
        );
    }

    public function testPersonWithBirthDate(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'person_type' => PersonType::Person->value,
            'full_name'   => 'Jan Novák',
            'birth_date'  => '1990-05-14',
            'person_id'   => 'TEST-0001',
        ]);

        $this->assertNotNull($info);
        $this->assertSame('Jan Novák', $info->title);
        $this->assertSame(
            [
                ['label' => 'Datum narození', 'value' => '1990-05-14'],
                ['label' => 'Kód osoby',      'value' => 'TEST-0001'],
            ],
            $info->info,
        );
        $this->assertSame('user', $info->icon);
        $this->assertSame([], $info->summary);
    }

    public function testPersonWithoutBirthDate(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'person_type' => PersonType::Person->value,
            'full_name'   => 'Jan Novák',
            'birth_date'  => null,
            'person_id'   => 'TEST-0001',
        ]);

        $this->assertNotNull($info);
        $this->assertSame(
            [['label' => 'Kód osoby', 'value' => 'TEST-0001']],
            $info->info,
        );
    }

    public function testPersonWithEmptyBirthDate(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'person_type' => PersonType::Person->value,
            'full_name'   => 'Jan Novák',
            'birth_date'  => '',
            'person_id'   => 'TEST-0001',
        ]);

        $this->assertNotNull($info);
        $this->assertSame(
            [['label' => 'Kód osoby', 'value' => 'TEST-0001']],
            $info->info,
        );
    }

    public function testPersonWithMalformedBirthDateKeepsValue(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'person_type' => PersonType::Person->value,
            'full_name'   => 'Jan Novák',
            'birth_date'  => 'not-a-date',
            'person_id'   => 'TEST-0001',
        ]);

        // Hodnota, která datem není, se vrací beze změny (DisplayDate, #133 D73).
        $this->assertNotNull($info);
        $this->assertSame(
            [['label' => 'Datum narození', 'value' => 'not-a-date'], ['label' => 'Kód osoby', 'value' => 'TEST-0001']],
            $info->info,
        );
    }

    public function testPersonWithoutPersonId(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'person_type' => PersonType::Person->value,
            'full_name'   => 'Jan Novák',
            'birth_date'  => '1990-05-14',
            'person_id'   => '',
        ]);

        $this->assertNotNull($info);
        $this->assertSame(
            [['label' => 'Datum narození', 'value' => '1990-05-14']],
            $info->info,
        );
    }

    public function testDefaultTableFormReturnsNull(): void
    {
        $form = new class('any_table') extends \Shipard\Core\Form\TableForm {
            public function buildFormDefinition(array $data, bool $isNew): \Shipard\Core\Form\FormDefinition
            {
                throw new \LogicException('not used');
            }
        };

        $this->assertNull($form->buildHeaderInfo(['anything' => 'goes']));
    }
}
