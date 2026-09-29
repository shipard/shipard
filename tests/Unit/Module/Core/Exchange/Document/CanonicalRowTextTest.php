<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Document\CanonicalRowText;

/**
 * Skladba textu řádku dle tasks/exchange-row-text.md D1 (#84).
 */
class CanonicalRowTextTest extends TestCase
{
    public function testTopLevelDescriptionWins(): void
    {
        // Účetní doklad / export nesou text na řádkové úrovni — item fragment
        // se nesmí přilepit, jinak by dataset round-trip řádky „obohacoval".
        $row = [
            'description' => 'Poplatek za vedení účtu',
            'item'        => ['name' => 'Bankovní poplatky', 'description' => 'Měsíční'],
        ];

        $this->assertSame('Poplatek za vedení účtu', CanonicalRowText::compose($row));
    }

    public function testNameOnly(): void
    {
        $this->assertSame('Natural 95', CanonicalRowText::compose(['item' => ['name' => 'Natural 95']]));
    }

    public function testNameWithDistinctDescriptionIsJoined(): void
    {
        $row = ['item' => [
            'name'        => 'Měsíční paušál za Internet',
            'description' => 'Fakturované období: 01.07.2026 - 31.07.2026',
        ]];

        $this->assertSame(
            'Měsíční paušál za Internet — Fakturované období: 01.07.2026 - 31.07.2026',
            CanonicalRowText::compose($row),
        );
        $this->assertSame(' — ', CanonicalRowText::SEPARATOR);
    }

    public function testDescriptionContainedInNameIsDropped(): void
    {
        $row = ['item' => ['name' => 'Natural 95 (benzín)', 'description' => 'Natural 95']];

        $this->assertSame('Natural 95 (benzín)', CanonicalRowText::compose($row));
    }

    public function testContainmentIsCaseInsensitive(): void
    {
        $row = ['item' => ['name' => 'Konzultace ŘÍZENÍ projektu', 'description' => 'řízení PROJEKTU']];

        $this->assertSame('Konzultace ŘÍZENÍ projektu', CanonicalRowText::compose($row));
    }

    public function testDescriptionEqualToNameIsDropped(): void
    {
        $row = ['item' => ['name' => 'Doprava', 'description' => 'Doprava']];

        $this->assertSame('Doprava', CanonicalRowText::compose($row));
    }

    public function testDescriptionOnly(): void
    {
        $row = ['item' => ['description' => 'Hodinová sazba']];

        $this->assertSame('Hodinová sazba', CanonicalRowText::compose($row));
    }

    public function testEmptyAndWhitespaceValuesAreIgnored(): void
    {
        // Prázdný top-level description nesmí vyhrát nad item fragmentem;
        // whitespace-only popis se nepřilepí.
        $row = [
            'description' => '   ',
            'item'        => ['name' => "  Natural 95\t", 'description' => "\n "],
        ];

        $this->assertSame('Natural 95', CanonicalRowText::compose($row));
        $this->assertNull(CanonicalRowText::compose(['description' => '', 'item' => ['name' => '', 'description' => '']]));
    }

    public function testNonStringValuesAreIgnored(): void
    {
        $row = [
            'description' => 42,
            'item'        => ['name' => ['x'], 'description' => 3.5],
        ];

        $this->assertNull(CanonicalRowText::compose($row));
        $this->assertSame('Servis', CanonicalRowText::compose(['description' => false, 'item' => ['name' => 'Servis', 'description' => null]]));
    }

    public function testMissingOrNonArrayItem(): void
    {
        $this->assertNull(CanonicalRowText::compose([]));
        $this->assertNull(CanonicalRowText::compose(['item' => 'Natural 95']));
        $this->assertNull(CanonicalRowText::compose(['item' => null]));
        $this->assertSame('Kontace 518', CanonicalRowText::compose(['description' => 'Kontace 518', 'item' => 'nonsense']));
    }

    public function testResultIsCappedAtColumnLengthInCharacters(): void
    {
        // Vícebajtový text: limit je ve znacích (mb_substr), ne v bajtech —
        // varchar(500) utf8mb4 pojme 500 znaků.
        $name = str_repeat('Ž', 300);
        $desc = str_repeat('č', 300);

        $joined = CanonicalRowText::compose(['item' => ['name' => $name, 'description' => $desc]]);
        $this->assertSame(CanonicalRowText::MAX_LENGTH, mb_strlen($joined, 'UTF-8'));
        $this->assertStringStartsWith($name . ' — ', $joined);

        $top = CanonicalRowText::compose(['description' => str_repeat('ř', 600)]);
        $this->assertSame(500, mb_strlen($top, 'UTF-8'));
        $this->assertSame(str_repeat('ř', 500), $top);
    }
}
