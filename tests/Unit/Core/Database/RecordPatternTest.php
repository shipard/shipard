<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Database;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\RecordPattern;

/** Vzor popisku záznamu `{sloupec}` — sloupce, datum, prázdné hodnoty a oddělovače (#136 D19). */
class RecordPatternTest extends TestCase
{
    public function testColumnsComeFromPlaceholdersInOrderWithoutRepeats(): void
    {
        $this->assertSame(['code', 'name'], (new RecordPattern('{code} — {name} ({code})'))->columns());
        $this->assertSame([], (new RecordPattern('Fixed text'))->columns());
    }

    public function testRendersAllValuesWithSeparators(): void
    {
        $pattern = new RecordPattern('{code} — {name}');

        $this->assertSame('A1 — Název', $pattern->render(['code' => 'A1', 'name' => 'Název']));
        $this->assertSame(
            'A1 — Název',
            $pattern->render(['d_x__code' => 'A1', 'd_x__name' => 'Název'], 'd_x__'),
            'prefix aliasů sloupců',
        );
    }

    public function testDateIsFormattedCzechStyle(): void
    {
        $pattern = new RecordPattern('{number} · {date_issue}');

        $this->assertSame(
            'FV-1 · 3. 6. 2026',
            $pattern->render(['number' => 'FV-1', 'date_issue' => new \DateTimeImmutable('2026-06-03')]),
        );
    }

    public function testEmptyLeadingValueDropsItsSeparator(): void
    {
        $pattern = new RecordPattern('{code} — {name}');

        $this->assertSame('Název', $pattern->render(['code' => null, 'name' => 'Název']));
        $this->assertSame('Název', $pattern->render(['code' => '', 'name' => 'Název']));
        $this->assertSame('Název', $pattern->render(['name' => 'Název']), 'chybějící klíč = prázdná hodnota');
    }

    public function testEmptyMiddleValueKeepsOneSeparator(): void
    {
        $pattern = new RecordPattern('{a} · {b} · {c}');

        $this->assertSame('A · C', $pattern->render(['a' => 'A', 'b' => null, 'c' => 'C']));
    }

    public function testEmptyTrailingValueDropsItsSeparator(): void
    {
        $this->assertSame('A1', (new RecordPattern('{code} — {name}'))->render(['code' => 'A1', 'name' => '']));
        $this->assertSame('Jan', (new RecordPattern('{first_name} {last_name}'))->render(['first_name' => 'Jan', 'last_name' => null]));
    }

    public function testAllValuesEmptyGivesNull(): void
    {
        $pattern = new RecordPattern('{code} — {name}');

        $this->assertNull($pattern->render(['code' => null, 'name' => null]));
        $this->assertNull($pattern->render([]));
        $this->assertNull($pattern->render(['code' => ' ', 'name' => '']), 'bílé znaky jsou prázdná hodnota');
    }

    public function testPatternWithoutPlaceholderRendersItsText(): void
    {
        $this->assertSame('Fixed text', (new RecordPattern('Fixed text'))->render(['id' => 7]));
        $this->assertNull((new RecordPattern('   '))->render(['id' => 7]), 'prázdný výsledek = null');
    }

    public function testLiteralOutsideSeparatorSetAlwaysStays(): void
    {
        $this->assertSame('#7', (new RecordPattern('#{id}'))->render(['id' => 7]));
        $this->assertSame('123/', (new RecordPattern('{number}/{year}'))->render(['number' => 123, 'year' => null]));
    }

    public function testFallbackPlaceholderTakesFirstNonEmptyValue(): void
    {
        $pattern = new RecordPattern('{account_number|iban}');

        $this->assertSame(['account_number', 'iban'], $pattern->columns(), 'oba sloupce do dotazu');
        $this->assertSame('19-2000145399/0800', $pattern->render(['account_number' => '19-2000145399/0800', 'iban' => 'CZ6508000000192000145399']));
        $this->assertSame('DE72100700000532013000', $pattern->render(['account_number' => '', 'iban' => 'DE72100700000532013000']), 'účet bez čísla IBANem (D28)');
        $this->assertSame('DE72100700000532013000', $pattern->render(['d_a__account_number' => null, 'd_a__iban' => 'DE72100700000532013000'], 'd_a__'));
        $this->assertNull($pattern->render(['account_number' => '', 'iban' => null]));

        $this->assertSame('X — Náhradník', (new RecordPattern('{code} — {name|alt|id}'))->render(['code' => 'X', 'name' => '', 'alt' => 'Náhradník', 'id' => 7]));
        $this->assertSame(['code', 'name', 'alt', 'id'], (new RecordPattern('{code} — {name|alt|id} ({code})'))->columns());
    }

    public function testSeparatorVariants(): void
    {
        foreach ([' — ', ' – ', ' · ', ' - ', ' '] as $separator) {
            $pattern = new RecordPattern('{a}' . $separator . '{b}');
            $this->assertSame('A' . $separator . 'B', $pattern->render(['a' => 'A', 'b' => 'B']));
            $this->assertSame('B', $pattern->render(['a' => null, 'b' => 'B']), "oddělovač '{$separator}'");
        }
    }
}
