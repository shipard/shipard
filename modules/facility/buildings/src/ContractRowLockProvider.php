<?php

declare(strict_types=1);

namespace Shipard\Module\Facility\Buildings;

use Shipard\Module\Economy\PeriodicInvoicing\Rows\AbstractPrescriptionRowLockProvider;

/**
 * Zámek řádků předpisu podle stavu smlouvy (`documentLockProviders` nad
 * `facility_buildings_contract_rows`, #174 D30): řádek je zamčený, když
 * smlouva — uložená i nově zadaná v `contract` — je ve stavu s `readOnly`
 * ve `facility.buildings.docStates` (V pořádku, Ukončená, Zrušená,
 * Smazaná). Řádky se mění přes V opravě. Mechanismus dává společný základ
 * enginu periodické fakturace; jádro ho vynucuje i na generickém CRUD.
 */
class ContractRowLockProvider extends AbstractPrescriptionRowLockProvider
{
    public const SOURCE = 'contract_state';

    /** tableId `facility_buildings_contracts` */
    public const SUBJECT_TABLE_ID = 474;

    protected function parentTable(): string
    {
        return ContractDocument::TABLE;
    }

    protected function parentColumn(): string
    {
        return 'contract';
    }

    protected function docStatesCfgItem(): string
    {
        return ContractDocument::DOC_STATES_CFG_ITEM;
    }

    protected function subjectTableId(): int
    {
        return self::SUBJECT_TABLE_ID;
    }

    protected function source(): string
    {
        return self::SOURCE;
    }

    protected function lockTitle(string $label): string
    {
        return "Smlouva {$label} je jen ke čtení — řádky uprav přes V opravě.";
    }

    protected function lockMessage(string $stateName): string
    {
        return "Řádky předpisu se řídí stavem smlouvy ({$stateName}): dej smlouvu V opravě,"
            . ' uprav řádky a vrať ji V pořádku. Koncept faktury se starými řádky pak Přegeneruj.';
    }
}
