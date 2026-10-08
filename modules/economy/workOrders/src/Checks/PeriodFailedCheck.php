<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Checks;

use Shipard\Module\Economy\WorkOrders\Invoicing\InvoiceBuildException;
use Shipard\Module\Economy\WorkOrders\Invoicing\PeriodRepository;

/**
 * Období se nepodařilo vystavit (tasks §6): per zakázka — `planned` období
 * s výsledkem běhu failed / no_rows / no_doc_type / no_series / no_customer
 * / contributor_failed; zpráva = poslední důvod. Zmizí po úspěšném běhu.
 */
class PeriodFailedCheck extends PeriodCheckBase
{
    public const RESULTS = [
        PeriodRepository::RESULT_FAILED,
        InvoiceBuildException::NO_ROWS,
        InvoiceBuildException::NO_DOC_TYPE,
        InvoiceBuildException::NO_SERIES,
        InvoiceBuildException::NO_CUSTOMER,
        InvoiceBuildException::CONTRIBUTOR_FAILED,
    ];

    public function run(): array
    {
        $isCs = $this->language === 'cs';
        $findings = [];
        foreach ($this->byWorkOrder($this->periods([PeriodRepository::STATE_PLANNED], self::RESULTS)) as $workOrderId => $periods) {
            $label = self::workOrderLabel($periods[0]);
            $count = count($periods);
            $reason = trim((string) ($periods[0]['message'] ?? ''));
            $list = implode(', ', array_map(static fn(array $p): string => self::periodLabel($p), $periods));
            $findings[] = $this->finding(
                $workOrderId,
                $periods,
                $isCs
                    ? "Zakázka {$label}: " . InvoicesToReviewCheck::pluralCs($count, 'období se nepodařilo', 'období se nepodařila', 'období se nepodařilo') . ' vystavit'
                    : "Work order {$label}: {$count} " . ($count === 1 ? 'period' : 'periods') . ' could not be issued',
                ($isCs ? 'Období: ' : 'Periods: ') . $list . ($reason !== '' ? ' — ' . $reason : ''),
                'warning',
                $isCs,
            );
        }
        return $findings;
    }
}
