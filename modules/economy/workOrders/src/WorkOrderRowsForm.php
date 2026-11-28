<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders;

use Shipard\Module\Economy\PeriodicInvoicing\Rows\AbstractPrescriptionRowsForm;

/**
 * Sub-formulář řádku předpisu periodické zakázky (tab Řádky ve
 * WorkOrdersForm, docs/work-orders.md §5.3). Pole, nabídky a přepočet
 * dává společný základ řádku předpisu enginu periodické fakturace
 * ({@see AbstractPrescriptionRowsForm}, #174 D31); zakázky dodávají rodiče
 * — zakázku a její druh, ze kterých se bere efektivní typ dokladu.
 */
class WorkOrderRowsForm extends AbstractPrescriptionRowsForm
{
    protected function parentColumn(): string
    {
        return 'work_order';
    }

    protected function loadParentHeadAndKind(int $parentId): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $head = $this->db->fetchRow(
            'SELECT * FROM `' . WorkOrderDocument::TABLE . '` WHERE `id` = %i',
            $parentId,
        );
        if ($head === null) {
            return null;
        }
        $kindId = (int) ($head['kind'] ?? 0);
        $kind = $kindId > 0
            ? $this->db->fetchRow('SELECT * FROM `' . KindDocument::TABLE . '` WHERE `id` = %i', $kindId)
            : null;

        return ['head' => $head, 'kind' => $kind];
    }

    protected function rowTitle(): string
    {
        return 'Řádek zakázky';
    }

    protected function rowTitleNew(): string
    {
        return 'Nový řádek zakázky';
    }
}
