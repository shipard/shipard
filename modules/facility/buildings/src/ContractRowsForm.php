<?php

declare(strict_types=1);

namespace Shipard\Module\Facility\Buildings;

use Shipard\Module\Economy\PeriodicInvoicing\Rows\AbstractPrescriptionRowsForm;

/**
 * Sub-formulář řádku předpisu smlouvy (tab Řádky v ContractsForm, #174
 * D30). Pole, nabídky a přepočet dává společný základ řádku předpisu enginu
 * periodické fakturace; smlouvy dodávají rodiče — smlouvu a její druh, ze
 * kterých se bere efektivní typ dokladu.
 */
class ContractRowsForm extends AbstractPrescriptionRowsForm
{
    protected function parentColumn(): string
    {
        return 'contract';
    }

    protected function loadParentHeadAndKind(int $parentId): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $head = $this->db->fetchRow(
            'SELECT * FROM `' . ContractDocument::TABLE . '` WHERE `id` = %i',
            $parentId,
        );
        if ($head === null) {
            return null;
        }
        $kindId = (int) ($head['kind'] ?? 0);
        $kind = $kindId > 0
            ? $this->db->fetchRow('SELECT * FROM `' . ContractKindDocument::TABLE . '` WHERE `id` = %i', $kindId)
            : null;

        return ['head' => $head, 'kind' => $kind];
    }

    protected function rowTitle(): string
    {
        return 'Řádek smlouvy';
    }

    protected function rowTitleNew(): string
    {
        return 'Nový řádek smlouvy';
    }
}
