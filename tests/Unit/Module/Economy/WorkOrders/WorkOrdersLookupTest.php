<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\WorkOrders\WorkOrdersLookup;

/**
 * Lookup zakázek: V pořádku a V opravě pro dimenzi na dokladech, s filtrem
 * role=parent i koncepty; hledání v čísle, názvu a zákazníkovi; popisek
 * `číslo — název` se zákazníkem jako sekundárním textem.
 */
class WorkOrdersLookupTest extends TestCase
{
    /** @param list<array<string, mixed>> $rows */
    private function lookup(array $rows, ?string &$sql = null, ?array &$args = null): WorkOrdersLookup
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $query, mixed ...$params) use ($rows, &$sql, &$args): array {
                $sql = $query;
                $args = $params;
                return $rows;
            },
        );
        $lookup = new WorkOrdersLookup();
        $lookup->setDb($db);
        return $lookup;
    }

    public function testSearchOffersConfirmedAndEditedByDefault(): void
    {
        $lookup = $this->lookup([
            ['id' => 5, 'number' => 'Z260007', 'title' => 'Rekonstrukce haly', 'customer_name' => 'Alfa s.r.o.'],
            ['id' => 6, 'number' => null, 'title' => 'Koncept', 'customer_name' => null],
        ], $sql, $args);

        $items = $lookup->search('hala', [], 20);

        $this->assertStringContainsString('WHERE w.`docState` IN %in', $sql);
        $this->assertSame([40, 80], $args[0]);
        $this->assertStringContainsString('p.`full_name`', $sql);
        $this->assertStringContainsString('ORDER BY w.`number` DESC, w.`title` ASC LIMIT %i', $sql);
        $this->assertSame(20, end($args));

        $this->assertSame('Z260007 — Rekonstrukce haly', $items[0]->primary);
        $this->assertSame('Alfa s.r.o.', $items[0]->secondary);
        $this->assertSame('Koncept', $items[1]->primary);
        $this->assertNull($items[1]->secondary);
    }

    public function testParentRoleAddsDrafts(): void
    {
        $lookup = $this->lookup([], $sql, $args);
        $lookup->search('', [WorkOrdersLookup::FILTER_ROLE => WorkOrdersLookup::ROLE_PARENT], 10);

        $this->assertSame([10, 40, 80], $args[0]);
        $this->assertSame([WorkOrdersLookup::FILTER_ROLE], $lookup->getAllowedFilterKeys());
    }

    public function testResolveReadsByIds(): void
    {
        $lookup = $this->lookup([['id' => 5, 'number' => 'Z260007', 'title' => 'Hala', 'customer_name' => null]], $sql, $args);

        $items = $lookup->resolve(['5', 0, 'x']);

        $this->assertStringContainsString('WHERE w.`id` IN %in', $sql);
        $this->assertSame([[5]], $args);
        $this->assertSame('Z260007 — Hala', $items[0]->primary);
        $this->assertSame([], $lookup->resolve([]));
        $this->assertSame([], (new WorkOrdersLookup())->search('a', [], 5));
    }
}
