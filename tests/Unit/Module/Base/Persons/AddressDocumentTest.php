<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\AddressDocument;

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

    public function testWithoutAnyCountryZipIsOnlyTrimmed(): void
    {
        $data = ['zip' => ' 760 01 ', 'country' => null];
        (new AddressDocument())->beforeSave($data);
        $this->assertSame('760 01', $data['zip'], 'nic se nehádá');
    }
}
