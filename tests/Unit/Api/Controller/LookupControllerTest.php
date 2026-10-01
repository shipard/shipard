<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\LookupController;
use Shipard\Api\Request;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\LookupRegistry;
use Shipard\Core\Form\Lookup\TableLookup;

class LookupControllerTest extends TestCase
{
    private LookupController $ctrl;
    private DataSourceConnection $db;

    protected function setUp(): void
    {
        $this->ctrl = new LookupController();
        $this->db = $this->createMock(DataSourceConnection::class);
    }

    private function makeTable(string $name): TableDefinition
    {
        return TableDefinition::fromArray([
            'tableId' => 1,
            'name'    => $name,
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
            ],
        ]);
    }

    private function makeRequest(array $queryParams = []): Request
    {
        return Request::fromArray('GET', '/api/v1/_ui/lookup/test_table/search', $queryParams, '', []);
    }

    private function auth(): AuthContext
    {
        return new AuthContext(true, 2, 'session', 'shpd_st_t');
    }

    private function registryWith(string $table, TableLookup $instance): LookupRegistry
    {
        $registry = new LookupRegistry();
        $registry->register($table, $instance::class);
        return $registry;
    }

    public function testSearchReturnsItems(): void
    {
        $tables = ['t' => $this->makeTable('Test')];
        $registry = $this->registryWith('t', new FakeControllerLookup());

        $resp = $this->ctrl->search('t', $this->makeRequest(['q' => 'foo']), $this->auth(), $tables, $this->db, $registry, null);
        $payload = $resp->getPayload();

        $this->assertTrue($payload['success']);
        $this->assertSame(20, $payload['data']['limit']);
        $this->assertNull($payload['data']['total']);
        $this->assertCount(1, $payload['data']['items']);
        $this->assertSame(['id' => 42, 'primary' => 'foo', 'secondary' => null], $payload['data']['items'][0]);
    }

    public function testSearchUnknownTableReturns404(): void
    {
        $registry = new LookupRegistry();

        $resp = $this->ctrl->search('unknown', $this->makeRequest(), $this->auth(), [], $this->db, $registry, null);
        $payload = $resp->getPayload();

        $this->assertFalse($payload['success']);
        $this->assertSame('TABLE_NOT_FOUND', $payload['error']['code']);
    }

    public function testSearchUnregisteredLookupReturns404(): void
    {
        $tables = ['t' => $this->makeTable('Test')];
        $registry = new LookupRegistry();

        $resp = $this->ctrl->search('t', $this->makeRequest(), $this->auth(), $tables, $this->db, $registry, null);
        $payload = $resp->getPayload();

        $this->assertFalse($payload['success']);
        $this->assertSame('LOOKUP_NOT_REGISTERED', $payload['error']['code']);
    }

    public function testSearchLimitClampedToMax(): void
    {
        $tables = ['t' => $this->makeTable('Test')];
        $registry = $this->registryWith('t', new FakeControllerLookup());

        $resp = $this->ctrl->search('t', $this->makeRequest(['limit' => '500']), $this->auth(), $tables, $this->db, $registry, null);

        $this->assertSame(50, $resp->getPayload()['data']['limit']);
    }

    public function testSearchRejectsZeroLimit(): void
    {
        $tables = ['t' => $this->makeTable('Test')];
        $registry = $this->registryWith('t', new FakeControllerLookup());

        $resp = $this->ctrl->search('t', $this->makeRequest(['limit' => '0']), $this->auth(), $tables, $this->db, $registry, null);

        $this->assertSame('BAD_REQUEST', $resp->getPayload()['error']['code']);
    }

    public function testSearchRejectsNonNumericLimit(): void
    {
        $tables = ['t' => $this->makeTable('Test')];
        $registry = $this->registryWith('t', new FakeControllerLookup());

        $resp = $this->ctrl->search('t', $this->makeRequest(['limit' => 'abc']), $this->auth(), $tables, $this->db, $registry, null);

        $this->assertSame('BAD_REQUEST', $resp->getPayload()['error']['code']);
    }

    public function testSearchAllowedFilterKeyForwarded(): void
    {
        $tables = ['t' => $this->makeTable('Test')];
        $registry = $this->registryWith('t', new FakePersonFilterLookup());

        $resp = $this->ctrl->search(
            't',
            $this->makeRequest(['filter' => ['person' => '42']]),
            $this->auth(),
            $tables,
            $this->db,
            $registry,
            null,
        );

        $this->assertTrue($resp->getPayload()['success']);
        $this->assertSame(['person' => '42'], FakePersonFilterLookup::$lastFilter);
    }

    public function testSearchUnknownFilterKeyDropped(): void
    {
        $tables = ['t' => $this->makeTable('Test')];
        $registry = $this->registryWith('t', new FakePersonFilterLookup());

        $this->ctrl->search(
            't',
            $this->makeRequest(['filter' => ['person' => '42', 'rogue' => '1']]),
            $this->auth(),
            $tables,
            $this->db,
            $registry,
            null,
        );

        $this->assertSame(['person' => '42'], FakePersonFilterLookup::$lastFilter);
    }

    public function testResolveByCommaSeparatedIds(): void
    {
        $tables = ['t' => $this->makeTable('Test')];
        $registry = $this->registryWith('t', new FakeControllerLookup());

        $req = Request::fromArray('GET', '/r', ['ids' => '1,2,abc'], '', []);
        $resp = $this->ctrl->resolve('t', $req, $this->auth(), $tables, $this->db, $registry, null);

        $this->assertTrue($resp->getPayload()['success']);
        $this->assertSame([1, 2, 'abc'], FakeControllerLookup::$lastResolveIds);
    }

    public function testResolveEmptyIdsReturnsEmpty(): void
    {
        $tables = ['t' => $this->makeTable('Test')];
        $registry = $this->registryWith('t', new FakeControllerLookup());

        $req = Request::fromArray('GET', '/r', ['ids' => ''], '', []);
        $resp = $this->ctrl->resolve('t', $req, $this->auth(), $tables, $this->db, $registry, null);

        $this->assertSame(['items' => []], $resp->getPayload()['data']);
    }

    // ── create-defaults (výchozí hodnoty nového záznamu z rodiče) ───────────

    private function defaultsTable(): TableDefinition
    {
        return TableDefinition::fromArray([
            'tableId' => 1,
            'name'    => 'Test',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
                ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 50],
                ['id' => 'price', 'name' => 'Price', 'type' => 'numeric', 'precision' => 12, 'scale' => 2, 'nullable' => true],
                ['id' => 'origin', 'name' => 'Origin', 'type' => 'varchar', 'length' => 10, 'system' => true],
            ],
        ]);
    }

    /** @param array<string, mixed>|null $body */
    private function defaultsRequest(?array $body): Request
    {
        return Request::fromArray(
            'POST',
            '/api/v1/_ui/lookup/t/create-defaults',
            [],
            $body === null ? '' : (string) json_encode($body),
            ['Content-Type' => 'application/json'],
        );
    }

    public function testCreateDefaultsPassesParentsAndFiltersColumns(): void
    {
        $tables = ['t' => $this->defaultsTable()];
        $registry = $this->registryWith('t', new FakeDefaultsLookup());
        FakeDefaultsLookup::$defaults = [
            'name' => 'Soustruh', 'price' => 1200.5,
            // Neznámý, systémový a PK sloupec ani neskalární hodnota neprojdou.
            'unknown' => 'x', 'origin' => 'import', 'id' => 5, 'nested' => ['a' => 1],
        ];

        $resp = $this->ctrl->createDefaults(
            't',
            $this->defaultsRequest(['row' => ['description' => 'Soustruh'], 'head' => ['accounting_date' => '2026-05-10']]),
            $this->auth(), $tables, $this->db, $registry, null,
        );
        $payload = $resp->getPayload();

        $this->assertTrue($payload['success']);
        $this->assertSame(['name' => 'Soustruh', 'price' => 1200.5], $payload['data']['defaults']);
        $this->assertSame(
            [['description' => 'Soustruh'], ['accounting_date' => '2026-05-10']],
            FakeDefaultsLookup::$lastParents,
        );
    }

    public function testCreateDefaultsWithoutBodyOrOverrideIsEmptyObject(): void
    {
        $tables = ['t' => $this->defaultsTable()];

        // Lookup bez přepsané createDefaults() → prázdný objekt, ne chyba.
        $resp = $this->ctrl->createDefaults(
            't', $this->defaultsRequest(null), $this->auth(), $tables, $this->db,
            $this->registryWith('t', new FakeControllerLookup()), null,
        );

        $this->assertTrue($resp->getPayload()['success']);
        $this->assertEquals(new \stdClass(), $resp->getPayload()['data']['defaults']);
    }

    public function testCreateDefaultsRejectsNonObjectParents(): void
    {
        $tables = ['t' => $this->defaultsTable()];
        $registry = $this->registryWith('t', new FakeDefaultsLookup());

        $resp = $this->ctrl->createDefaults(
            't', $this->defaultsRequest(['row' => 'x']), $this->auth(), $tables, $this->db, $registry, null,
        );

        $this->assertSame('BAD_REQUEST', $resp->getPayload()['error']['code']);
    }

    public function testCreateDefaultsUnknownTableAndLookup(): void
    {
        $resp = $this->ctrl->createDefaults('x', $this->defaultsRequest([]), $this->auth(), [], $this->db, new LookupRegistry(), null);
        $this->assertSame('TABLE_NOT_FOUND', $resp->getPayload()['error']['code']);

        $resp = $this->ctrl->createDefaults(
            't', $this->defaultsRequest([]), $this->auth(), ['t' => $this->defaultsTable()], $this->db, new LookupRegistry(), null,
        );
        $this->assertSame('LOOKUP_NOT_REGISTERED', $resp->getPayload()['error']['code']);
    }
}

class FakeDefaultsLookup extends TableLookup
{
    /** @var array{0: array<string, mixed>, 1: array<string, mixed>}|null */
    public static ?array $lastParents = null;
    /** @var array<string, mixed> */
    public static array $defaults = [];

    public function search(string $q, array $filter, int $limit): array
    {
        return [];
    }

    public function resolve(array $ids): array
    {
        return [];
    }

    public function createDefaults(array $parentRow, array $parentHead): array
    {
        self::$lastParents = [$parentRow, $parentHead];
        return self::$defaults;
    }
}

class FakeControllerLookup extends TableLookup
{
    /** @var list<int|string> */
    public static array $lastResolveIds = [];

    public function search(string $q, array $filter, int $limit): array
    {
        return [new LookupItem(id: 42, primary: $q !== '' ? $q : 'default')];
    }

    public function resolve(array $ids): array
    {
        self::$lastResolveIds = $ids;
        return [];
    }
}

class FakePersonFilterLookup extends TableLookup
{
    /** @var array<string, scalar> */
    public static array $lastFilter = [];

    public function search(string $q, array $filter, int $limit): array
    {
        self::$lastFilter = $filter;
        return [];
    }

    public function resolve(array $ids): array
    {
        return [];
    }

    public function getAllowedFilterKeys(): array
    {
        return ['person'];
    }
}
