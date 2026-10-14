<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting\Dataset;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Accounting\Dataset\LedgerRange;

class LedgerRangeTest extends TestCase
{
    /** @return array<string, array{0: int, 1: int, 2: int, 3: array{from: int, to: int, granularity: string}}> */
    public static function ranges(): array
    {
        return [
            'jeden měsíc' => [8, 8, 12, ['from' => 8, 'to' => 8, 'granularity' => 'month']],
            'srpen–září = Q3' => [8, 9, 12, ['from' => 7, 'to' => 9, 'granularity' => 'quarter']],
            'červenec–září = Q3' => [7, 9, 12, ['from' => 7, 'to' => 9, 'granularity' => 'quarter']],
            'srpen–říjen = 2. pololetí' => [8, 10, 12, ['from' => 7, 'to' => 12, 'granularity' => 'halfYear']],
            'červen–červenec = rok' => [6, 7, 12, ['from' => 1, 'to' => 12, 'granularity' => 'year']],
            'leden–únor = Q1' => [1, 2, 12, ['from' => 1, 'to' => 3, 'granularity' => 'quarter']],
            'kratší rok' => [8, 9, 9, ['from' => 7, 'to' => 9, 'granularity' => 'quarter']],
            'čtvrtletí přes konec kratšího roku = rok' => [7, 8, 8, ['from' => 1, 'to' => 8, 'granularity' => 'year']],
        ];
    }

    /** @param array{from: int, to: int, granularity: string} $expected */
    #[DataProvider('ranges')]
    public function testPick(int $min, int $max, int $count, array $expected): void
    {
        $this->assertSame($expected, LedgerRange::pick($min, $max, $count));
    }

    public function testInvalidInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LedgerRange::pick(9, 8, 12);
    }
}
