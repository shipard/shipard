<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\World\Validations;

use PHPUnit\Framework\TestCase;
use Shipard\Module\World\Validations\BankAccountShape;

/** Tvar trojice účet / IBAN / BIC při uložení a přesun IBAN ze sloupce čísla účtu (#118 D18, D20). */
class BankAccountShapeTest extends TestCase
{
    public function testShapesOnlyWhenNothingToMove(): void
    {
        $this->assertSame(
            ['accountNumber' => '19-2000145399/0800', 'iban' => 'CZ6508000000192000145399', 'bic' => 'GIBACZPX'],
            BankAccountShape::normalize(' 000019 - 2000145399 / 0800', 'cz65 0800 0000 1920 0014 5399', ' gibaczpx '),
        );
        $this->assertSame(
            ['accountNumber' => null, 'iban' => '', 'bic' => null],
            BankAccountShape::normalize(null, '', null),
            'null a prázdný řetězec zůstávají',
        );
        $this->assertSame(
            ['accountNumber' => '532013000', 'iban' => null, 'bic' => null],
            BankAccountShape::normalize('532 013 000', null, null),
            'zahraniční tvar jen bez mezer; IBAN se nedopočítává (D1)',
        );
    }

    public function testIbanInAccountNumberMovesToIbanWithDomesticNumberForCzSk(): void
    {
        $this->assertSame(
            ['accountNumber' => '19-2000145399/0800', 'iban' => 'CZ6508000000192000145399', 'bic' => null],
            BankAccountShape::normalize('CZ65 0800 0000 1920 0014 5399', null, null),
        );
        $this->assertSame(
            ['accountNumber' => '2600000003/1100', 'iban' => 'SK2411000000002600000003', 'bic' => ''],
            BankAccountShape::normalize('sk24 1100 0000 0026 0000 0003', '', ''),
        );
    }

    public function testIbanInAccountNumberOfOtherCountryLeavesAccountNumberEmpty(): void
    {
        $this->assertSame(
            ['accountNumber' => '', 'iban' => 'DE72100700000532013000', 'bic' => null],
            BankAccountShape::normalize('DE72 1007 0000 0532 0130 00', null, null),
        );
    }

    public function testIbanInAccountNumberWithSameAccountInIbanColumn(): void
    {
        $this->assertSame(
            ['accountNumber' => '19-2000145399/0800', 'iban' => 'CZ6508000000192000145399', 'bic' => null],
            BankAccountShape::normalize('CZ6508000000192000145399', 'cz65 0800 0000 1920 0014 5399', null),
        );
    }

    public function testIbanInAccountNumberWithDifferentAccountInIbanColumnIsLeftAlone(): void
    {
        $this->assertSame(
            ['accountNumber' => 'cz6508000000192000145399', 'iban' => 'CZ8520100000002308417557', 'bic' => null],
            BankAccountShape::normalize('cz65 0800 0000 1920 0014 5399', 'CZ8520100000002308417557', null),
            'jiný účet v IBAN: jen tvary (číslo účtu cizího tvaru = bez mezer), nesoulad hlásí validace',
        );
    }

    public function testRowMovesOnlyWhenBothColumnsArePresent(): void
    {
        $row = BankAccountShape::normalizeRow(['name' => 'Účet', 'account_number' => 'CZ65 0800 0000 1920 0014 5399', 'iban' => '', 'bic' => 'gibaczpx', 'currency' => 'czk']);
        $this->assertSame(
            ['name' => 'Účet', 'account_number' => '19-2000145399/0800', 'iban' => 'CZ6508000000192000145399', 'bic' => 'GIBACZPX', 'currency' => 'czk'],
            $row,
        );

        $partial = BankAccountShape::normalizeRow(['account_number' => 'CZ65 0800 0000 1920 0014 5399']);
        $this->assertSame(['account_number' => 'CZ6508000000192000145399'], $partial, 'bez sloupce iban jen tvar');

        $ibanOnly = BankAccountShape::normalizeRow(['iban' => ' cz65 0800 0000 1920 0014 5399', 'bic' => null]);
        $this->assertSame(['iban' => 'CZ6508000000192000145399', 'bic' => null], $ibanOnly);

        $this->assertSame(['account_number' => null, 'iban' => null], BankAccountShape::normalizeRow(['account_number' => null, 'iban' => null]));
        $this->assertSame(['other' => 1], BankAccountShape::normalizeRow(['other' => 1]));
    }

    public function testRowIsIdempotent(): void
    {
        $once = BankAccountShape::normalizeRow(['account_number' => 'CZ65 0800 0000 1920 0014 5399', 'iban' => '', 'bic' => ' gibaczpx']);
        $this->assertSame($once, BankAccountShape::normalizeRow($once));
    }
}
