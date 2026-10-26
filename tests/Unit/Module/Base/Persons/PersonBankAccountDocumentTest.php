<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\PersonBankAccountDocument;

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
