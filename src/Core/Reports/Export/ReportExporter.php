<?php

declare(strict_types=1);

namespace Shipard\Core\Reports\Export;

use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Utils\Slug;

/**
 * Export reportu do souboru — další renderer nad `ReportResult` (D4, D72).
 * Jediný vstupní bod pro REST i CLI: tabularizace → writer dle formátu →
 * soubor s názvem `{slug názvu reportu}-{období}.{přípona}`.
 */
final class ReportExporter
{
    public function __construct(
        private readonly ReportTabularizer $tabularizer = new ReportTabularizer(),
        private readonly ReportCsvWriter $csvWriter = new ReportCsvWriter(),
        private readonly ReportXlsxWriter $xlsxWriter = new ReportXlsxWriter(),
    ) {}

    public function export(
        ReportResult $result,
        ReportExportFormat $format,
        ReportExportContext $context,
    ): ReportExportFile {
        $table = $this->tabularizer->tabularize($result, $context);

        $body = match ($format) {
            ReportExportFormat::Csv  => $this->csvWriter->write($table),
            ReportExportFormat::Xlsx => $this->xlsx($table, $context),
        };

        return new ReportExportFile($body, $format->contentType(), $this->fileName($result, $format, $context));
    }

    /** OpenSpout zapisuje jen do souboru — reporty jsou malé, tělo se vrací jako string. */
    private function xlsx(ReportTable $table, ReportExportContext $context): string
    {
        $path = tempnam(sys_get_temp_dir(), 'shpd_report_');
        if ($path === false) {
            throw new \RuntimeException('Cannot create a temporary file for the XLSX export');
        }
        try {
            $this->xlsxWriter->write($table, $context, $path);
            $body = file_get_contents($path);
            if ($body === false) {
                throw new \RuntimeException('Cannot read the generated XLSX export');
            }
            return $body;
        } finally {
            @unlink($path);
        }
    }

    private function fileName(ReportResult $result, ReportExportFormat $format, ReportExportContext $context): string
    {
        $name   = Slug::make($context->reportName, fallback: 'report');
        $period = $result->params['period'] ?? null;
        if (is_array($period)) {
            $name .= '-' . ReportPeriodFormatter::fileSuffix($period, $context->fiscalYearMonths);
        }
        return $name . '.' . $format->value;
    }
}
