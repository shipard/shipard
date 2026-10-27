<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\World\Validations;

use PHPUnit\Framework\TestCase;
use Shipard\Module\World\Validations\CzValueRules;
use Shipard\Module\World\Validations\DeValueRules;
use Shipard\Module\World\Validations\DomesticAccount;
use Shipard\Module\World\Validations\GenericValueRules;
use Shipard\Module\World\Validations\Iban;
use Shipard\Module\World\Validations\Phone;
use Shipard\Module\World\Validations\SkValueRules;
use Shipard\Module\World\Validations\ValueRulesRegistry;
use Shipard\Module\World\Validations\VatId;

/** Okraje mimo sdílený fixture: primitiva a vazby, které fixture vyjadřuje jen nepřímo. */
class CountryRulesEdgeCasesTest extends TestCase
{
    // ── Tuzemský účet ───────────────────────────────────────────────────

    public function testDomesticAccountParseAndFormat(): void
    {
        $a = DomesticAccount::parse(' 000019 - 0002000145399 / 0800 ');
        $this->assertNotNull($a);
        $this->assertSame(['19', '2000145399', '0800'], [$a->prefix, $a->number, $a->bank]);
        $this->assertSame('19-2000145399/0800', (string) $a);
        $this->assertSame('0800' . '000019' . '2000145399', $a->toBban());
        $this->assertEquals($a, DomesticAccount::fromBban($a->toBban()));

        $noPrefix = DomesticAccount::parse('0-123/0100');
        $this->assertSame('', $noPrefix?->prefix);
        $this->assertSame('123/0100', (string) $noPrefix);

        $this->assertNull(DomesticAccount::parse('2308417557'), 'bez kódu banky není tuzemský účet');
        $this->assertNull(DomesticAccount::parse('CZ8520100000002308417557'));
        $this->assertNull(DomesticAccount::parse('1234567-123/0100'), 'předčíslí nejvýš 6 číslic');
        $this->assertNull(DomesticAccount::fromBban('123'));
    }

    public function testMod11EmptyPrefixIsValidAndWeightsRunFromTheRight(): void
    {
        $this->assertTrue(DomesticAccount::mod11(''));
        $this->assertTrue(DomesticAccount::mod11('19'));
        $this->assertTrue(DomesticAccount::mod11('2000145399'));
        $this->assertFalse(DomesticAccount::mod11('2000145398'));
        $this->assertFalse((new DomesticAccount('', '0', '0100'))->isValid(), 'číslo 0 není účet');
        $this->assertTrue((new DomesticAccount('', '0', '0100'))->checksumOk(), 'samé nuly mod 11 projdou — chyba tvaru, ne podezření');
        $suspect = DomesticAccount::parse('1234567890/0100');
        $this->assertNotNull($suspect);
        $this->assertFalse($suspect->checksumOk(), 'mod 11 neprojde = podezření (D30)');
        $this->assertFalse($suspect->isValid());
        $this->assertTrue(DomesticAccount::parse('19-2000145399/0800')?->checksumOk());
    }

    // ── IBAN ────────────────────────────────────────────────────────────

    public function testIbanChecksumVersusCountryRule(): void
    {
        $this->assertSame('65', Iban::checkDigits('CZ', '0800' . '000019' . '2000145399'));
        $this->assertSame('72', Iban::checkDigits('de', '100700000532013000'));
        $this->assertTrue(Iban::checksumOk('CZ4701000000001234567890'), 'mod 97 sedí');
        $this->assertTrue(Iban::isValid('CZ4701000000001234567890'), 'číslo účtu uvnitř bez mod 11 je jen podezření (D30)');
        $this->assertFalse(Iban::isValidWithDomesticAccount('CZ4701000000001234567890'), 'přísná varianta pro dopočet ho odmítne');
        $this->assertTrue(Iban::isValidWithDomesticAccount('CZ8520100000002308417557'));
        $this->assertFalse(Iban::isValidWithDomesticAccount('CZ8520100000002308417558'), 'chybná kontrolní číslice ani přísně');
        $this->assertTrue(Iban::isValidWithDomesticAccount('GB82WEST12345698765432'), 'stát bez tuzemského tvaru: totéž co isValid');
        $this->assertFalse(Iban::isValid('DE7210070000053201300'), 'DE má 22 znaků');
        $this->assertSame(24, ValueRulesRegistry::forCountry('cz')->ibanLength());
        $this->assertSame(24, ValueRulesRegistry::forCountry('sk')->ibanLength());
        $this->assertSame(22, ValueRulesRegistry::forCountry('de')->ibanLength());
        $this->assertNull(ValueRulesRegistry::forCountry('gb')->ibanLength(), 'stát bez třídy délku neurčuje');
        $this->assertTrue(Iban::isValid('GB82WEST12345698765432'), 'země bez třídy: jen tvar a mod 97');
        $this->assertSame('gb', Iban::countryOf('GB82WEST12345698765432'));
        $this->assertNull(Iban::countryOf('12345'));
    }

    public function testCzIbanRoundTrip(): void
    {
        $cz = new CzValueRules();
        $account = DomesticAccount::parse('19-2000145399/0800');
        $this->assertNotNull($account);
        $iban = $cz->ibanFromDomesticAccount($account);
        $this->assertSame('CZ6508000000192000145399', $iban);
        $this->assertSame('19-2000145399/0800', (string) $cz->domesticAccountFromIban((string) $iban));
        $this->assertNull($cz->domesticAccountFromIban('SK2411000000002600000003'), 'cizí prefix');
        $this->assertNull((new DeValueRules())->ibanFromDomesticAccount($account), 'de tuzemský tvar nemá');
    }

    // ── IČO, DIČ ────────────────────────────────────────────────────────

    public function testCompanyIdNormalizationPadsOnlyDigits(): void
    {
        $cz = new CzValueRules();
        $this->assertSame('00006947', $cz->normalizeCompanyId(' 6947 '));
        $this->assertSame('123456789', $cz->normalizeCompanyId('123456789'), 'devět číslic se nezkracuje');
        $this->assertSame('HRB12345', $cz->normalizeCompanyId('HRB 12345'), 'písmena: jen bez mezer');
        $this->assertSame('', $cz->normalizeCompanyId('  '));
        $this->assertFalse($cz->isValidCompanyId(''));
        $this->assertTrue((new SkValueRules())->isValidCompanyId('36339008'));
    }

    public function testVatIdCompletionAndCompanyIdLink(): void
    {
        $cz = new CzValueRules();
        $this->assertSame('CZ43075894', VatId::normalize('43075894', $cz));
        $this->assertSame('CZ4307589412', VatId::normalize('4307589412', $cz));
        $this->assertSame('4307589', VatId::normalize('4307589', $cz), 'sedm číslic DIČ není');
        $this->assertSame('43075894', VatId::normalize('43075894', new SkValueRules()), 'sk nedoplňuje');
        $this->assertSame('43075894', VatId::normalize('43075894', new GenericValueRules('us')));
        $this->assertSame('43075894', VatId::normalize('43075894'));

        $this->assertSame('43075894', $cz->companyIdFromVatId('CZ43075894'));
        $this->assertNull($cz->companyIdFromVatId('CZ4307589412'), 'fyzická osoba');
        $this->assertNull($cz->companyIdFromVatId('SK2020000001'));
        $this->assertNull((new SkValueRules())->companyIdFromVatId('SK2020000001'));

        $this->assertSame('CZ', VatId::prefixOf('CZ43075894'));
        $this->assertNull(VatId::prefixOf('43075894'));
        $this->assertSame('43075894', VatId::numberOf('CZ43075894'));
    }

    public function testDeVatCheckDigit(): void
    {
        $this->assertSame(6, DeValueRules::checkDigit('13669597'));
        $this->assertTrue(VatId::isValid('DE136695976'));
        $this->assertFalse(VatId::isValid('DE136695977'));
    }

    // ── Telefon ─────────────────────────────────────────────────────────

    public function testPhoneEdges(): void
    {
        $cz = new CzValueRules();
        $this->assertSame('n/a', Phone::normalize(' n/a ', $cz), 'bez číslic beze změny');
        $this->assertSame('+420577123456', Phone::normalize('+420577123456', $cz));
        $this->assertSame('+420000201304', Phone::normalize('000 201 304', $cz), 'korpusové 000… je národní číslo');
        $this->assertSame('+420020130456', Phone::normalize('020 130 456', $cz), 'cz národní předčíslí nemá — nic se nezahazuje');
        $this->assertSame('0201304', Phone::normalize('0201304', $cz), '7 číslic není cz číslo — beze změny (D26)');
        $this->assertSame('+421905123456', Phone::normalize('905 123 456', new SkValueRules()));
        $this->assertSame('+4930123456', Phone::normalize('(0)30 123456', new DeValueRules()));
        $this->assertSame('+49301234567', Phone::normalize('0049 (0)30 1234567', new GenericValueRules()));
        $this->assertSame('4155550199', Phone::normalize('415-555-0199'), 'bez země bez předvolby');
    }
}
