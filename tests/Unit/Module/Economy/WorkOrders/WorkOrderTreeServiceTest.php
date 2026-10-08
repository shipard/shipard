<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\WorkOrders\WorkOrderTreeService;
use Shipard\Module\Economy\WorkOrders\WorkOrderTypes;

/**
 * Strom zakázek (D15) a zákazník „z nadřazené“ (D14): podzakázky, řetězec
 * předků se stropem, zákazník interní jednorázové z nejbližší externí
 * zakázky v řetězci.
 */
class WorkOrderTreeServiceTest extends TestCase
{
    private const TYPES = [
        'project'  => ['name' => 'Externí jednorázová', 'external' => true, 'oneOff' => true],
        'overhead' => ['name' => 'Interní průběžná', 'external' => false, 'oneOff' => false],
        'internal' => ['name' => 'Interní jednorázová', 'external' => false, 'oneOff' => true],
    ];

    /** 1 (externí, zákazník 50) → 2 (interní bez zákazníka) → 3 (interní) ; 4 externí bez zákazníka → 5 interní */
    public const ROWS = [
        1 => ['id' => 1, 'number' => 'Z260001', 'title' => 'Areál', 'type' => 'project', 'parent' => null, 'customer' => 50, 'customer_name' => 'Alfa s.r.o.', 'docState' => 40],
        2 => ['id' => 2, 'number' => 'I0002', 'title' => 'Hala', 'type' => 'internal', 'parent' => 1, 'customer' => null, 'customer_name' => null, 'docState' => 40],
        3 => ['id' => 3, 'number' => null, 'title' => 'Střecha', 'type' => 'internal', 'parent' => 2, 'customer' => null, 'customer_name' => null, 'docState' => 10],
        4 => ['id' => 4, 'number' => 'Z260004', 'title' => 'Bez zákazníka', 'type' => 'project', 'parent' => null, 'customer' => null, 'customer_name' => null, 'docState' => 10],
        5 => ['id' => 5, 'number' => 'I0005', 'title' => 'Podřízená', 'type' => 'internal', 'parent' => 4, 'customer' => null, 'customer_name' => null, 'docState' => 40],
        // cyklus v datech — strop průchodu
        8 => ['id' => 8, 'number' => 'C8', 'title' => 'A', 'type' => 'internal', 'parent' => 9, 'customer' => null, 'customer_name' => null, 'docState' => 40],
        9 => ['id' => 9, 'number' => 'C9', 'title' => 'B', 'type' => 'internal', 'parent' => 8, 'customer' => null, 'customer_name' => null, 'docState' => 40],
    ];

    private function service(): WorkOrderTreeService
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([[WorkOrderTypes::CFG_ITEM, self::TYPES]]);
        return new class(new WorkOrderTypes($config)) extends WorkOrderTreeService {
            public function __construct(WorkOrderTypes $types)
            {
                parent::__construct(null, $types);
            }

            protected function loadRow(int $id): ?array
            {
                return WorkOrderTreeServiceTest::ROWS[$id] ?? null;
            }

            protected function loadChildren(int $id): array
            {
                return array_values(array_filter(
                    WorkOrderTreeServiceTest::ROWS,
                    static fn(array $r): bool => (int) ($r['parent'] ?? 0) === $id && $r['docState'] !== 90,
                ));
            }
        };
    }

    public function testChildrenAndAncestors(): void
    {
        $service = $this->service();

        $this->assertSame(
            [['id' => 2, 'number' => 'I0002', 'title' => 'Hala', 'type' => 'internal', 'docState' => 40, 'customerName' => null]],
            $service->children(1),
        );
        $this->assertSame([], $service->children(3));

        $this->assertSame([2, 1], array_column($service->ancestors(3), 'id'));
        $this->assertSame([], $service->ancestors(1));
        $this->assertSame([], $service->ancestors(99));
    }

    public function testCycleInDataStopsAtDepthLimit(): void
    {
        $this->assertCount(WorkOrderTreeService::MAX_DEPTH, $this->service()->ancestors(8));
    }

    public function testEffectiveCustomer(): void
    {
        $service = $this->service();

        // Externí: vlastní zákazník.
        $this->assertSame(['id' => 50, 'name' => 'Alfa s.r.o.', 'from' => null], $service->effectiveCustomer(self::ROWS[1]));
        $this->assertNull($service->effectiveCustomer(self::ROWS[4]));

        // Interní jednorázová: z nejbližší externí zakázky v řetězci, i přes dva stupně.
        $this->assertSame(
            ['id' => 50, 'name' => 'Alfa s.r.o.', 'from' => ['id' => 1, 'number' => 'Z260001', 'title' => 'Areál']],
            $service->effectiveCustomer(self::ROWS[3]),
        );
        // Externí předek bez zákazníka nic nedá.
        $this->assertNull($service->effectiveCustomer(self::ROWS[5]));
        // Bez konfigurace typů není nic externí.
        $this->assertNull((new WorkOrderTreeService(null, new WorkOrderTypes(null)))->effectiveCustomer(self::ROWS[1]));
    }
}
