<?php

declare(strict_types=1);

namespace Shipard\Core\Reports\Export;

/**
 * Co `ReportResult` o sobě neví, ale export to potřebuje: lidský název
 * reportu a firmy (výsledek nese jen id), popisky a jazyk. Staví volající
 * (REST / CLI) — tabularizace zůstává čistá funkce bez DB.
 */
final class ReportExportContext
{
    /**
     * @param string $dataSourceName Název firmy / zdroje dat — do exportu
     *                               nikdy ID zdroje.
     * @param int $fiscalYearMonths Počet běžných měsíců fiskálního roku
     *                              výsledku (rozliší „celý rok" v popisku
     *                              období); u vatPeriod reportů bez významu.
     */
    public function __construct(
        public readonly string $reportName,
        public readonly string $dataSourceName,
        public readonly ReportExportLabels $labels = new ReportExportLabels(),
        public readonly string $language = 'en',
        public readonly int $fiscalYearMonths = 12,
    ) {}
}
