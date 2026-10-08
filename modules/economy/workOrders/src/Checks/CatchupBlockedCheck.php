<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Checks;

use Shipard\Module\Economy\WorkOrders\Invoicing\InvoicingRunService;
use Shipard\Module\Economy\WorkOrders\Invoicing\PeriodRepository;

/**
 * Pojistka dohánění (Q4): per zakázka — `planned` období, která běh
 * nevystavil, protože dlužných období je víc než
 * InvoicingRunService::MAX_CATCHUP. Vystaví je akce Vystavit dlužná
 * období na zakázce.
 */
class CatchupBlockedCheck extends PeriodCheckBase
{
    public function run(): array
    {
        $isCs = $this->language === 'cs';
        $findings = [];
        foreach ($this->byWorkOrder($this->periods([PeriodRepository::STATE_PLANNED], [PeriodRepository::RESULT_CATCHUP])) as $workOrderId => $periods) {
            $label = self::workOrderLabel($periods[0]);
            $count = count($periods);
            $oldest = self::czDate(end($periods)['period_from']);
            $findings[] = $this->finding(
                $workOrderId,
                $periods,
                $isCs
                    ? "Zakázka {$label}: " . InvoicesToReviewCheck::pluralCs($count, 'dlužné období', 'dlužná období', 'dlužných období') . ' čeká na vystavení'
                    : "Work order {$label}: {$count} due " . ($count === 1 ? 'period waits' : 'periods wait') . ' to be issued',
                $isCs
                    ? "Víc než " . InvoicingRunService::MAX_CATCHUP . " splatných období najednou (od {$oldest}) běh nevystaví — zkontroluj „fakturovat od“ a vystav je akcí Vystavit dlužná období na záložce Fakturace."
                    : 'More than ' . InvoicingRunService::MAX_CATCHUP . " due periods at once (since {$oldest}) are not issued automatically — check “invoice from” and use Issue due periods on the Invoicing tab.",
                'warning',
                $isCs,
            );
        }
        return $findings;
    }
}
