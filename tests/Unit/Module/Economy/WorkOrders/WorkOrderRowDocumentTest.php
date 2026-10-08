<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\WorkOrders\WorkOrderRowDocument;

/**
 * Řádek předpisu zakázky: tvarová validace, pořadí nového řádku, trim.
 */
class WorkOrderRowDocumentTest extends TestCase
{
    private function doc(int $nextOrderPos = 4): WorkOrderRowDocument
    {
        return new class($nextOrderPos) extends WorkOrderRowDocument {
            public array $orderPosCalls = [];

            public function __construct(private readonly int $next)
            {
            }

            protected function nextOrderPos(int $workOrderId): int
            {
                $this->orderPosCalls[] = $workOrderId;
                return $this->next;
            }
        };
    }

    /** @return list<string> column:code */
    private function codes(WorkOrderRowDocument $doc, array $data): array
    {
        return array_map(
            static fn(array $e): string => $e['column'] . ':' . $e['code'],
            $doc->validate($data)->toArray(),
        );
    }

    public function testValidationRules(): void
    {
        $doc = $this->doc();
        $this->assertSame(['work_order:required'], $this->codes($doc, ['description' => 'Nájem']));
        $this->assertSame(
            ['quantity:invalid', 'unit_price:invalid'],
            $this->codes($doc, ['work_order' => 7, 'quantity' => 'x', 'unit_price' => '1,5']),
        );
        $this->assertSame(
            ['valid_to:invalid_range'],
            $this->codes($doc, ['work_order' => 7, 'valid_from' => '2026-10-01', 'valid_to' => '2026-09-30']),
        );
        $this->assertSame(['contributor:invalid'], $this->codes($doc, ['work_order' => 7, 'contributor' => 'Spotřeba!']));
        $this->assertSame([], $this->codes($doc, [
            'work_order' => 7, 'quantity' => '1', 'unit_price' => 1500.5, 'valid_from' => '2026-10-01', 'contributor' => 'energy.consumption',
        ]));
    }

    public function testNewRowGetsNextOrderPosAndEmptyTextsBecomeNull(): void
    {
        $doc = $this->doc(4);
        $data = ['work_order' => 7, 'description' => ' Nájem ', 'vat_code' => '', 'operation' => ' ', 'contributor' => ''];
        $doc->beforeSave($data, null);

        $this->assertSame(4, $data['order_pos']);
        $this->assertSame([7], $doc->orderPosCalls);
        $this->assertSame('Nájem', $data['description']);
        $this->assertNull($data['vat_code']);
        $this->assertNull($data['operation']);
        $this->assertNull($data['contributor']);
    }

    public function testExplicitOrderPosAndUpdatesKeepTheirOrder(): void
    {
        $doc = $this->doc();
        $data = ['work_order' => 7, 'order_pos' => 2];
        $doc->beforeSave($data, null);
        $this->assertSame(2, $data['order_pos']);

        $update = ['id' => 3, 'work_order' => 7, 'order_pos' => 0];
        $doc->beforeSave($update, ['id' => 3, 'order_pos' => 0]);
        $this->assertSame(0, $update['order_pos']);
        $this->assertSame([], $doc->orderPosCalls);
    }
}
