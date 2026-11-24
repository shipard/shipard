<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Places;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Base\Places\PlacesLookup;
use Shipard\Module\Base\Places\PlaceTree;
use Shipard\Module\Base\Places\PlaceTypes;

/**
 * Lookup míst (#169 D12): filtr `childType` nabízí jen místa, pod která typ
 * smí; `exclude` vynechá místo s celým podstromem; řazení podle cesty.
 */
class PlacesLookupTest extends TestCase
{
    private const TYPES_FILE = __DIR__ . '/../../../../../modules/base/places/config/types.jsonc';

    /**
     * Areál(1) › Budova A(2) › Křídlo(3) › 1. NP(4) › Kancelář 101(5) › Stůl(6)
     *          › Parkoviště(7) › Stání 1(8)
     * Ostatní(9) na nejvyšší úrovni; Starý sklad(10) v archivu.
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(): array
    {
        $row = static fn (int $id, ?int $parent, string $name, string $type, string $kind, int $state = 40, ?string $short = null): array => [
            'id' => $id, 'parent' => $parent, 'name' => $name, 'short_name' => $short, 'code' => null,
            'order_pos' => $id, 'docState' => $state, 'kind' => 1, 'kind_name' => $kind, 'kind_type' => $type, 'kind_icon' => null,
        ];
        return [
            $row(1, null, 'Areál firmy', 'site', 'Areál', short: 'Areál'),
            $row(2, 1, 'Budova A', 'building', 'Budova', short: 'A'),
            $row(3, 2, 'Křídlo', 'section', 'Část budovy'),
            $row(4, 3, '1. NP', 'floor', 'Podlaží'),
            $row(5, 4, 'Kancelář 101', 'room', 'Kancelář'),
            $row(6, 5, 'Stůl u okna', 'spot', 'Pracovní místo'),
            $row(7, 1, 'Parkoviště', 'outdoor', 'Venkovní plocha'),
            $row(8, 7, 'Stání 1', 'spot', 'Parkovací stání'),
            $row(9, null, 'Ostatní prostory', 'other', 'Ostatní'),
            $row(10, 1, 'Starý sklad', 'building', 'Budova', 70),
        ];
    }

    /**
     * @param list<int>|null $matching id, která vrátí hledání v SQL (null = všechna živá)
     */
    private function lookup(?array $matching = null, ?string &$sql = null, ?array &$args = null): PlacesLookup
    {
        $rows = self::rows();
        $live = array_values(array_filter($rows, static fn (array $r): bool => in_array($r['docState'], [10, 40, 80], true)));
        $ids = $matching ?? array_column($live, 'id');

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $query, mixed ...$params) use ($ids, $rows, &$sql, &$args): array {
                $sql = $query;
                $args = $params;
                if (str_contains($query, 'IN %in') && str_contains($query, 'kind_name')) {
                    // resolve(): řádky podle id
                    return array_values(array_filter($rows, static fn (array $r): bool => in_array($r['id'], $params[0], true)));
                }
                return array_map(static fn (int $id): array => ['id' => $id], $ids);
            },
        );

        $tree = new class($rows) extends PlaceTree {
            /** @param list<array<string, mixed>> $rows */
            public function __construct(private readonly array $rows)
            {
                parent::__construct(null);
            }

            protected function loadAll(): array
            {
                return $this->rows;
            }
        };

        $lookup = new class($tree) extends PlacesLookup {
            public function __construct(private readonly PlaceTree $fixture)
            {
            }

            protected function tree(): PlaceTree
            {
                return $this->fixture;
            }
        };
        $lookup->setDb($db);

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            [PlaceTypes::CFG_ITEM, JsoncParser::parseFile(self::TYPES_FILE)],
        ]);
        $lookup->setConfig($config);
        return $lookup;
    }

    /** @return list<string> */
    private function names(array $items): array
    {
        return array_map(static fn ($item): string => $item->primary, $items);
    }

    public function testAllowedFilterKeys(): void
    {
        $this->assertSame(['childType', 'exclude'], $this->lookup()->getAllowedFilterKeys());
    }

    public function testSearchQueriesLivePlacesByNameShortNameAndCode(): void
    {
        $lookup = $this->lookup([5], $sql, $args);

        $items = $lookup->search('101', [], 20);

        $this->assertSame([10, 40, 80], $args[0]);
        $this->assertSame(['101', '101', '101'], array_slice($args, 1));
        foreach (['p.`name`', 'p.`short_name`', 'p.`code`'] as $column) {
            $this->assertStringContainsString($column, $sql);
        }
        $this->assertSame('Kancelář 101', $items[0]->primary);
        $this->assertSame('Areál › A › Křídlo › 1. NP · Kancelář', $items[0]->secondary);
    }

    public function testTopLevelPlaceShowsOnlyItsKind(): void
    {
        $items = $this->lookup([1])->search('', [], 20);

        $this->assertSame('Areál firmy', $items[0]->primary);
        $this->assertSame('Areál', $items[0]->secondary);
    }

    public function testResultsAreSortedByPathAndName(): void
    {
        $this->assertSame(
            [
                'Areál firmy', 'Ostatní prostory',  // nejvyšší úroveň (prázdná cesta), podle názvu
                'Budova A', 'Parkoviště',           // Areál
                'Křídlo',                           // Areál › A
                '1. NP',                            // Areál › A › Křídlo
                'Kancelář 101',                     // Areál › A › Křídlo › 1. NP
                'Stůl u okna',                      // … › Kancelář 101
                'Stání 1',                          // Areál › Parkoviště
            ],
            $this->names($this->lookup()->search('', [], 50)),
        );
    }

    public function testChildTypeRoomOffersOnlyPlacesARoomMaySitUnder(): void
    {
        $names = $this->names($this->lookup()->search('', ['childType' => 'room'], 50));
        sort($names);

        // Areál, budova, část budovy, podlaží a Ostatní; ne místnost,
        // venkovní plocha (stejná úroveň) ani místa.
        $this->assertSame(['1. NP', 'Areál firmy', 'Budova A', 'Křídlo', 'Ostatní prostory'], $names);
    }

    public function testOutdoorAreaIsOfferedOnlyForSpots(): void
    {
        $forSpot = $this->names($this->lookup()->search('', ['childType' => 'spot'], 50));
        $this->assertContains('Parkoviště', $forSpot);
        $this->assertContains('Kancelář 101', $forSpot);
        $this->assertNotContains('Stání 1', $forSpot);
        $this->assertNotContains('Stůl u okna', $forSpot);

        foreach (['site', 'building', 'section', 'floor', 'room', 'outdoor'] as $type) {
            $this->assertNotContains('Parkoviště', $this->names($this->lookup()->search('', ['childType' => $type], 50)), $type);
        }
    }

    public function testChildTypeOtherOffersEverything(): void
    {
        $this->assertCount(9, $this->lookup()->search('', ['childType' => 'other'], 50));
    }

    public function testExcludeLeavesOutThePlaceAndItsWholeSubtree(): void
    {
        // Úprava Budovy A: bez ní a všeho pod ní.
        $names = $this->names($this->lookup()->search('', ['exclude' => '2'], 50));
        sort($names);
        $this->assertSame(['Areál firmy', 'Ostatní prostory', 'Parkoviště', 'Stání 1'], $names);
    }

    public function testFiltersCombineForTheParentField(): void
    {
        // Úprava podlaží 1. NP: nenabízí své místnosti, sebe ani nic se
        // stejnou nebo vyšší úrovní — zbývá areál, budova, křídlo a Ostatní.
        $names = $this->names($this->lookup()->search('', ['childType' => 'floor', 'exclude' => 4], 50));
        sort($names);
        $this->assertSame(['Areál firmy', 'Budova A', 'Křídlo', 'Ostatní prostory'], $names);
    }

    public function testArchivedPlaceIsNotOfferedAndLimitApplies(): void
    {
        $lookup = $this->lookup();

        $this->assertNotContains('Starý sklad', $this->names($lookup->search('', [], 50)));
        $this->assertCount(3, $lookup->search('', [], 3));
    }

    public function testResolveReturnsTheSameShapeEvenForPlacesTheFilterWouldHide(): void
    {
        $items = $this->lookup()->resolve([5, '10', 0, 'x']);
        $byId = [];
        foreach ($items as $item) {
            $byId[$item->id] = $item;
        }

        $this->assertSame([5, 10], array_keys($byId));
        $this->assertSame('Kancelář 101', $byId[5]->primary);
        $this->assertSame('Areál › A › Křídlo › 1. NP · Kancelář', $byId[5]->secondary);
        // Archivované místo lookup nenabízí, vybrané ho ale ukáže.
        $this->assertSame('Starý sklad', $byId[10]->primary);
        $this->assertSame('Areál · Budova', $byId[10]->secondary);
        $this->assertSame([], $this->lookup()->resolve([]));
    }

    public function testDescribeJoinsPathAndKind(): void
    {
        $this->assertSame('Areál › A · Podlaží', PlacesLookup::describe('Areál › A', 'Podlaží'));
        $this->assertSame('Podlaží', PlacesLookup::describe('', 'Podlaží'));
        $this->assertSame('Areál', PlacesLookup::describe('Areál', ''));
        $this->assertSame('', PlacesLookup::describe('', ''));
    }
}
