<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Deadline;

/**
 * Pravidlo termínu podání podle země zdroje dat
 * (`DataSourceConfig::getCountry()`, docs/reports.md §16.2). Země bez
 * pravidla = null → termín se neposílá (#120 D17).
 */
final class FilingDeadlines
{
    public static function forCountry(string $country): ?FilingDeadlineRule
    {
        return match (strtolower(trim($country))) {
            'cz'    => new CzFilingDeadlineRule(),
            default => null,
        };
    }
}
