<?php

declare(strict_types=1);

namespace Shipard\Module\Facility\Buildings;

use Shipard\Module\Economy\PeriodicInvoicing\Rows\AbstractPrescriptionRowLockProvider;

/**
 * Zámek šablony řádků podle stavu druhu smlouvy (`documentLockProviders`
 * nad `facility_buildings_contract_kind_rows`, #174 D32): druh má sadu stavů
 * `core.system.docStatesArchive`, kde je V pořádku, Archiv i Koš `readOnly`
 * — šablona potvrzeného druhu se proto mění přes V opravě, stejně jako
 * zbytek druhu. Popisek důvodu je název druhu (druh číslo nemá).
 */
class ContractKindRowLockProvider extends AbstractPrescriptionRowLockProvider
{
    public const SOURCE = 'contract_kind_state';

    /** tableId `facility_buildings_contract_kinds` */
    public const SUBJECT_TABLE_ID = 472;

    public const DOC_STATES_CFG_ITEM = 'core.system.docStatesArchive';

    protected function parentTable(): string
    {
        return ContractKindDocument::TABLE;
    }

    protected function parentColumn(): string
    {
        return 'kind';
    }

    protected function docStatesCfgItem(): string
    {
        return self::DOC_STATES_CFG_ITEM;
    }

    protected function subjectTableId(): int
    {
        return self::SUBJECT_TABLE_ID;
    }

    protected function source(): string
    {
        return self::SOURCE;
    }

    protected function parentLabel(array $parent, int $id): string
    {
        return trim((string) ($parent['name'] ?? '')) ?: "#{$id}";
    }

    protected function lockTitle(string $label): string
    {
        return "Druh smlouvy {$label} je jen ke čtení — šablonu řádků uprav přes V opravě.";
    }

    protected function lockMessage(string $stateName): string
    {
        return "Šablona řádků se řídí stavem druhu ({$stateName}): dej druh V opravě, uprav šablonu"
            . ' a vrať ho V pořádku. Smlouvy, které už řádky mají, se nezmění.';
    }
}
