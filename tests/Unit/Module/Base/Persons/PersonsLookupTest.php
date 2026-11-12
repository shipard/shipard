<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\PersonsLookup;

class PersonsLookupTest extends TestCase
{
    public function testGetAllowedFilterKeysEmpty(): void
    {
        $this->assertSame([], (new PersonsLookup())->getAllowedFilterKeys());
    }

    public function testCompanyBuildsIcoSecondary(): void
    {
        $item = PersonsLookup::buildItem([
            'id'          => 42,
            'full_name'   => 'Testování 999',
            'person_type' => 2,
            'company_id'  => '12345678',
            'birth_date'  => null,
            'person_id'   => 'P000042',
        ]);

        $this->assertSame(42, $item->id);
        $this->assertSame('Testování 999', $item->primary);
        $this->assertSame('IČO 12345678', $item->secondary);
    }

    public function testPersonBuildsBirthDateSecondary(): void
    {
        $item = PersonsLookup::buildItem([
            'id'          => 7,
            'full_name'   => 'Jan Novák',
            'person_type' => 1,
            'company_id'  => null,
            'birth_date'  => '1990-05-14',
            'person_id'   => 'P000007',
        ]);

        $this->assertSame('Jan Novák', $item->primary);
        $this->assertSame('Datum narození 1990-05-14', $item->secondary);
    }

    public function testPersonWithoutBirthDateHasNoSecondary(): void
    {
        $item = PersonsLookup::buildItem([
            'id'          => 7,
            'full_name'   => 'Jan Novák',
            'person_type' => 1,
            'company_id'  => null,
            'birth_date'  => null,
            'person_id'   => 'P000007',
        ]);

        $this->assertNull($item->secondary);
    }

    public function testCompanyWithoutCompanyIdHasNoSecondary(): void
    {
        $item = PersonsLookup::buildItem([
            'id'          => 9,
            'full_name'   => 'Acme s.r.o.',
            'person_type' => 2,
            'company_id'  => null,
            'birth_date'  => null,
            'person_id'   => 'P000009',
        ]);

        $this->assertNull($item->secondary);
    }

    public function testUndefinedPersonTypeHasNoSecondary(): void
    {
        $item = PersonsLookup::buildItem([
            'id'          => 1,
            'full_name'   => 'Anon',
            'person_type' => 0,
            'company_id'  => '99999999',
            'birth_date'  => '2000-01-01',
            'person_id'   => 'P000001',
        ]);

        $this->assertNull($item->secondary);
    }

    public function testEmptyFullNameFallsBackToIdMarker(): void
    {
        $item = PersonsLookup::buildItem([
            'id'          => 13,
            'full_name'   => '',
            'person_type' => 0,
            'company_id'  => null,
            'birth_date'  => null,
            'person_id'   => 'P000013',
        ]);

        $this->assertSame('#13', $item->primary);
    }

    public function testInvalidBirthDateKeepsValue(): void
    {
        $item = PersonsLookup::buildItem([
            'id'          => 5,
            'full_name'   => 'Jan',
            'person_type' => 1,
            'company_id'  => null,
            'birth_date'  => 'not-a-date',
            'person_id'   => 'P000005',
        ]);

        // Hodnota, která datem není, se vrací beze změny (DisplayDate, #133 D73).
        $this->assertSame('Datum narození not-a-date', $item->secondary);
    }

    public function testResolveOnNullDbReturnsEmpty(): void
    {
        // Without setDb the connection is null — should not crash and just return [].
        $this->assertSame([], (new PersonsLookup())->resolve([1, 2, 3]));
    }

    public function testSearchOnNullDbReturnsEmpty(): void
    {
        $this->assertSame([], (new PersonsLookup())->search('foo', [], 10));
    }
}
