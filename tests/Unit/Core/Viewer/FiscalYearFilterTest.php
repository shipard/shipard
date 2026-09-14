<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Viewer;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Viewer\FiscalYearFilter;
use Shipard\Tests\Fixtures\Reports\FakeFiscalPeriodProvider;

/**
 * Jediný helper „aktuálního fiskálního roku" pro filtry viewerů: default =
 * rok obsahující dnešek, fallback nejnovější rok, bez roků bez defaultu;
 * options v pořadí provideru (nejnovější první).
 */
class FiscalYearFilterTest extends TestCase
{
    public function testDefaultIsYearContainingToday(): void
    {
        $years = [['id' => 2, 'name' => '2027'], ['id' => 1, 'name' => '2026']];
        $filter = FiscalYearFilter::build(
            new FakeFiscalPeriodProvider($years, ['2026-09-14' => ['id' => 1, 'name' => '2026']]),
            'Období',
            '2026-09-14',
        );

        $this->assertSame('fiscal_year', $filter['id']);
        $this->assertSame('select', $filter['type']);
        $this->assertSame('Období', $filter['label']);
        $this->assertSame(
            [['value' => 2, 'label' => '2027'], ['value' => 1, 'label' => '2026']],
            $filter['options'],
            'Options v pořadí provideru — nejnovější první',
        );
        $this->assertSame('1', $filter['default'], 'Default jako string — hodnoty filtrů na klientovi jsou stringy');
    }

    public function testFallsBackToNewestYearWhenTodayIsOutsideAllYears(): void
    {
        $years = [['id' => 2, 'name' => '2027'], ['id' => 1, 'name' => '2026']];
        $filter = FiscalYearFilter::build(new FakeFiscalPeriodProvider($years), 'Období', '2031-01-01');

        $this->assertSame('2', $filter['default']);
    }

    public function testNoYearsMeansNoDefault(): void
    {
        $filter = FiscalYearFilter::build(new FakeFiscalPeriodProvider(), 'Období', '2026-09-14');

        $this->assertSame([], $filter['options']);
        $this->assertArrayNotHasKey('default', $filter);
    }
}
