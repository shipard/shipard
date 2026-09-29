<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Assets\AssetNumberAllocator;

/** Přidělení inventárního čísla — čistá funkce (docs/assets.md D22). */
class AssetNumberAllocatorTest extends TestCase
{
    public function testEmptyTableStartsAtOne(): void
    {
        $this->assertSame('MA0001', AssetNumberAllocator::next('MA', []));
    }

    public function testContinuesAfterHighestNumber(): void
    {
        $this->assertSame(
            'MA0042',
            AssetNumberAllocator::next('MA', ['MA0003', 'MA0041', 'MA0007']),
        );
    }

    public function testLongerNumberKeepsAllDigits(): void
    {
        $this->assertSame(
            'MA12346',
            AssetNumberAllocator::next('MA', ['MA0041', 'MA12345']),
        );
    }

    public function testOtherPrefixAndOffPatternNumbersAreIgnored(): void
    {
        $this->assertSame(
            'MA0002',
            AssetNumberAllocator::next('MA', ['DHM0099', 'MAX0100', 'MA-7', 'MA0001', null]),
        );
    }

    public function testPrefixWithRegexCharactersIsQuoted(): void
    {
        $this->assertSame('A.B0002', AssetNumberAllocator::next('A.B', ['A.B0001', 'AXB0500']));
    }

    public function testTooLongPrefixThrowsDomainException(): void
    {
        $this->expectException(\DomainException::class);
        AssetNumberAllocator::next(str_repeat('X', 17), []);
    }
}
