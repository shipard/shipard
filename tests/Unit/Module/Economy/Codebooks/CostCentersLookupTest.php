<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Codebooks\CostCentersLookup;

/**
 * Lookup střediska pro pole dimenze na dokladech (#110 D23): nabízí jen
 * platné záznamy číselníku (ne archiv, ne koš), hledá v kódu a názvu,
 * popisek `kód — název`.
 */
class CostCentersLookupTest extends TestCase
{
    /** @param list<array<string, mixed>> $rows */
    private function lookup(array $rows, ?string &$sql = null, ?array &$args = null): CostCentersLookup
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $query, mixed ...$params) use ($rows, &$sql, &$args): array {
                $sql = $query;
                $args = $params;
                return $rows;
            },
        );
        $lookup = new CostCentersLookup();
        $lookup->setDb($db);
        return $lookup;
    }

    public function testSearchOffersOnlyValidRecordsOrderedByCodebook(): void
    {
        $lookup = $this->lookup([
            ['id' => 3, 'code' => 'S01', 'name' => 'Správa'],
            ['id' => 5, 'code' => 'V02', 'name' => ''],
        ], $sql, $args);

        $items = $lookup->search('sp', [], 20);

        $this->assertStringContainsString('`docState` IN (10, 40, 80)', $sql);
        $this->assertStringContainsString('ORDER BY `sort_order` ASC, `name` ASC LIMIT %i', $sql);
        $this->assertSame(20, end($args));
        $this->assertStringContainsString('`code`', $sql);
        $this->assertStringContainsString('`name`', $sql);

        $this->assertSame([3, 5], array_map(static fn($item): int => $item->id, $items));
        $this->assertSame('S01 — Správa', $items[0]->primary);
        // Bez názvu zůstane jen to, co záznam má.
        $this->assertSame('#5', $items[1]->primary);
    }

    public function testResolveSkipsInvalidIdsAndReadsByIds(): void
    {
        $lookup = $this->lookup([['id' => 3, 'code' => 'S01', 'name' => 'Správa']], $sql, $args);

        $items = $lookup->resolve(['3', 0, 'x']);

        $this->assertStringContainsString('WHERE `id` IN %in', $sql);
        $this->assertSame([[3]], $args);
        $this->assertSame('S01 — Správa', $items[0]->primary);
        $this->assertSame([], $lookup->resolve([]));
        $this->assertSame([], $lookup->resolve([0, 'x']));
    }

    public function testWithoutDatabaseReturnsNothing(): void
    {
        $lookup = new CostCentersLookup();
        $this->assertSame([], $lookup->search('a', [], 5));
        $this->assertSame([], $lookup->resolve([1]));
        $this->assertSame([], $lookup->getAllowedFilterKeys());
    }
}
