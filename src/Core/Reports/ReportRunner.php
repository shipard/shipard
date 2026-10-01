<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Jediný vstupní bod pro spuštění reportu — REST controller i budoucí
 * MCP/diff volají výhradně `run()`, nikdy builder přímo.
 *
 * registry → validace parametrů → builder → `ReportResult`.
 */
final class ReportRunner
{
    private readonly FiscalPeriodProvider $periods;
    private readonly ReportPeriodProvider $vatPeriods;

    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly DataSourceConnection $db,
        private readonly ?ConfigRuntime $config,
        private readonly string $dataSourceId,
        private readonly string $language = 'en',
        ?FiscalPeriodProvider $periods = null,
        ?ReportPeriodProvider $vatPeriods = null,
        private readonly string $country = 'cz',
    ) {
        $this->periods = $periods ?? new DbFiscalPeriodProvider($db);
        // Konstrukce je bez dotazu — DS bez economy.vat za to nic neplatí,
        // dotaz přijde až s během vatPeriod reportu (ten bez tabulek neexistuje).
        $this->vatPeriods = $vatPeriods ?? new DbReportPeriodProvider($db);
    }

    /**
     * @param array<string, mixed> $rawParams
     * @throws ReportNotFoundException Neznámé id reportu (→ 404).
     * @throws \InvalidArgumentException Nevalidní parametry (→ 400).
     */
    public function run(string $reportId, array $rawParams): ReportResult
    {
        $definition = $this->registry->get($reportId);
        if ($definition === null) {
            throw new ReportNotFoundException("Unknown report '{$reportId}'");
        }

        $validator = new ReportParamValidator($this->periods, $this->vatPeriods);
        $validated = $validator->validate($definition, $rawParams);

        $builderClass = $definition->builderClass;
        if (!class_exists($builderClass)) {
            throw new \RuntimeException(
                "Report '{$reportId}': builder class '{$builderClass}' not found",
            );
        }
        $builder = new $builderClass();
        if (!$builder instanceof ReportBuilder) {
            throw new \RuntimeException(
                "Report '{$reportId}': '{$builderClass}' does not implement ReportBuilder",
            );
        }

        return $builder->build(new ReportRequest(
            reportId: $reportId,
            range: $validated['range'],
            params: $validated['params'],
            db: $this->db,
            config: $this->config,
            dataSource: $this->dataSourceId,
            language: $this->language,
            vatRange: $validated['vatRange'],
            country: $this->country,
        ));
    }
}
