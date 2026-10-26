<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\AddressDocument;
use Shipard\Module\Base\Persons\Values\PersonContext;

/**
 * Kontext bez databáze: osoba 1 je vlastní firma, ostatní partneři; země
 * osoby 9 = sk, osoby 8 = gb; uložená adresa 5 má zemi gb.
 */
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

    public function addressCountry(int $addressId): ?string
    {
        return $addressId === 5 ? 'gb' : null;
    }

    protected function storedCountry(int $personId): ?string
    {
        return match ($personId) {
            9 => 'sk',
            8 => 'gb',
            default => null,
        };
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

        // D24: adresa bez země podle země osoby — vlastní firma (zdroj dat cz) chyba, partner varování.
        $noCountry = ['person' => 1, 'address_type' => 1, 'zip' => '7790'];
        $result = (new StubAddressDocument())->validate($noCountry);
        $this->assertFalse($result->isValid(), 'bez země podle země osoby (D24)');
        $this->assertSame('zip_invalid', $result->toArray()[0]['code']);

        $partner = ['person' => 9, 'address_type' => 1, 'zip' => '7790', 'country' => ''];
        $result = (new StubAddressDocument())->validate($partner);
        $this->assertTrue($result->isValid());
        $this->assertSame('PSČ „7790“ nemá pět číslic (sk).', $result->warningsToArray()[0]['message'], 'země osoby z uložených adres');

        $british = ['person' => 8, 'zip' => 'SW1A 1AA'];
        $this->assertSame([], (new StubAddressDocument())->validate($british)->warningsToArray(), 'země osoby bez pravidla PSČ');
    }

    public function testUpdateWithoutCountryKeyIsCheckedAgainstTheStoredRow(): void
    {
        $stored = ['id' => 5, 'person' => 2, 'zip' => 'SW1A 1AA'];
        $this->assertSame([], (new StubAddressDocument())->validate($stored)->warningsToArray(), 'uložený řádek gb má přednost před zemí osoby');

        $unknown = ['id' => 6, 'person' => 2, 'zip' => 'SW1A 1AA'];
        $this->assertSame('zip_invalid', (new StubAddressDocument())->validate($unknown)->warningsToArray()[0]['code'], 'bez uložené země platí země osoby');
    }

    public function testWithoutAnyCountryZipFollowsThePersonCountry(): void
    {
        $data = ['person' => 2, 'zip' => ' 760 01 ', 'country' => null];
        (new AddressDocument())->beforeSave($data);
        $this->assertSame('76001', $data['zip'], 'bez databáze země zdroje dat (cz)');
        $this->assertNull($data['country'], 'země se nedoplňuje');

        $foreign = ['person' => 8, 'zip' => ' SW1A 1AA ', 'country' => null];
        (new StubAddressDocument())->beforeSave($foreign);
        $this->assertSame('SW1A 1AA', $foreign['zip'], 'země osoby gb: jen trim');

        $update = ['id' => 7, 'zip' => ' 811 01 '];
        (new StubAddressDocument())->beforeSave($update, ['id' => 7, 'person' => 9, 'country' => '']);
        $this->assertSame('81101', $update['zip'], 'update bez země v payloadu i v DB: osoba z uloženého řádku');
    }
}
