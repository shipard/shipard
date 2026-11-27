<?php

declare(strict_types=1);

namespace Shipard\Module\Facility\Buildings;

use Shipard\Module\Economy\PeriodicInvoicing\Rows\AbstractPrescriptionRowDocument;

/**
 * Řádek šablony řádků předpisu na druhu smlouvy
 * (facility_buildings_contract_kind_rows, #174 D32): tvar řádku předpisu bez
 * platnosti. Validaci a pořadí dává společný základ enginu periodické
 * fakturace. Kopii šablony do smlouvy dělá {@see ContractDocument}.
 */
class ContractKindRowDocument extends AbstractPrescriptionRowDocument
{
    public const TABLE = ContractDocument::KIND_ROWS_TABLE;

    protected function rowsTable(): string
    {
        return self::TABLE;
    }

    protected function parentColumn(): string
    {
        return 'kind';
    }

    protected function parentRequiredMessage(): string
    {
        return 'Řádek šablony musí patřit druhu smlouvy.';
    }

    protected function hasValidity(): bool
    {
        return false;
    }
}
