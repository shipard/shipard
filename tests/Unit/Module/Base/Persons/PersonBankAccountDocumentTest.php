<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\PersonBankAccountDocument;
use Shipard\Module\Base\Persons\Values\PersonContext;
use Shipard\Module\World\Validations\Iban;

/** Kontext bez databáze: osoba 1 je vlastní firma (cz), osoba 9 má sídlo v de. */
final class StubBankContext extends PersonContext
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
        return $personId === 9 ? 'de' : null;
    }
}

final class StubBankDocument extends PersonBankAccountDocument
{
    protected function personContext(): PersonContext
    {
        return new StubBankContext();
    }
}

/**
 * Účet osoby z formuláře: tvary a přesun IBAN ze sloupce čísla účtu (#118
 * D18, D20), číslo účtu nebo IBAN (D28).
 */
class PersonBankAccountDocumentTest extends TestCase
{
    public function testAccountNeedsAccountNumberOrIban(): void
    {
        $ibanOnly = ['person' => 2, 'account_number' => '', 'iban' => 'DE72 1007 0000 0532 0130 00', 'bic' => null];
        $result = (new StubBankDocument())->validate($ibanOnly);
        $this->assertTrue($result->isValid(), 'zahraniční účet má jen IBAN');
        $this->assertSame([], $result->warningsToArray());

        $moved = ['person' => 2, 'account_number' => 'DE72 1007 0000 0532 0130 00', 'iban' => ''];
        $this->assertTrue((new StubBankDocument())->validate($moved)->isValid(), 'IBAN v čísle účtu se před kontrolou přesune (D20)');

        foreach ([2, 1] as $person) {
            $empty = ['person' => $person, 'name' => 'Bez údajů', 'account_number' => ' ', 'iban' => null, 'bic' => ''];
            $result = (new StubBankDocument())->validate($empty);
            $this->assertFalse($result->isValid(), "bez obou údajů chyba i u partnera (osoba {$person})");
            $this->assertSame(
                [['column' => 'account_number', 'message' => 'Musí být vyplněn alespoň jeden z údajů: Číslo účtu nebo IBAN.', 'code' => 'required_one_of']],
                array_map(static fn (array $e): array => ['column' => $e['column'], 'message' => $e['message'], 'code' => $e['code']], $result->toArray()),
            );
        }

        $newWithoutKeys = ['person' => 2, 'name' => 'Jen název'];
        $this->assertFalse((new StubBankDocument())->validate($newWithoutKeys)->isValid(), 'nový záznam bez obou klíčů');

        $partialUpdate = ['id' => 30, 'person' => 2, 'name' => 'Přejmenovaný'];
        $this->assertTrue((new StubBankDocument())->validate($partialUpdate)->isValid(), 'částečný update jiných sloupců');
        $partialIban = ['id' => 30, 'person' => 2, 'iban' => ''];
        $this->assertTrue((new StubBankDocument())->validate($partialIban)->isValid(), 'jeden z klíčů bez druhého se nehodnotí — uložený účet není vidět');
    }

    public function testBeforeSaveShapesColumns(): void
    {
        $data = ['person' => 1, 'name' => 'Hlavní', 'account_number' => ' 000019-2000145399 / 0800', 'iban' => 'cz65 0800 0000 1920 0014 5399', 'bic' => ' gibaczpx', 'currency' => 'czk'];
        (new PersonBankAccountDocument())->beforeSave($data);
        $this->assertSame(
            ['person' => 1, 'name' => 'Hlavní', 'account_number' => '19-2000145399/0800', 'iban' => 'CZ6508000000192000145399', 'bic' => 'GIBACZPX', 'currency' => 'czk'],
            $data,
        );
    }

    public function testInvalidAccountWarnsPartnerAndBlocksOwnCompany(): void
    {
        $partner = ['person' => 2, 'account_number' => '2308417558/2010', 'iban' => 'CZ8520100000002308417557', 'bic' => 'X'];
        $result = (new StubBankDocument())->validate($partner);
        $this->assertTrue($result->isValid());
        $this->assertSame(
            ['account_number:account_number_checksum', 'bic:bic_invalid', 'iban:iban_account_mismatch'],
            array_map(static fn (array $e): string => $e['column'] . ':' . $e['code'], $result->warningsToArray()),
            'podezřelé číslo (mod 11, D30) a nesoulad s IBAN jiného účtu',
        );

        $own = ['person' => 1, 'account_number' => '19-2000145399/0800', 'iban' => 'CZ8520100000002308417557'];
        $result = (new StubBankDocument())->validate($own);
        $this->assertFalse($result->isValid());
        $this->assertSame('iban_account_mismatch', $result->toArray()[0]['code']);

        $foreign = ['person' => 9, 'account_number' => '532013000', 'iban' => 'DE72100700000532013000'];
        $this->assertSame([], (new StubBankDocument())->validate($foreign)->warningsToArray(), 'tuzemský tvar se čeká podle země osoby');
        $domestic = ['person' => 2, 'account_number' => '532013000'];
        $this->assertSame(['account_number_invalid'], array_column((new StubBankDocument())->validate($domestic)->warningsToArray(), 'code'));
    }

    public function testSuspectAccountNumberIsWarningEvenForOwnCompany(): void
    {
        // D30: mod 11 je pravidlo pro vytvoření čísla, zveřejněné účty ho někdy nesplňují — uloží se s varováním.
        $suspect = ['person' => 1, 'account_number' => '2308417558/2010', 'iban' => ''];
        $result = (new StubBankDocument())->validate($suspect);
        $this->assertTrue($result->isValid(), 'vlastní firma: podezřelé číslo jde uložit');
        $this->assertSame(['account_number:account_number_checksum'], array_map(static fn (array $e): string => $e['column'] . ':' . $e['code'], $result->warningsToArray()));
        $this->assertSame('Číslo účtu „2308417558/2010“ neprojde kontrolou mod 11 — ověřte ho.', $result->warningsToArray()[0]['message']);

        $withIban = ['person' => 1, 'account_number' => '2308417558/2010', 'iban' => 'CZ' . Iban::checkDigits('CZ', '20100000002308417558') . '20100000002308417558'];
        $result = (new StubBankDocument())->validate($withIban);
        $this->assertTrue($result->isValid(), 'IBAN téhož podezřelého účtu je platný');
        $this->assertSame(['account_number'], array_column($result->warningsToArray(), 'column'), 'jeden nález, u čísla účtu');

        $ibanOnly = ['person' => 1, 'account_number' => '', 'iban' => 'CZ4701000000001234567890'];
        $result = (new StubBankDocument())->validate($ibanOnly);
        $this->assertTrue($result->isValid());
        $this->assertSame(['iban:account_number_checksum'], array_map(static fn (array $e): string => $e['column'] . ':' . $e['code'], $result->warningsToArray()), 'jen IBAN: podezření u IBAN');

        foreach (['CZ8520100000002308417558' => 'iban_invalid', 'CZ850100000002308417558' => 'iban_invalid'] as $iban => $code) {
            $data = ['person' => 1, 'account_number' => '2308417558/2010', 'iban' => $iban];
            $result = (new StubBankDocument())->validate($data);
            $this->assertFalse($result->isValid(), "jistá chyba IBAN „{$iban}“ dál blokuje");
            $this->assertSame([$code], array_column($result->toArray(), 'code'));
            $this->assertSame(['account_number_checksum'], array_column($result->warningsToArray(), 'code'));
        }
        $different = ['person' => 1, 'account_number' => '2308417558/2010', 'iban' => 'CZ8520100000002308417557'];
        $result = (new StubBankDocument())->validate($different);
        $this->assertFalse($result->isValid(), 'platný IBAN jiného účtu: nesoulad je chyba i při podezření');
        $this->assertSame(['iban_account_mismatch'], array_column($result->toArray(), 'code'));
        $this->assertSame(['account_number_checksum'], array_column($result->warningsToArray(), 'code'));
        $noBank = ['person' => 1, 'account_number' => '2308417558', 'iban' => ''];
        $this->assertFalse((new StubBankDocument())->validate($noBank)->isValid(), 'bez kódu banky dál chyba');
    }

    public function testIbanTypedIntoAccountNumberMovesToIban(): void
    {
        $data = ['person' => 1, 'account_number' => 'CZ65 0800 0000 1920 0014 5399', 'iban' => '', 'bic' => ''];
        (new PersonBankAccountDocument())->beforeSave($data);
        $this->assertSame('19-2000145399/0800', $data['account_number']);
        $this->assertSame('CZ6508000000192000145399', $data['iban']);

        $foreign = ['person' => 1, 'account_number' => 'DE72 1007 0000 0532 0130 00', 'iban' => null, 'bic' => null];
        (new PersonBankAccountDocument())->beforeSave($foreign);
        $this->assertSame('', $foreign['account_number'], 'mimo cz / sk zůstane číslo účtu prázdné');
        $this->assertSame('DE72100700000532013000', $foreign['iban']);
    }
}
