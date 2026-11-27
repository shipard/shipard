<?php

declare(strict_types=1);

namespace Shipard\Module\Facility\Buildings;

use Shipard\Module\Economy\PeriodicInvoicing\Rows\AbstractPrescriptionRowsForm;

/**
 * Sub-formulář řádku šablony řádků na druhu smlouvy (tab Šablona řádků
 * v ContractKindsForm, #174 D32): řádek předpisu bez platnosti. Rodič je
 * druh — typ dokladu pro nabídku kódů DPH a pohybů jde jen z něj.
 */
class ContractKindRowsForm extends AbstractPrescriptionRowsForm
{
    protected function parentColumn(): string
    {
        return 'kind';
    }

    protected function loadParentHeadAndKind(int $parentId): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $kind = $this->db->fetchRow(
            'SELECT * FROM `' . ContractKindDocument::TABLE . '` WHERE `id` = %i',
            $parentId,
        );
        return $kind === null ? null : ['head' => null, 'kind' => $kind];
    }

    protected function rowTitle(): string
    {
        return 'Řádek šablony';
    }

    protected function rowTitleNew(): string
    {
        return 'Nový řádek šablony';
    }

    protected function hasValidity(): bool
    {
        return false;
    }
}
