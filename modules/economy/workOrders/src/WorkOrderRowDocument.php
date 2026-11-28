<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders;

use Shipard\Module\Economy\PeriodicInvoicing\Rows\AbstractPrescriptionRowDocument;

/**
 * Řádek zakázky (economy_work_orders_rows, docs/work-orders.md §5.3) —
 * fakturační předpis periodické zakázky. Tvarovou validaci a pořadí nového
 * řádku dává společný základ řádku předpisu enginu periodické fakturace
 * ({@see AbstractPrescriptionRowDocument}, #174 D31). Stav zakázky řádek
 * sám nehlídá — zámek podle stavu rodiče (V pořádku a další stavy
 * s readOnly) dodává WorkOrderRowLockProvider, který jádro vynucuje i na
 * generickém CRUD (tasks/work-orders-rows-readonly.md).
 */
class WorkOrderRowDocument extends AbstractPrescriptionRowDocument
{
    public const TABLE = 'economy_work_orders_rows';

    protected function rowsTable(): string
    {
        return self::TABLE;
    }

    protected function parentColumn(): string
    {
        return 'work_order';
    }

    protected function parentRequiredMessage(): string
    {
        return 'Řádek musí patřit zakázce.';
    }
}
