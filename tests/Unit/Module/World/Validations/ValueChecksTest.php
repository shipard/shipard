<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\World\Validations;

use PHPUnit\Framework\TestCase;
use Shipard\Module\World\Validations\ValueChecks;
use Shipard\Module\World\Validations\ValueFinding;
use Shipard\Module\World\Validations\ValueRulesRegistry;

/** Kontroly hodnot s důvodem a sdílené texty nálezů (#118 D13, D19). */
class ValueChecksTest extends TestCase
{
    public function testCompanyIdReasons(): void
    {
        $cz = ValueRulesRegistry::forCountry('cz');
        $this->assertNull(ValueChecks::companyId('52654150', $cz));
        $checksum = ValueChecks::companyId('52654151', $cz);
        $this->assertSame(ValueFinding::COMPANY_ID_INVALID, $checksum?->code);
        $this->assertSame('IČO „52654151“ nemá platný kontrolní součet.', $checksum->message());
        $this->assertSame('IČO dodavatele „52654151“ nemá platný kontrolní součet.', $checksum->message('dodavatele'));
        $this->assertSame('IČO „5265415“ nemá tvar osmi číslic.', ValueChecks::companyId('5265415', $cz)?->message());
        $this->assertNull(ValueChecks::companyId('HRB 12345', ValueRulesRegistry::forCountry('de')), 'stát bez pravidla IČO');
        $this->assertNull(ValueChecks::companyId('5265415', ValueRulesRegistry::forCountry(null)));
    }

    public function testVatIdReasonsAndMismatch(): void
    {
        $cz = ValueRulesRegistry::forCountry('cz');
        $this->assertNull(ValueChecks::vatId('CZ52654150'));
        $this->assertSame('DIČ „CZ526541“ neodpovídá pravidlu státu CZ (8–10 číslic).', ValueChecks::vatId('CZ526541')?->message());
        $this->assertSame('DIČ „DE136695975“ neodpovídá pravidlu státu DE (9 číslic s kontrolní číslicí).', ValueChecks::vatId('DE136695975')?->message());
        $this->assertSame('DIČ „52654150“ nemá tvar: prefix státu a 2–13 znaků.', ValueChecks::vatId('52654150')?->message());

        $mismatch = ValueChecks::vatIdCompanyId('CZ07123451', '52654150', $cz);
        $this->assertSame(ValueFinding::VAT_ID_COMPANY_ID_MISMATCH, $mismatch?->code);
        $this->assertSame('DIČ „CZ07123451“ neodpovídá IČO „52654150“ — osmimístné DIČ je CZ + IČO.', $mismatch->message());
        $this->assertNull(ValueChecks::vatIdCompanyId('CZ52654150', '52654150', $cz));
        $this->assertNull(ValueChecks::vatIdCompanyId('CZ5265415012', '52654150', $cz), 'desetimístné DIČ fyzické osoby — vazba neplatí');
        $this->assertNull(ValueChecks::vatIdCompanyId('CZ07123451', '52654151', $cz), 'jen když jsou obě hodnoty platné');

        $this->assertSame(
            [ValueFinding::COMPANY_ID_INVALID],
            array_map(static fn (ValueFinding $f): string => $f->code, ValueChecks::identity('52654151', null, $cz)),
        );
        $this->assertSame([], ValueChecks::identity('', ' ', $cz), 'prázdné hodnoty se nekontrolují');
    }

    public function testZip(): void
    {
        $this->assertNull(ValueChecks::zip('76001', ValueRulesRegistry::forCountry('cz')));
        $this->assertSame('PSČ „7790“ nemá pět číslic (cz).', ValueChecks::zip('7790', ValueRulesRegistry::forCountry('cz'))?->message());
        $this->assertNull(ValueChecks::zip('SW1A 1AA', ValueRulesRegistry::forCountry('gb')));
    }

    public function testBankAccountReasons(): void
    {
        $this->assertNull(ValueChecks::accountNumber('2308417557/2010', true));
        $this->assertSame('Číslo účtu „2308417558/2010“ nesplňuje kontrolu mod 11.', ValueChecks::accountNumber('2308417558/2010', true)?->message());
        $this->assertSame('Číslo účtu „532013000“ nemá tvar [předčíslí-]číslo/kód banky.', ValueChecks::accountNumber('532013000', true)?->message());
        $this->assertNull(ValueChecks::accountNumber('532013000', false), 'zahraniční tvar u strany bez tuzemského tvaru');

        $this->assertNull(ValueChecks::iban('CZ8520100000002308417557'));
        $this->assertSame('IBAN „CZ1“ nemá tvar IBAN.', ValueChecks::iban('CZ1')?->message());
        $this->assertSame('IBAN „CZ8520100000002308417558“ nemá platnou kontrolní číslici.', ValueChecks::iban('CZ8520100000002308417558')?->message());
        $this->assertSame(
            'IBAN „CZ4701000000001234567890“ neodpovídá pravidlu státu CZ (délka nebo číslo účtu uvnitř).',
            ValueChecks::iban('CZ4701000000001234567890')?->message(),
        );

        $this->assertNull(ValueChecks::bic('GIBACZPX'));
        $this->assertSame('BIC „GIBACZ“ nemá tvar 8 nebo 11 znaků.', ValueChecks::bic('GIBACZ')?->message());

        $mismatch = ValueChecks::ibanAccount('CZ8520100000002308417557', '19-2000145399/0800');
        $this->assertSame(ValueFinding::IBAN_ACCOUNT_MISMATCH, $mismatch?->code);
        $this->assertSame(
            'IBAN „CZ8520100000002308417557“ neodpovídá číslu účtu „19-2000145399/0800“ — podle IBAN je účet „2308417557/2010“.',
            $mismatch->message(),
        );
        $this->assertNull(ValueChecks::ibanAccount('CZ8520100000002308417557', '2308417557/2010'));
        $this->assertNull(ValueChecks::ibanAccount('CZ8520100000002308417558', '19-2000145399/0800'), 'jen když jsou obě hodnoty platné');
        $this->assertNull(ValueChecks::ibanAccount('DE72100700000532013000', '19-2000145399/0800'), 'IBAN bez tuzemského tvaru');

        $codes = array_map(
            static fn (ValueFinding $f): string => $f->code,
            ValueChecks::bankAccount('1/0100', 'CZ1', 'X', true),
        );
        $this->assertSame([ValueFinding::ACCOUNT_NUMBER_INVALID, ValueFinding::IBAN_INVALID, ValueFinding::BIC_INVALID], $codes);
        $this->assertSame([], ValueChecks::bankAccount(null, '', null, true));
    }

    public function testEveryCodeCanBeProduced(): void
    {
        $cz = ValueRulesRegistry::forCountry('cz');
        $produced = array_merge(
            ValueChecks::identity('12345678', 'CZ1', $cz),
            ValueChecks::identity('52654150', 'CZ07123451', $cz),
            [ValueChecks::zip('1', $cz)],
            ValueChecks::bankAccount('1/0100', 'CZ1', 'X', true),
            ValueChecks::bankAccount('19-2000145399/0800', 'CZ8520100000002308417557', null, true),
        );
        $codes = array_unique(array_map(static fn (ValueFinding $f): string => $f->code, array_filter($produced)));
        sort($codes);
        $expected = ValueFinding::CODES;
        sort($expected);
        $this->assertSame($expected, array_values($codes));
    }
}
