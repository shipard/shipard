<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Obálka pro `ReportBuilder::build()` — validované období, normalizované
 * parametry a runtime závislosti. Staví ji výhradně `ReportRunner`.
 *
 * Dle `periodSource` deklarace je vyplněné právě jedno z `range`
 * (fiscal) / `vatRange` (vatPeriod); druhé je null.
 */
final class ReportRequest
{
    /** @param array<string, mixed> $params Normalizované parametry vč. klíče `period`. */
    public function __construct(
        public readonly string $reportId,
        public readonly ?FiscalRange $range,
        public readonly array $params,
        public readonly DataSourceConnection $db,
        public readonly ?ConfigRuntime $config,
        public readonly string $dataSource,
        public readonly string $language,
        public readonly ?VatPeriodRange $vatRange = null,
        /** Země zdroje dat (`DataSourceConfig::getCountry()`) — pravidla per země. */
        public readonly string $country = 'cz',
    ) {}
}
