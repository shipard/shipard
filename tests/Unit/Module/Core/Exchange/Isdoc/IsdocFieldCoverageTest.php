<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Isdoc;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Compare\FieldOutcome;
use Shipard\Module\Core\Exchange\Compare\FieldState;
use Shipard\Module\Core\Exchange\Compare\Projection;
use Shipard\Module\Core\Exchange\Isdoc\IsdocFieldCoverage;

/**
 * Jediný zdroj polí, která ISDOC nenese (#103 D76): každá cesta existuje
 * v papírové projekci korpusu, důvod u každé, přemapování výsledků
 * porovnání na `notCarried` podle uzlu (indexy v cestě nehrají roli,
 * potomci uzlu také).
 */
final class IsdocFieldCoverageTest extends TestCase
{
    private const PROJECTION = __DIR__ . '/../../../../../corpus/projection.jsonc';

    public function testEveryPathExistsInTheProjectionWithAReason(): void
    {
        $paths = Projection::load(self::PROJECTION)->nodePaths();
        $this->assertCount(9, IsdocFieldCoverage::NOT_CARRIED);
        foreach (IsdocFieldCoverage::NOT_CARRIED as $path => $reason) {
            $this->assertContains($path, $paths, "{$path} v projekci není");
            $this->assertNotSame('', trim($reason), $path);
            $this->assertStringNotContainsString('_resolve', $path, 'štítek se v evaluaci měří');
        }
        $this->assertSame(array_keys(IsdocFieldCoverage::NOT_CARRIED), IsdocFieldCoverage::paths());
    }

    public function testReasonMatchesNodeWithIndexesAndDescendants(): void
    {
        $this->assertNotNull(IsdocFieldCoverage::reasonFor('rows[2].discountPct'));
        $this->assertNotNull(IsdocFieldCoverage::reasonFor('rows[].vat.supplyKind'));
        $this->assertNotNull(IsdocFieldCoverage::reasonFor('vat.place'));
        $this->assertNull(IsdocFieldCoverage::reasonFor('vat.placeholder'), 'jen celý segment, ne prefix textu');
        $this->assertNull(IsdocFieldCoverage::reasonFor('rows'));
        $this->assertNull(IsdocFieldCoverage::reasonFor('rows[0].vat.pct'));
        $this->assertNull(IsdocFieldCoverage::reasonFor('_resolve.contentTag.tag'));
    }

    public function testMarkNotCarriedKeepsValuesAndOtherOutcomes(): void
    {
        $outcomes = [
            new FieldOutcome('docNumber', FieldState::Wrong, 'A', 'B'),
            new FieldOutcome('vat.place', FieldState::Missing, 'cz', null),
            new FieldOutcome('rows[1].priceCalcMode', FieldState::Match, 'fromUnitPrice', 'fromUnitPrice'),
            new FieldOutcome('supplier.contact.web', FieldState::Extra, null, 'example.cz'),
        ];
        $marked = IsdocFieldCoverage::markNotCarried($outcomes);

        $this->assertSame($outcomes[0], $marked[0]);
        $this->assertSame([FieldState::Wrong, FieldState::NotCarried, FieldState::NotCarried, FieldState::NotCarried], array_column($marked, 'state'));
        $this->assertSame('cz', $marked[1]->expected);
        $this->assertNull($marked[1]->actual);
        $this->assertSame(IsdocFieldCoverage::NOT_CARRIED['vat.place'], $marked[1]->reason);
        $this->assertSame(['path' => 'vat.place', 'state' => 'notCarried', 'expected' => 'cz', 'actual' => null, 'reason' => IsdocFieldCoverage::NOT_CARRIED['vat.place']], $marked[1]->toArray());
        $this->assertFalse(FieldState::NotCarried->isHit());
        $this->assertFalse(FieldState::NotCarried->isCounted());
        $this->assertTrue(FieldState::Missing->isCounted());
    }
}
