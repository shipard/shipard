<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\RecordCode;

class RecordCodeTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function shapes(): array
    {
        return [
            'typ dokladu'            => ['invno', true],
            's příponou'             => ['invno-2', true],
            'velká písmena'          => ['cash-HL', true],
            'tečka a podtržítko'     => ['a.b_c', true],
            'začíná číslicí'         => ['2cz', true],
            'prázdný'                => ['', false],
            'mezera'                 => ['inv no', false],
            'dvojtečka (D15)'        => ['code:invno', false],
            'zavináč (D15)'          => ['@invno', false],
            'začíná pomlčkou'        => ['-invno', false],
            'začíná tečkou'          => ['.invno', false],
            'diakritika'             => ['řada', false],
            'lomítko'                => ['inv/no', false],
            'konec řádku na konci'   => ["invno\n", false],
        ];
    }

    #[DataProvider('shapes')]
    public function testShape(string $code, bool $valid): void
    {
        $this->assertSame($valid, RecordCode::isValid($code));
    }

    public function testKeyIgnoresCaseLikeTheDatabaseCollation(): void
    {
        $this->assertSame(RecordCode::key('FV'), RecordCode::key('fv'));
        $this->assertNotSame(RecordCode::key('fv'), RecordCode::key('fv-2'));
    }

    public function testPartReplacesEveryDisallowedCharacter(): void
    {
        $this->assertSame('HL-1', RecordCode::part(' HL 1 '));
        $this->assertSame('a-b', RecordCode::part('a:b'));
        $this->assertSame('-1', RecordCode::part('Č1'), 'znak s diakritikou je jeden znak, ne dva bajty');
        $this->assertSame('', RecordCode::part('   '));
    }

    public function testBaseJoinsPartsAndStartsWithLetterOrDigit(): void
    {
        $this->assertSame('cash-HL', RecordCode::base('cash', 'HL'));
        $this->assertSame('invno', RecordCode::base('invno', ''));
        $this->assertSame('1', RecordCode::base('Č1'));
        $this->assertSame('', RecordCode::base('', '  '));
        $this->assertTrue(RecordCode::isValid(RecordCode::base('cash', 'H L:1')));
    }

    public function testUniqueKeepsFreeBaseAndSuffixesTakenOne(): void
    {
        $this->assertSame('invno', RecordCode::unique('invno', [], 40));
        $this->assertSame('invno-2', RecordCode::unique('invno', ['invno' => true], 40));
        $this->assertSame('invno-3', RecordCode::unique('invno', ['invno' => true, 'invno-2' => true], 40));
        // Obsazenost bez ohledu na velikost písmen.
        $this->assertSame('cash-HL-2', RecordCode::unique('cash-HL', ['cash-hl' => true], 40));
    }

    public function testNumberedStartsAtTwo(): void
    {
        $this->assertSame('cz-2', RecordCode::numbered('cz', [], 20));
        $this->assertSame('cz-4', RecordCode::numbered('cz', ['cz-2' => true, 'cz-3' => true], 20));
    }

    public function testResultFitsTheColumnLength(): void
    {
        $base = str_repeat('a', 30);

        $this->assertSame(str_repeat('a', 10), RecordCode::unique($base, [], 10));
        $taken = [str_repeat('a', 10) => true];
        $this->assertSame(str_repeat('a', 8) . '-2', RecordCode::unique($base, $taken, 10));
        $this->assertSame(10, strlen(RecordCode::numbered($base, [], 10)));
    }

    public function testEmptyBaseStillGivesValidCode(): void
    {
        $this->assertTrue(RecordCode::isValid(RecordCode::unique('', [], 20)));
        $this->assertTrue(RecordCode::isValid(RecordCode::numbered('', [], 20)));
    }
}
