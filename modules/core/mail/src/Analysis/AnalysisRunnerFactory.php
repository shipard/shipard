<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

use Shipard\Api\TableLoader;
use Shipard\Core\Ai\AnthropicLlmClient;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * Produkční wiring runneru pro CLI `mail-analyze`: služby analýzy se
 * stejnými závislostmi jako `AnalysisController` v public/index.php
 * (validace canonicalu, obohacení řádků, compiled config), přílohy,
 * prompt, parser, Anthropic klient, sloty ze server.json a spawner pro
 * sweep.
 */
final class AnalysisRunnerFactory
{
    /**
     * @param ServerConfig|null $serverConfig Zdroj `ai.analysis.maxConcurrent`;
     *        null (server config nenačten) = výchozí limit.
     */
    public static function create(
        DataSourceConfig $dsConfig,
        DataSourceConnection $db,
        string $dsDir,
        ModulePathResolver $resolver,
        ?ServerConfig $serverConfig = null,
    ): AnalysisRunner {
        $tables = TableLoader::load($dsConfig, $resolver, $dsConfig->getDefaultLanguage());
        $attachments = new AttachmentService($db, $dsDir, $tables);

        try {
            $configRuntime = ConfigRuntime::load($dsDir, $dsConfig->getDefaultLanguage());
        } catch (\Throwable $e) {
            ErrorLogger::warn('AnalysisRunnerFactory: compiled config unavailable — classification and registry targets degrade', [
                'error' => $e->getMessage(),
            ]);
            $configRuntime = null;
        }
        try {
            $enricher = RowEnrichmentPipeline::create($db, $configRuntime, $dsConfig);
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'AnalysisRunnerFactory: RowEnrichmentPipeline unavailable — results stored without enrichment');
            $enricher = null;
        }

        $services = AnalysisServices::create(
            $db,
            $dsConfig,
            new SchemaValidator(SchemaLoader::default()),
            $enricher,
            $configRuntime,
        );
        $slots = AnalysisSlots::fromServerConfig($serverConfig);
        $spawner = new AnalysisSpawner($dsDir, maxConcurrent: $slots->maxConcurrent());

        return new AnalysisRunner(
            $db,
            $services,
            new AttachmentPreparer($attachments),
            new PromptRenderer(),
            new OutputParser(),
            new AnthropicLlmClient(),
            $slots,
            static function (int $messageId) use ($spawner): void {
                $spawner->spawn($messageId);
            },
        );
    }
}
