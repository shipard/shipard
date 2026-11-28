<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders;

use Shipard\Module\Economy\PeriodicInvoicing\Rows\AbstractPrescriptionRowLockProvider;

/**
 * Zámek řádků předpisu podle stavu zakázky (`documentLockProviders` nad
 * `economy_work_orders_rows`, tasks/work-orders-rows-readonly.md): řádek je
 * zamčený, když zakázka — uložená i nově zadaná v `work_order` — je ve stavu
 * s `readOnly` v `economy.workOrders.docStates` (V pořádku, Ukončeno,
 * Zrušeno, Smazáno). Potvrzená zakázka je celá jen ke čtení, řádky se mění
 * přes V opravě (docs/work-orders.md §5.4).
 *
 * Mechanismus dává společný základ enginu periodické fakturace
 * ({@see AbstractPrescriptionRowLockProvider}, #174 D31); zakázky dodávají
 * rodiče a texty. Import mód výjimku nemá — import zapisuje řádky před
 * potvrzením zakázky (docs/work-orders.md §6).
 */
class WorkOrderRowLockProvider extends AbstractPrescriptionRowLockProvider
{
    public const SOURCE = 'work_order_state';

    /** tableId `economy_work_orders_heads` */
    public const SUBJECT_TABLE_ID = 460;

    protected function parentTable(): string
    {
        return WorkOrderDocument::TABLE;
    }

    protected function parentColumn(): string
    {
        return 'work_order';
    }

    protected function docStatesCfgItem(): string
    {
        return WorkOrderDocument::DOC_STATES_CFG_ITEM;
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
        return "Zakázka {$label} je jen ke čtení — řádky uprav přes V opravě.";
    }

    protected function lockMessage(string $stateName): string
    {
        return "Řádky předpisu se řídí stavem zakázky ({$stateName}): dej zakázku V opravě,"
            . ' uprav řádky a vrať ji V pořádku. Koncept faktury se starými řádky pak Přegeneruj.';
    }
}
