<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\WorkOrders\WorkOrderDocument;
use Shipard\Module\Economy\WorkOrders\WorkOrderRowLockProvider;

final class TestableWorkOrderRowLockProvider extends WorkOrderRowLockProvider
{
    /** @var array<int, array{number: ?string, title: ?string, docState: int}> */
    public array $workOrders = [];
    /** @var list<list<int>> */
    public array $queries = [];

    public function withDb(): self
    {
        $this->db = (new \ReflectionClass(\Dibi\Connection::class))->newInstanceWithoutConstructor();
        return $this;
    }

    protected function loadWorkOrders(array $ids): array
    {
        $this->queries[] = $ids;
        return array_intersect_key($this->workOrders, array_flip($ids));
    }
}

/**
 * Zámek řádků předpisu podle stavu zakázky (tasks/work-orders-rows-readonly.md):
 * stavy z reálného config/docStates.jsonc — Koncept a V opravě pouští,
 * V pořádku, Ukončeno, Zrušeno a Smazáno zamykají; platí původní i nová
 * zakázka řádku, uložení i mazání.
 */
final class WorkOrderRowLockProviderTest extends TestCase
{
    private const TABLE = 'economy_work_orders_rows';
    private const MODULE = __DIR__ . '/../../../../../modules/economy/workOrders';

    private const WORK_ORDERS = [
        1 => ['number' => null, 'title' => 'Nájem haly', 'docState' => 10],
        2 => ['number' => 'P260002', 'title' => 'Servis', 'docState' => 80],
        3 => ['number' => 'P260003', 'title' => 'Nájem kanceláře', 'docState' => 40],
        4 => ['number' => 'P260004', 'title' => 'Úklid', 'docState' => 70],
        5 => ['number' => 'P260005', 'title' => 'Hosting', 'docState' => 30],
        6 => ['number' => null, 'title' => 'Zahozený koncept', 'docState' => 90],
    ];

    private function provider(bool $withStates = true, bool $withDb = true): TestableWorkOrderRowLockProvider
    {
        $p = new TestableWorkOrderRowLockProvider();
        if ($withDb) {
            $p->withDb();
        }
        $p->workOrders = self::WORK_ORDERS;
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $withStates && $id === WorkOrderDocument::DOC_STATES_CFG_ITEM
                ? JsoncParser::parseFile(self::MODULE . '/config/docStates.jsonc')
                : null,
        );
        $p->setConfig($config);
        return $p;
    }

    public function testDraftAndEditedWorkOrdersLeaveRowsFree(): void
    {
        $p = $this->provider();
        foreach ([1, 2] as $workOrder) {
            $row = ['id' => 11, 'work_order' => $workOrder, 'description' => 'Nájem'];
            $this->assertSame([], $p->lockReasons(self::TABLE, $row, null), "nový řádek, zakázka {$workOrder}");
            $this->assertSame([], $p->lockReasons(self::TABLE, $row, $row), "uložení / mazání, zakázka {$workOrder}");
        }
    }

    public function testReadOnlyStatesFromCfgItemLockRows(): void
    {
        $p = $this->provider();
        foreach ([3 => 'Confirmed', 4 => 'Finished', 5 => 'Cancelled', 6 => 'Deleted'] as $workOrder => $stateName) {
            $row = ['id' => 11, 'work_order' => $workOrder];
            // Uložení i mazání: data = uložený řádek.
            $reasons = $p->lockReasons(self::TABLE, $row, $row);
            $this->assertCount(1, $reasons, (string) $workOrder);
            $this->assertSame(WorkOrderRowLockProvider::SOURCE, $reasons[0]->source);
            $this->assertSame(460, $reasons[0]->subjectTableId);
            $this->assertSame($workOrder, $reasons[0]->subjectRowId);
            $this->assertSame($stateName, $reasons[0]->params['state'], (string) $workOrder);
        }
    }

    public function testNewRowIntoConfirmedWorkOrderIsBlocked(): void
    {
        $p = $this->provider();
        $reasons = $p->lockReasons(self::TABLE, ['work_order' => 3, 'description' => 'Nájem'], null);

        $this->assertCount(1, $reasons);
        $this->assertSame('Zakázka P260003 je jen ke čtení — řádky uprav přes V opravě.', $reasons[0]->title);
        $this->assertSame(['label' => 'P260003', 'state' => 'Confirmed', 'docState' => 40], $reasons[0]->params);
        $this->assertStringContainsString('V opravě', $reasons[0]->message);
    }

    public function testDeletedDraftWithoutNumberUsesTitleAsLabel(): void
    {
        $p = $this->provider();
        $reasons = $p->lockReasons(self::TABLE, ['work_order' => 6], null);

        $this->assertSame('Zahozený koncept', $reasons[0]->params['label']);
        $this->assertSame('Zakázka Zahozený koncept je jen ke čtení — řádky uprav přes V opravě.', $reasons[0]->title);
    }

    public function testMovingRowBetweenWorkOrdersChecksBothSides(): void
    {
        $p = $this->provider();
        $original = ['id' => 11, 'work_order' => 3];
        $data = ['id' => 11, 'work_order' => 1];

        $reasons = $p->lockReasons(self::TABLE, $data, $original);

        $this->assertCount(1, $reasons);
        $this->assertSame(3, $reasons[0]->subjectRowId);
        $this->assertEqualsCanonicalizing([1, 3], $p->queries[0]);

        // Opačný směr: z konceptu do potvrzené zakázky.
        $this->assertCount(1, $p->lockReasons(self::TABLE, $original, $data));
    }

    public function testSameWorkOrderOnBothSidesIsQueriedOnce(): void
    {
        $p = $this->provider();
        $row = ['id' => 11, 'work_order' => 3];

        $this->assertCount(1, $p->lockReasons(self::TABLE, $row, $row));
        $this->assertSame([[3]], $p->queries);
    }

    public function testRowWithoutWorkOrderIsSilent(): void
    {
        $p = $this->provider();
        $this->assertSame([], $p->lockReasons(self::TABLE, ['description' => 'x', 'work_order' => null], null));
        $this->assertSame([], $p->queries);
    }

    public function testWithoutCompiledStatesNothingIsLocked(): void
    {
        // DS před ds-upgrade: cfgItem chybí, readOnly se nedá určit — bez dotazu.
        $p = $this->provider(withStates: false);
        $row = ['id' => 11, 'work_order' => 3];

        $this->assertSame([], $p->lockReasons(self::TABLE, $row, $row));
        $this->assertSame([], $p->queries);
    }

    public function testWithoutDbProviderIsSilent(): void
    {
        $p = $this->provider(withDb: false);
        $this->assertSame([], $p->lockReasons(self::TABLE, ['work_order' => 3], null));
        $this->assertSame([], $p->queries);
    }
}
