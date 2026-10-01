<?php

declare(strict_types=1);

namespace Shipard\Core\Reports\Export;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Reports\FiscalPeriodProvider;
use Shipard\Core\Reports\ReportDefinition;
use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Settings\SettingsStore;

/**
 * Staví `ReportExportContext` pro konkrétní běh reportu — sdílené REST
 * controllerem a CLI `report-run`, ať se úvodní blok exportu neliší podle
 * toho, odkud byl spuštěn.
 */
final class ReportExportContextFactory
{
    public function __construct(
        private readonly string $dataSourceName,
        private readonly ?ConfigRuntime $config,
        private readonly string $language,
        private readonly ?FiscalPeriodProvider $periods = null,
    ) {}

    /**
     * Název firmy pro úvodní blok: nastavení `app.name` → `name` z main.json
     * (stejná přednost jako `AppController`). Selhání čtení nastavení
     * export neshodí — padá na název z konfigurace.
     */
    public static function resolveDataSourceName(DataSourceConnection $db, DataSourceConfig $config): string
    {
        try {
            $name = (new SettingsStore($db))->get('app.name');
        } catch (\Throwable) {
            $name = null;
        }
        return is_string($name) && trim($name) !== '' ? $name : $config->getName();
    }

    public function create(ReportDefinition $definition, ReportResult $result): ReportExportContext
    {
        return new ReportExportContext(
            reportName: $definition->name,
            dataSourceName: $this->dataSourceName,
            labels: ReportExportLabels::fromConfig($this->config)->withDefinitionParams($definition->params),
            language: $this->language,
            fiscalYearMonths: $this->fiscalYearMonths($result),
        );
    }

    /** Počet běžných měsíců fiskálního roku výsledku — rozliší „celý rok" v popisku období. */
    private function fiscalYearMonths(ReportResult $result): int
    {
        $yearName = $result->params['period']['fiscalYear'] ?? null;
        if ($this->periods === null || !is_scalar($yearName)) {
            return 12;
        }
        foreach ($this->periods->regularYears() as $year) {
            if ((string) $year['name'] === (string) $yearName) {
                return (int) $year['months'];
            }
        }
        return 12;
    }
}
