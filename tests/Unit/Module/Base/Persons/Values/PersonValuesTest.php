<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons\Values;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\Values\PersonValues;

/** Kanonický tvar údajů adresáře nad názvy sloupců (#118 D18, D20). */
class PersonValuesTest extends TestCase
{
    public function testPersonColumnsFollowTheCountry(): void
    {
        $data = [
            'full_name' => ' Acme s.r.o. ', 'company_id' => '6947', 'vat_id' => 'cz 0000 6947', 'tax_id' => '00006947',
            'phone' => '577 123 456', 'email' => ' Odbyt@Acme.Example ', 'web' => 'https://www.acme.example/',
        ];
        $this->assertSame([
            'full_name' => ' Acme s.r.o. ', 'company_id' => '00006947', 'vat_id' => 'CZ00006947', 'tax_id' => 'CZ00006947',
            'phone' => '+420577123456', 'email' => 'odbyt@acme.example', 'web' => 'www.acme.example',
        ], PersonValues::normalizePerson($data, 'cz'), 'jiné sloupce beze změny');

        $this->assertSame(
            ['company_id' => 'HRB 12345', 'phone' => '+4930123456'],
            PersonValues::normalizePerson(['company_id' => ' HRB 12345 ', 'phone' => '030 / 12 34 56'], 'de'),
        );
        $this->assertSame(
            ['company_id' => '6947', 'phone' => '577123456'],
            PersonValues::normalizePerson(['company_id' => '6947', 'phone' => '577 123 456'], null),
            'bez země jen obecná pravidla — nic se nehádá',
        );
    }

    public function testOnlyPresentNonEmptyKeysChange(): void
    {
        $data = ['company_id' => null, 'phone' => '', 'email' => '  ', 'vat_id' => 'cz6947'];
        $this->assertSame(['company_id' => null, 'phone' => '', 'email' => '  ', 'vat_id' => 'CZ6947'], PersonValues::normalizePerson($data, 'cz'));
        $this->assertSame(['phone' => '+420577123456'], PersonValues::normalizePerson(['phone' => '577123456'], 'cz'), 'částečný update');
    }

    public function testAddressZipFollowsItsOwnCountryThenStoredCountry(): void
    {
        $this->assertSame(
            ['zip' => '76001', 'country' => 'cz', 'city' => 'Zlín'],
            PersonValues::normalizeAddress(['zip' => '760 01', 'country' => ' CZ', 'city' => 'Zlín']),
        );
        $this->assertSame(
            ['zip' => '76001'],
            PersonValues::normalizeAddress(['zip' => '760 01'], 'cz'),
            'bez země v řádku podle uloženého řádku',
        );
        $this->assertSame(
            ['zip' => '76001', 'country' => ''],
            PersonValues::normalizeAddress(['zip' => '760 01', 'country' => ''], 'cz'),
            'prázdná země v řádku = chybí, platí uložený řádek',
        );
        $this->assertSame(
            ['zip' => '760 01'],
            PersonValues::normalizeAddress(['zip' => ' 760 01 ']),
            'bez země jen trim',
        );
        $this->assertSame(
            ['zip' => 'SW1A 1AA', 'country' => 'gb'],
            PersonValues::normalizeAddress(['zip' => ' SW1A 1AA ', 'country' => 'GB'], 'cz'),
            'země řádku má přednost před uloženou',
        );
        $this->assertSame(['zip' => null, 'country' => null], PersonValues::normalizeAddress(['zip' => null, 'country' => null], 'cz'));
    }

    public function testContactColumns(): void
    {
        $this->assertSame(
            ['name' => 'Jana', 'email' => 'jana@acme.example', 'phone' => '+421905123456'],
            PersonValues::normalizeContact(['name' => 'Jana', 'email' => ' Jana@Acme.Example', 'phone' => '0905 123 456'], 'sk'),
        );
    }

    public function testBankAccountMovesIbanFromAccountNumber(): void
    {
        $this->assertSame(
            ['person' => 5, 'account_number' => '19-2000145399/0800', 'iban' => 'CZ6508000000192000145399', 'bic' => 'GIBACZPX'],
            PersonValues::normalizeBankAccount(['person' => 5, 'account_number' => 'CZ65 0800 0000 1920 0014 5399', 'iban' => null, 'bic' => 'gibaczpx']),
        );
    }
}
