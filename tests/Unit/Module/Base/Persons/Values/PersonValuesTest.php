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

    public function testAddressZipFollowsItsOwnCountryThenStoredCountryThenPersonCountry(): void
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
            'bez jakékoli země jen trim',
        );
        $this->assertSame(
            ['zip' => 'SW1A 1AA', 'country' => 'gb'],
            PersonValues::normalizeAddress(['zip' => ' SW1A 1AA ', 'country' => 'GB'], 'cz'),
            'země řádku má přednost před uloženou',
        );
        $this->assertSame(['zip' => null, 'country' => null], PersonValues::normalizeAddress(['zip' => null, 'country' => null], 'cz'));

        // D24: adresa bez země (v řádku i v DB) podle země osoby; `country` se nedoplňuje.
        $this->assertSame(
            ['zip' => '76001', 'country' => ''],
            PersonValues::normalizeAddress(['zip' => ' 760 01 ', 'country' => ''], null, 'CZ'),
            'bez země řádku i uloženého řádku podle země osoby (D24)',
        );
        $this->assertSame(
            ['zip' => '760 01'],
            PersonValues::normalizeAddress(['zip' => ' 760 01 '], 'gb', 'cz'),
            'uložený řádek má přednost před zemí osoby',
        );
        $this->assertSame(
            ['zip' => 'SW1A 1AA'],
            PersonValues::normalizeAddress(['zip' => ' SW1A 1AA '], null, 'gb'),
            'země osoby bez pravidla: jen trim',
        );
    }

    public function testContactColumns(): void
    {
        $this->assertSame(
            ['name' => 'Jana', 'email' => 'jana@acme.example', 'phone' => '+421905123456'],
            PersonValues::normalizeContact(['name' => 'Jana', 'email' => ' Jana@Acme.Example', 'phone' => '0905 123 456'], 'sk'),
        );
    }

    public function testChecksMapFindingsToColumns(): void
    {
        $this->assertSame([], PersonValues::checkPerson(['company_id' => '6947', 'vat_id' => 'cz 0000 6947'], 'cz'), 'kontrola nad normalizovaným tvarem');
        $this->assertSame(
            [['column' => 'company_id', 'code' => 'company_id_invalid', 'message' => 'IČO „52654151“ nemá platný kontrolní součet.']],
            PersonValues::checkPerson(['company_id' => '52654151'], 'cz'),
        );
        $this->assertSame(
            [['column' => 'vat_id', 'code' => 'vat_id_company_id_mismatch', 'message' => 'DIČ „CZ07123451“ neodpovídá IČO „52654150“ — osmimístné DIČ je CZ + IČO.']],
            PersonValues::checkPerson(['company_id' => '52654150', 'vat_id' => 'CZ07123451'], 'cz'),
        );
        $this->assertSame([], PersonValues::checkPerson(['company_id' => '52654151'], 'de'), 'zahraniční IČO bez pravidla');

        $this->assertSame(
            [['column' => 'zip', 'code' => 'zip_invalid', 'message' => 'PSČ „7790“ nemá pět číslic (cz).']],
            PersonValues::checkAddress(['zip' => '7790', 'country' => 'CZ']),
        );
        $this->assertSame([], PersonValues::checkAddress(['zip' => '760 01', 'country' => 'cz']), 'mezera se normalizuje, ne hlásí');
        $this->assertSame([], PersonValues::checkAddress(['zip' => '7790']), 'adresa bez jakékoli země nález nemá');
        $this->assertSame(1, count(PersonValues::checkAddress(['zip' => '7790'], 'cz')), 'země z uloženého řádku');
        $this->assertSame(
            [['column' => 'zip', 'code' => 'zip_invalid', 'message' => 'PSČ „7790“ nemá pět číslic (cz).']],
            PersonValues::checkAddress(['zip' => '7790'], null, 'cz'),
            'adresa bez země podle země osoby (D24)',
        );
        $this->assertSame([], PersonValues::checkAddress(['zip' => '7790'], 'gb', 'cz'), 'uložený řádek má přednost před zemí osoby');

        $this->assertSame(
            ['account_number', 'iban', 'bic'],
            array_column(PersonValues::checkBankAccount(['account_number' => '1/0100', 'iban' => 'CZ1', 'bic' => 'X'], 'cz'), 'column'),
        );
        $this->assertSame([], PersonValues::checkBankAccount(['account_number' => '532013000', 'iban' => 'DE72100700000532013000'], 'de'));
        $this->assertSame(
            ['iban_account_mismatch'],
            array_column(PersonValues::checkBankAccount(['account_number' => '19-2000145399/0800', 'iban' => 'CZ8520100000002308417557'], 'cz'), 'code'),
        );
        $this->assertSame(
            [],
            PersonValues::checkBankAccount(['account_number' => 'CZ65 0800 0000 1920 0014 5399', 'iban' => ''], 'cz'),
            'IBAN v čísle účtu se před kontrolou přesune (D20)',
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
