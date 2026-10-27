<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\World\Validations;

use PHPUnit\Framework\TestCase;
use Shipard\Module\World\Validations\Iban;
use Shipard\Module\World\Validations\ValueChecks;
use Shipard\Module\World\Validations\ValueFinding;
use Shipard\Module\World\Validations\ValueRules;
use Shipard\Module\World\Validations\ValueRulesRegistry;

/** Kontroly hodnot s důvodem a sdílené texty nálezů (#118 D13, D19); mod 11 jako podezření (D30). */
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
        $suspect = ValueChecks::accountNumber('2308417558/2010', true);
        $this->assertSame(ValueFinding::ACCOUNT_NUMBER_CHECKSUM, $suspect?->code, 'mod 11 je podezření, ne jistá chyba (D30)');
        $this->assertTrue($suspect->isSuspicion());
        $this->assertSame('Číslo účtu „2308417558/2010“ neprojde kontrolou mod 11 — ověřte ho.', $suspect->message());
        $this->assertSame(ValueFinding::ACCOUNT_NUMBER_CHECKSUM, ValueChecks::accountNumber('12-2308417557/2010', true)?->code, 'podezřelé předčíslí');
        $zeros = ValueChecks::accountNumber('0/0100', true);
        $this->assertSame(ValueFinding::ACCOUNT_NUMBER_INVALID, $zeros?->code, 'samé nuly jsou chyba tvaru');
        $this->assertSame('Číslo účtu „0/0100“ má číslo účtu ze samých nul.', $zeros->message());
        $this->assertFalse($zeros->isSuspicion());
        $this->assertSame('Číslo účtu „532013000“ nemá kód banky (tvar [předčíslí-]číslo/kód banky).', ValueChecks::accountNumber('532013000', true)?->message());
        $this->assertSame('Číslo účtu „2308417557/2010<“ obsahuje nepovolené znaky (jen číslice, pomlčka a lomítko).', ValueChecks::accountNumber('2308417557/2010<', true)?->message());
        $this->assertSame('Číslo účtu „230841755ě/2010“ obsahuje nepovolené znaky (jen číslice, pomlčka a lomítko).', ValueChecks::accountNumber('230841755ě/2010', true)?->message(), 'písmeno z české klávesnice');
        $this->assertSame('Číslo účtu „1234567-2000145399/0800“ nemá tvar [předčíslí-]číslo/kód banky.', ValueChecks::accountNumber('1234567-2000145399/0800', true)?->message(), 'dlouhé předčíslí');
        $this->assertSame('Číslo účtu „2308417557/201“ nemá tvar [předčíslí-]číslo/kód banky.', ValueChecks::accountNumber('2308417557/201', true)?->message(), 'kód banky nemá čtyři číslice');
        $this->assertNull(ValueChecks::accountNumber('532013000', false), 'zahraniční tvar u strany bez tuzemského tvaru');

        $this->assertNull(ValueChecks::iban('CZ8520100000002308417557'));
        $this->assertSame('IBAN „CZ1“ nemá tvar IBAN.', ValueChecks::iban('CZ1')?->message());
        $this->assertSame('IBAN „CZ8520100000002308417558“ nemá platnou kontrolní číslici.', ValueChecks::iban('CZ8520100000002308417558')?->message());
        $this->assertNull(ValueChecks::iban('CZ4701000000001234567890'), 'číslo uvnitř bez mod 11 IBAN neznehodnotí (D30)');
        $suspectIban = ValueChecks::ibanAccountChecksum('CZ4701000000001234567890');
        $this->assertSame(ValueFinding::ACCOUNT_NUMBER_CHECKSUM, $suspectIban?->code);
        $this->assertSame(ValueRules::IBAN, $suspectIban->kind, 'nález u IBAN');
        $this->assertSame(
            'IBAN „CZ4701000000001234567890“ má platnou kontrolní číslici, ale číslo účtu uvnitř „1234567890/0100“ neprojde kontrolou mod 11 — ověřte ho.',
            $suspectIban->message(),
        );
        $this->assertNull(ValueChecks::ibanAccountChecksum('CZ8520100000002308417557'));
        $this->assertNull(ValueChecks::ibanAccountChecksum('CZ8520100000002308417558'), 'neplatný IBAN hlásí iban()');
        $this->assertNull(ValueChecks::ibanAccountChecksum('GB82WEST12345698765432'), 'stát bez tuzemského tvaru');
        $short = 'CZ' . Iban::checkDigits('CZ', '0800000019200014539') . '0800000019200014539';
        $this->assertTrue(Iban::checksumOk($short));
        $this->assertSame("IBAN „{$short}“ má pro stát CZ chybnou délku (24 znaků, zapsáno 23).", ValueChecks::iban($short)?->message(), 'IBAN bez jedné číslice');
        $long = 'DE' . Iban::checkDigits('DE', '100700000532013000000') . '100700000532013000000';
        $this->assertSame("IBAN „{$long}“ má pro stát DE chybnou délku (22 znaků, zapsáno 25).", ValueChecks::iban($long)?->message());

        $this->assertNull(ValueChecks::bic('GIBACZPX'));
        $this->assertSame('BIC „GIBACZ“ nemá tvar 8 nebo 11 znaků.', ValueChecks::bic('GIBACZ')?->message());

        $mismatch = ValueChecks::ibanAccount('CZ8520100000002308417557', '19-2000145399/0800');
        $this->assertSame(ValueFinding::IBAN_ACCOUNT_MISMATCH, $mismatch?->code);
        $this->assertSame(
            'IBAN „CZ8520100000002308417557“ neodpovídá číslu účtu „19-2000145399/0800“ — podle IBAN je účet „2308417557/2010“.',
            $mismatch->message(),
        );
        $this->assertNull(ValueChecks::ibanAccount('CZ8520100000002308417557', '2308417557/2010'));
        $this->assertNull(ValueChecks::ibanAccount('CZ8520100000002308417558', '19-2000145399/0800'), 'neplatný IBAN nesoulad nehlásí');
        $this->assertNull(ValueChecks::ibanAccount('CZ8520100000002308417557', '2308417557'), 'číslo bez tuzemského tvaru');
        $this->assertNull(ValueChecks::ibanAccount('DE72100700000532013000', '19-2000145399/0800'), 'IBAN bez tuzemského tvaru');
        $this->assertSame(
            ValueFinding::IBAN_ACCOUNT_MISMATCH,
            ValueChecks::ibanAccount('CZ8520100000002308417557', '2308417558/2010')?->code,
            'nesoulad i u podezřelého čísla — dva různé účty jsou jistá chyba (D30)',
        );
        $this->assertNull(ValueChecks::ibanAccount('CZ4701000000001234567890', '1234567890/0100'), 'týž podezřelý účet není nesoulad');

        $codes = array_map(
            static fn (ValueFinding $f): string => $f->code,
            ValueChecks::bankAccount('0/0100', 'CZ1', 'X', true),
        );
        $this->assertSame([ValueFinding::ACCOUNT_NUMBER_INVALID, ValueFinding::IBAN_INVALID, ValueFinding::BIC_INVALID], $codes);
        $this->assertSame([], ValueChecks::bankAccount(null, '', null, true));
    }

    public function testSuggestionsComeOnlyFromAValidCounterpart(): void
    {
        $cz = ValueRulesRegistry::forCountry('cz');

        $findings = ValueChecks::identity('52654151', 'CZ52654150', $cz);
        $this->assertSame([ValueFinding::COMPANY_ID_INVALID], array_column($findings, 'code'));
        $this->assertSame('52654150', $findings[0]->suggestion, 'IČO z osmimístného DIČ');
        $this->assertSame('IČO „52654151“ nemá platný kontrolní součet.', $findings[0]->message(), 'návrh do zprávy nepatří');
        $this->assertNull(ValueChecks::identity('52654151', 'CZ5265415012', $cz)[0]->suggestion, 'desetimístné DIČ fyzické osoby IČO nenese');
        $this->assertNull(ValueChecks::identity('52654151', 'CZ526541', $cz)[0]->suggestion, 'neplatné DIČ nic nenavrhuje');
        $this->assertNull(ValueChecks::identity('52654151', null, $cz)[0]->suggestion);
        $this->assertNull(ValueChecks::identity('52654150', 'CZ07123451', $cz)[0]->suggestion, 'nesoulad dvou platných hodnot: která je správně, se neví');

        $findings = ValueChecks::bankAccount('19-2000145399/0800', 'CZ6508000000192000145398', null, true);
        $this->assertSame([ValueFinding::IBAN_INVALID], array_column($findings, 'code'));
        $this->assertSame('CZ6508000000192000145399', $findings[0]->suggestion, 'IBAN z platného čísla účtu');
        $findings = ValueChecks::bankAccount('19-2000145399/0800', 'CZ' . Iban::checkDigits('CZ', '0800000019200014539') . '0800000019200014539', null, true);
        $this->assertSame('CZ6508000000192000145399', $findings[0]->suggestion, 'IBAN bez jedné číslice');
        $findings = ValueChecks::bankAccount('19-2000145399/0800', 'SK6508000000192000145398', null, true);
        $this->assertSame('SK' . Iban::checkDigits('SK', '08000000192000145399') . '08000000192000145399', $findings[0]->suggestion, 'stát podle prefixu neplatného IBAN');
        $this->assertNull(ValueChecks::bankAccount('19-2000145399/0800', 'není IBAN', null, true)[0]->suggestion, 'bez rozpoznatelného prefixu bez návrhu');
        $this->assertNull(ValueChecks::bankAccount('19-2000145399/0800', 'DE72100700000532013001', null, true)[0]->suggestion, 'stát bez tuzemského tvaru');

        $findings = ValueChecks::bankAccount('2308417558/2010', 'CZ8520100000002308417557', null, true);
        $this->assertSame([ValueFinding::ACCOUNT_NUMBER_CHECKSUM, ValueFinding::IBAN_ACCOUNT_MISMATCH], array_column($findings, 'code'), 'podezřelé číslo + platný IBAN jiného účtu');
        $this->assertNull($findings[0]->suggestion, 'podezření návrh nenese (D30) — účet podle IBAN říká nesoulad');
        $this->assertStringContainsString('podle IBAN je účet „2308417557/2010“', $findings[1]->message());
        $this->assertSame('2308417557/2010', ValueChecks::bankAccount('2308417557', 'CZ8520100000002308417557', null, true)[0]->suggestion, 'číslo bez kódu banky: návrh z IBAN');
        $this->assertNull(ValueChecks::bankAccount('2308417557', 'CZ4701000000001234567890', null, true)[0]->suggestion, 'z IBAN s podezřelým číslem uvnitř se nenavrhuje');

        $both = ValueChecks::bankAccount('2308417558/2010', 'CZ8520100000002308417558', null, true);
        $this->assertSame([ValueFinding::ACCOUNT_NUMBER_CHECKSUM, ValueFinding::IBAN_INVALID], array_column($both, 'code'));
        $this->assertSame([null, null], array_column($both, 'suggestion'), 'podezřelé číslo + chybná kontrolní číslice: bez návrhu — z podezřelého čísla se nedopočítává');
        $this->assertNull(ValueChecks::bankAccount('19-2000145399/0800', 'CZ8520100000002308417557', null, true)[0]->suggestion, 'nesoulad dvou platných hodnot');
        $this->assertNull(ValueChecks::bankAccount(null, 'CZ6508000000192000145398', null, true)[0]->suggestion, 'bez druhé hodnoty není z čeho');
    }

    public function testSuspectAccountIsReportedOncePerAccount(): void
    {
        $same = ValueChecks::bankAccount('1234567890/0100', 'CZ4701000000001234567890', null, true);
        $this->assertSame([ValueFinding::ACCOUNT_NUMBER_CHECKSUM], array_column($same, 'code'), 'číslo i IBAN téhož podezřelého účtu: jeden nález');
        $this->assertSame(ValueRules::ACCOUNT_NUMBER, $same[0]->kind, 'u čísla účtu');
        $this->assertNull($same[0]->suggestion);

        $ibanOnly = ValueChecks::bankAccount(null, 'CZ4701000000001234567890', null, true);
        $this->assertSame([ValueFinding::ACCOUNT_NUMBER_CHECKSUM], array_column($ibanOnly, 'code'));
        $this->assertSame(ValueRules::IBAN, $ibanOnly[0]->kind, 'jen IBAN: nález u IBAN');

        $different = ValueChecks::bankAccount('19-2000145399/0800', 'CZ4701000000001234567890', null, true);
        $this->assertSame(
            [ValueFinding::ACCOUNT_NUMBER_CHECKSUM, ValueFinding::IBAN_ACCOUNT_MISMATCH],
            array_column($different, 'code'),
            'platné číslo + IBAN jiného, podezřelého účtu: podezření u IBAN a nesoulad',
        );
        $this->assertSame(ValueRules::IBAN, $different[0]->kind);

        $shapeless = ValueChecks::bankAccount('2308417557', 'CZ4701000000001234567890', null, true);
        $this->assertSame([ValueFinding::ACCOUNT_NUMBER_INVALID, ValueFinding::ACCOUNT_NUMBER_CHECKSUM], array_column($shapeless, 'code'), 'číslo bez tvaru nenese podezření IBAN');
        $this->assertSame(
            [ValueFinding::ACCOUNT_NUMBER_CHECKSUM],
            array_column(ValueChecks::bankAccount('1234567890/0100', 'CZ4701000000001234567890', null, false), 'code'),
            'tuzemský tvar se hodnotí bez ohledu na zemi strany',
        );
    }

    public function testEveryCodeCanBeProduced(): void
    {
        $cz = ValueRulesRegistry::forCountry('cz');
        $produced = array_merge(
            ValueChecks::identity('12345678', 'CZ1', $cz),
            ValueChecks::identity('52654150', 'CZ07123451', $cz),
            [ValueChecks::zip('1', $cz)],
            ValueChecks::bankAccount('0/0100', 'CZ1', 'X', true),
            ValueChecks::bankAccount('19-2000145399/0800', 'CZ8520100000002308417557', null, true),
            ValueChecks::bankAccount('2308417558/2010', null, null, true),
        );
        $codes = array_unique(array_map(static fn (ValueFinding $f): string => $f->code, array_filter($produced)));
        sort($codes);
        $expected = ValueFinding::CODES;
        sort($expected);
        $this->assertSame($expected, array_values($codes));
    }
}
