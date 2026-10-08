<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Codebooks\FiscalYearLookup;

final class FiscalYearLookupTest extends TestCase
{
    public function testYearIdForDateSkipsDeletedYears(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('fetch')
            ->with($this->stringContains('[docState] != %i'), '2026-05-06', '2026-05-06', 90)
            ->willReturn(new Row(['id' => 13]));

        $this->assertSame(13, FiscalYearLookup::yearIdForDate($db, '2026-05-06'));
    }

    public function testYearIdForDateWithoutYearOrDate(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);
        $this->assertNull(FiscalYearLookup::yearIdForDate($db, '1999-01-01'));

        $silent = $this->createMock(Connection::class);
        $silent->expects($this->never())->method('fetch');
        $this->assertNull(FiscalYearLookup::yearIdForDate($silent, ''));
    }

    public function testYearLabelTakesLeadingFourDigitsOfName(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('fetch')
            ->with($this->stringContains('[doc_number_prefix], [name]'), 100)
            ->willReturn(new Row(['doc_number_prefix' => '26', 'name' => '2026/27 hospodářský']));

        $this->assertSame('2026', FiscalYearLookup::yearLabel($db, 100));
    }

    public function testYearLabelFallsBackToCurrentYear(): void
    {
        $noPrefix = $this->createMock(Connection::class);
        $noPrefix->method('fetch')->willReturn(new Row(['doc_number_prefix' => '26', 'name' => 'FY 2026']));
        $this->assertSame(date('Y'), FiscalYearLookup::yearLabel($noPrefix, 100));

        $missing = $this->createMock(Connection::class);
        $missing->method('fetch')->willReturn(null);
        $this->assertSame(date('Y'), FiscalYearLookup::yearLabel($missing, 100));
    }

    public function testLabelFromDate(): void
    {
        $this->assertSame('2025', FiscalYearLookup::labelFromDate('2025-03-15'));
        $this->assertSame(date('Y'), FiscalYearLookup::labelFromDate(''));
        $this->assertSame(date('Y'), FiscalYearLookup::labelFromDate(null));
    }
}
