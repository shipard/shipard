<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\PersonBankAccountDocument;
use Shipard\Module\Base\Persons\Values\PersonContext;

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

/** Účet osoby z formuláře: tvary a přesun IBAN ze sloupce čísla účtu (#118 D18, D20). */
class PersonBankAccountDocumentTest extends TestCase
{
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
            ['account_number:account_number_invalid', 'bic:bic_invalid'],
            array_map(static fn (array $e): string => $e['column'] . ':' . $e['code'], $result->warningsToArray()),
            'nesoulad se hlásí jen u dvou platných hodnot',
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
