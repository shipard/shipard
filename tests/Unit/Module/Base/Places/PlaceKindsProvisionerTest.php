<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Places;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Base\Places\PlaceKindsProvisioner;

/**
 * Seed druhů míst (#169 D14): jeden druh na typ, idempotentně podle
 * `system_code`; existující druh v jakémkoli stavu se nezakládá znovu.
 */
class PlaceKindsProvisionerTest extends TestCase
{
    private const TYPES = [
        'site'     => ['name' => 'Areál', 'level' => 10],
        'building' => ['name' => 'Budova', 'level' => 20],
        'other'    => ['name' => 'Ostatní', 'level' => null],
    ];

    /** @param list<array<string, mixed>> $rows výchozí obsah tabulky */
    private function db(\stdClass $store, array $rows = []): DataSourceConnection
    {
        $store->rows = $rows;

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static function (string $sql, mixed ...$params) use ($store): ?array {
                foreach ($store->rows as $row) {
                    if (($row['system_code'] ?? null) === $params[0]) {
                        return ['id' => $row['id']];
                    }
                }
                return null;
            },
        );
        $db->method('insertRow')->willReturnCallback(
            static function (string $table, array $data) use ($store): int {
                $id = count($store->rows) + 1;
                $store->rows[] = ['id' => $id, '_table' => $table] + $data;
                return $id;
            },
        );
        return $db;
    }

    public function testCreatesOneConfirmedKindPerType(): void
    {
        $store = new \stdClass();
        $result = new PlaceKindsProvisioner($this->db($store), self::TYPES)->provision();

        $this->assertSame(['kinds' => ['created' => 3, 'existing' => 0]], $result);
        $this->assertSame(['Areál', 'Budova', 'Ostatní'], array_column($store->rows, 'name'));
        $this->assertSame(['site', 'building', 'other'], array_column($store->rows, 'type'));
        $this->assertSame(['site', 'building', 'other'], array_column($store->rows, 'system_code'));
        foreach ($store->rows as $row) {
            $this->assertSame(PlaceKindsProvisioner::TABLE, $row['_table']);
            $this->assertSame(40, $row['docState']);
            $this->assertSame(3, $row['docStateMain']);
            $this->assertNull($row['icon']);
        }
    }

    public function testSecondRunCreatesNothing(): void
    {
        $store = new \stdClass();
        $db = $this->db($store);

        new PlaceKindsProvisioner($db, self::TYPES)->provision();
        $result = new PlaceKindsProvisioner($db, self::TYPES)->provision();

        $this->assertSame(['kinds' => ['created' => 0, 'existing' => 3]], $result);
        $this->assertCount(3, $store->rows);
    }

    public function testArchivedTrashedAndRenamedKindIsNotSeededAgain(): void
    {
        $store = new \stdClass();
        $db = $this->db($store, [
            ['id' => 1, 'name' => 'Náš kampus', 'type' => 'site', 'system_code' => 'site', 'docState' => 40],
            ['id' => 2, 'name' => 'Budova', 'type' => 'building', 'system_code' => 'building', 'docState' => 70],
            ['id' => 3, 'name' => 'Ostatní', 'type' => 'other', 'system_code' => 'other', 'docState' => 90],
        ]);

        $result = new PlaceKindsProvisioner($db, self::TYPES)->provision();

        $this->assertSame(['kinds' => ['created' => 0, 'existing' => 3]], $result);
        $this->assertSame(['Náš kampus', 'Budova', 'Ostatní'], array_column($store->rows, 'name'));
        $this->assertSame([40, 70, 90], array_column($store->rows, 'docState'));
    }

    public function testUserKindWithoutSystemCodeDoesNotBlockTheSeed(): void
    {
        $store = new \stdClass();
        $db = $this->db($store, [
            ['id' => 1, 'name' => 'Budova', 'type' => 'building', 'system_code' => null, 'docState' => 40],
        ]);

        $result = new PlaceKindsProvisioner($db, self::TYPES)->provision();

        $this->assertSame(['kinds' => ['created' => 3, 'existing' => 0]], $result);
    }
}
