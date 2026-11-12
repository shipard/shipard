<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Enrich;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Enrich\ItemContentTags;

/**
 * Štítky položek podle kódu (#89 D19): jeden dotaz na sadu kódů, jen živé
 * otagované položky, klíč mapy bez rozlišení velikosti písmen.
 */
final class ItemContentTagsTest extends TestCase
{
    public function testReadsTagsOfAllCodesWithOneQuery(): void
    {
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('fetchAll')->willReturnCallback(
            static function (mixed ...$args) use (&$captured): array {
                $captured = $args;
                return [
                    new Row(['code' => 'CEST', 'content_tags' => '["travel.accommodation","travel.fares"]']),
                    new Row(['code' => 'Ostat', 'content_tags' => '["vehicle.parking", "", 7, "services.shipping"]']),
                ];
            },
        );

        $map = ItemContentTags::forCodes($db, ['CEST', ' ostat ', 'cest', '', 'NEZNAMA']);

        $this->assertSame(
            [
                'cest'  => ['travel.accommodation', 'travel.fares'],
                'ostat' => ['vehicle.parking', 'services.shipping'],
            ],
            $map,
        );
        $this->assertStringContainsString('economy_items', (string) $captured[0]);
        $this->assertStringContainsString('content_tags', (string) $captured[0]);
        // Kódy bez duplicit a prázdných hodnot; jen živé položky.
        $this->assertSame(['CEST', 'ostat', 'NEZNAMA'], $captured[1]);
        $this->assertSame([10, 40, 80], $captured[2]);
    }

    public function testNoCodesMeansNoQuery(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetchAll');

        $this->assertSame([], ItemContentTags::forCodes($db, []));
        $this->assertSame([], ItemContentTags::forCodes($db, ['', '  ']));
    }

    public function testBrokenOrEmptyTagsAreSkipped(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturn([
            new Row(['code' => 'A', 'content_tags' => 'not json']),
            new Row(['code' => 'B', 'content_tags' => '[]']),
            new Row(['code' => 'C', 'content_tags' => '{"tag":"x"}']),
            // Duplicitní kód: platí první otagovaná položka.
            new Row(['code' => 'D', 'content_tags' => '["travel.fares"]']),
            new Row(['code' => 'd', 'content_tags' => '["it.software"]']),
        ]);

        $this->assertSame(
            ['c' => ['x'], 'd' => ['travel.fares']],
            ItemContentTags::forCodes($db, ['A', 'B', 'C', 'D']),
        );
    }
}
