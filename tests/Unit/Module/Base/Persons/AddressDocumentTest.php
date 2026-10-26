<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\AddressDocument;
use Shipard\Module\Base\Persons\Values\PersonContext;

/** Kontext bez databáze: osoba 1 je vlastní firma, ostatní partneři; země osoby 9 = sk. */
final class StubAddressContext extends PersonContext
{
    public function __construct()
    {
        parent::__construct(null, 'cz');
    }

    public function isOwn(int $personId): bool
    {
        return $personId === 1;
    }

    protected function storedCountry(int $personId): ?string
    {
        return $personId === 9 ? 'sk' : null;
    }
}

final class StubAddressDocument extends AddressDocument
{
    protected function personContext(): PersonContext
    {
        return new StubAddressContext();
    }
}

/** Adresa osoby z formuláře: země malými, PSČ podle země adresy (#118 D18). */
class AddressDocumentTest extends TestCase
{
    public function testBeforeSaveNormalizesCountryAndZip(): void
    {
        $data = ['person' => 1, 'address_type' => 1, 'city' => 'Zlín', 'zip' => ' 760 01', 'country' => 'CZ'];
        (new AddressDocument())->beforeSave($data);
        $this->assertSame(['person' => 1, 'address_type' => 1, 'city' => 'Zlín', 'zip' => '76001', 'country' => 'cz'], $data);
    }

    public function testPartialUpdateWithoutCountryUsesTheStoredRow(): void
    {
        $data = ['id' => 9, 'zip' => '760 01'];
        (new AddressDocument())->beforeSave($data, ['id' => 9, 'zip' => '76002', 'country' => 'cz']);
        $this->assertSame('76001', $data['zip']);

        $foreign = ['id' => 9, 'zip' => ' SW1A 1AA '];
        (new AddressDocument())->beforeSave($foreign, ['id' => 9, 'country' => 'gb']);
        $this->assertSame('SW1A 1AA', $foreign['zip'], 'stát bez pravidla: jen trim');
    }

    public function testInvalidZipWarnsPartnerAndBlocksOwnCompany(): void
    {
        $partner = ['person' => 2, 'address_type' => 1, 'zip' => '7790', 'country' => 'cz'];
        $result = (new StubAddressDocument())->validate($partner);
        $this->assertTrue($result->isValid());
        $this->assertSame('zip', $result->warningsToArray()[0]['column']);
        $this->assertSame('PSČ „7790“ nemá pět číslic (cz).', $result->warningsToArray()[0]['message']);

        $own = ['person' => 1, 'address_type' => 1, 'zip' => '7790', 'country' => 'cz'];
        $result = (new StubAddressDocument())->validate($own);
        $this->assertFalse($result->isValid());
        $this->assertSame('zip_invalid', $result->toArray()[0]['code']);

        $noCountry = ['person' => 1, 'address_type' => 1, 'zip' => '7790'];
        $this->assertTrue((new StubAddressDocument())->validate($noCountry)->isValid(), 'bez země se PSČ nekontroluje');
    }

    public function testWithoutAnyCountryZipIsOnlyTrimmed(): void
    {
        $data = ['zip' => ' 760 01 ', 'country' => null];
        (new AddressDocument())->beforeSave($data);
        $this->assertSame('760 01', $data['zip'], 'nic se nehádá');
    }
}
