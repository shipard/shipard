<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing\Checks;

use Shipard\Module\Economy\PeriodicInvoicing\InvoiceBuildException;
use Shipard\Module\Economy\PeriodicInvoicing\PeriodRepository;

/**
 * Období se nepodařilo vystavit (tasks §6): per záznam zdroje — `planned` období
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
        foreach ($this->recordsWithPeriods([PeriodRepository::STATE_PLANNED], self::RESULTS) as $group) {
            $periods = $group['periods'];
            $count = count($periods);
            $reason = trim((string) ($periods[0]['message'] ?? ''));
            $list = implode(', ', array_map(static fn(array $p): string => self::periodLabel($p), $periods));
            $findings[] = $this->finding(
                $group,
                $isCs
                    ? InvoicesToReviewCheck::pluralCs($count, 'období se nepodařilo', 'období se nepodařila', 'období se nepodařilo') . ' vystavit'
                    : "{$count} " . ($count === 1 ? 'period' : 'periods') . ' could not be issued',
                ($isCs ? 'Období: ' : 'Periods: ') . $list . ($reason !== '' ? ' — ' . $reason : ''),
                'warning',
            );
        }
        return $findings;
    }
}
