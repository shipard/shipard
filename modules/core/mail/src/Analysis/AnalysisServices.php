<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * Wiring služeb AI analýzy na jednom místě (tasks/mail-analysis-inprocess.md
 * D13) — sdílí ho `AnalysisController` (pull protokol, reanalýza)
 * a in-process runner (`AnalysisRunnerFactory`).
 */
final readonly class AnalysisServices
{
    public function __construct(
        public AnalysisQueue $queue,
        public AnalysisClaimService $claims,
        public AnalysisResultWriter $results,
    ) {}

    public static function create(
        DataSourceConnection $db,
        DataSourceConfig $config,
        ?SchemaValidator $schemaValidator = null,
        ?RowEnrichmentPipeline $enricher = null,
        ?ConfigRuntime $configRuntime = null,
    ): self {
        return new self(
            new AnalysisQueue($db),
            new AnalysisClaimService($db, $config),
            new AnalysisResultWriter($db, $config, $schemaValidator, $enricher, $configRuntime),
        );
    }
}
