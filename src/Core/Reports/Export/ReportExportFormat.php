<?php

declare(strict_types=1);

namespace Shipard\Core\Reports\Export;

/**
 * Souborové formáty exportu reportu. JSON sem nepatří — to je
 * `ReportResult::toArray()` beze změn (D4), ne export.
 */
enum ReportExportFormat: string
{
    case Xlsx = 'xlsx';
    case Csv = 'csv';

    public function contentType(): string
    {
        return match ($this) {
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Csv  => 'text/csv; charset=utf-8',
        };
    }
}
