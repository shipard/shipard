<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accbal;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Accbal\OperationSides;

/**
 * Mapa operací → strana saldokonta (#69 D17) musí pokrýt každou operaci,
 * kterou konfigurace umí zapsat do `economy_accounting_journal.operation`:
 * `docs.core.rowOperations` (řádky dokladů) i `economy.bank.txOperations`
 * (bankovní engine). Nová operace bez zařazení = selhání tohoto testu.
 */
class OperationSidesTest extends TestCase
{
    private const ROW_OPERATIONS = __DIR__ . '/../../../../../modules/docs/core/config/rowOperations.jsonc';
    private const TX_OPERATIONS  = __DIR__ . '/../../../../../modules/economy/bank/config/txOperations.jsonc';

    /** @return list<string> */
    private static function configuredIds(): array
    {
        $ids = [];
        foreach ([self::ROW_OPERATIONS, self::TX_OPERATIONS] as $file) {
            $cfg = JsoncParser::parseFile($file);
            self::assertIsArray($cfg, $file);
            foreach (array_keys($cfg) as $id) {
                $ids[(string) $id] = true;
            }
        }
        return array_keys($ids);
    }

    public function testEveryConfiguredOperationIsClassified(): void
    {
        $configured = self::configuredIds();
        $mapped = array_keys(OperationSides::MAP);

        $missing = array_diff($configured, $mapped);
        $this->assertSame([], array_values($missing), 'operace z konfigurace bez zařazení v OperationSides::MAP');

        $stale = array_diff($mapped, $configured);
        $this->assertSame([], array_values($stale), 'operace v mapě, která už v konfiguraci není');
    }

    public function testSideDeterminingOperations(): void
    {
        foreach (['acc.balanceReceivable', 'acc.fxLossReceivable', 'acc.fxGainReceivable'] as $op) {
            $this->assertSame(OperationSides::SIDE_RECEIVABLE, OperationSides::kindOf($op), $op);
        }
        foreach (['acc.balancePayable', 'acc.fxLossPayable', 'acc.fxGainPayable'] as $op) {
            $this->assertSame(OperationSides::SIDE_PAYABLE, OperationSides::kindOf($op), $op);
        }
        foreach (['payment.receivable', 'payment.payable', 'payment.in', 'payment.out'] as $op) {
            $this->assertSame(OperationSides::PAYMENT, OperationSides::kindOf($op), $op);
        }
    }

    public function testNeutralOperationsGoThroughSettings(): void
    {
        foreach (['sale.services', 'purchase.goods', 'acc.record', 'acc.item', 'acc.entry', 'advance.received', 'transfer.in', 'fee.out'] as $op) {
            $this->assertNull(OperationSides::kindOf($op), $op);
        }
        $this->assertNull(OperationSides::kindOf(null));
        $this->assertNull(OperationSides::kindOf(''));
        $this->assertNull(OperationSides::kindOf('unknown.op'), 'neznámá operace = přes nastavení, ne výjimka');
    }
}
