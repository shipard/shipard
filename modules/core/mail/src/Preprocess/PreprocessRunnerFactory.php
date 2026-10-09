<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Preprocess;

use Shipard\Api\TableLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Render\RenderClient;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Mail\Analysis\AnalysisSpawner;
use Shipard\Module\Core\Mail\IsdocImportService;
use Shipard\Module\Core\Mail\MessagePartnerWriter;
use Shipard\Module\Core\Mail\MessageTitleComposer;
use Shipard\Module\Core\Mail\Preprocess\Action\FetchLinkedDocumentAction;
use Shipard\Module\Core\Mail\Preprocess\Action\RenderBodyToPdfAction;
use Shipard\Module\Core\Mail\Preprocess\Http\CurlHttpFetcher;

/**
 * Produkční wiring runneru pro CLI `mail-preprocess`: přílohy, registr
 * akcí, rendering klient (#34), ISDOC import s obohacením řádků (jediné
 * místo, kde import běží — intake v public/index.php dělá jen detekci,
 * #81 D1), spawner pro sweep, matcher pro --force a spawner AI analýzy
 * po konci běhu (tasks/mail-analysis-inprocess.md D14).
 */
final class PreprocessRunnerFactory
{
    /**
     * @param ServerConfig|null $serverConfig Zdroj `render` sekce; null
     *        (server config nenačten) = nenakonfigurovaná služba, render
     *        akce selhávají s `unconfigured` — zpráva doteče do stavu 40.
     */
    public static function create(
        DataSourceConfig $dsConfig,
        DataSourceConnection $db,
        string $dsDir,
        ModulePathResolver $resolver,
        ?ServerConfig $serverConfig = null,
    ): PreprocessRunner {
        $tables = TableLoader::load($dsConfig, $resolver, $dsConfig->getDefaultLanguage());
        $attachments = new AttachmentService($db, $dsDir, $tables);
        $dibi = $db->getDibiConnection();
        $render = $serverConfig !== null ? RenderClient::fromServerConfig($serverConfig) : new RenderClient(null);

        $isdocImportFactory = static function () use ($db, $dibi, $dsDir, $dsConfig): IsdocImportService {
            // Compiled config: partner target ISDOC importu (jazykově
            // nezávislý — bez něj target vždy docs) a taxonomie / defaults
            // obsahové eskalace. Titulek si config načítá sám v jazyce AI
            // profilu DS (forDataSource).
            try {
                $configRuntime = ConfigRuntime::load($dsDir, $dsConfig->getDefaultLanguage());
            } catch (\Throwable $e) {
                ErrorLogger::warn('PreprocessRunnerFactory: compiled config unavailable — ISDOC partner target falls back to docs', [
                    'error' => $e->getMessage(),
                ]);
                $configRuntime = null;
            }
            // Obohacení řádků vč. obsahové eskalace (#81 D1): Vrstva 0 +
            // pravidlo IČO → štítek, jinak LLM. Classifier je null-safe —
            // DS bez backendu/klíče degraduje na deterministickou část.
            // Selhaný wiring = import bez obohacení, runner nikdy nepadá.
            try {
                $enricher = RowEnrichmentPipeline::create($db, $configRuntime, $dsConfig);
            } catch (\Throwable $e) {
                ErrorLogger::logException($e, 'PreprocessRunnerFactory: RowEnrichmentPipeline unavailable — ISDOC import runs without enrichment');
                $enricher = null;
            }
            return new IsdocImportService(
                $db,
                new SchemaValidator(SchemaLoader::default()),
                $enricher,
                $dsDir,
                partnerWriter: MessagePartnerWriter::create($dibi, $configRuntime),
                titleComposer: MessageTitleComposer::forDataSource($db, $dsConfig),
            );
        };

        $spawner = new PreprocessSpawner($dsDir);
        $analysisSpawner = new AnalysisSpawner($dsDir);

        return new PreprocessRunner(
            $db,
            $attachments,
            self::defaultActions($db, $attachments, $render),
            $isdocImportFactory,
            static function (int $messageId) use ($spawner): void {
                $spawner->spawn($messageId);
            },
            new PreprocessRuleMatcher($dibi),
            static function (int $messageId) use ($analysisSpawner): void {
                $analysisSpawner->spawn($messageId);
            },
        );
    }

    /**
     * Registr akcí, které runner umí (zrcadlo
     * PreprocessRuleDocument::IMPLEMENTED_ACTIONS). Testovací seam:
     * RenderClient s injektovaným fake enginem.
     */
    public static function defaultActions(DataSourceConnection $db, AttachmentService $attachments, RenderClient $render): ActionRegistry
    {
        return new ActionRegistry()
            ->register(FetchLinkedDocumentAction::KEY, new FetchLinkedDocumentAction($attachments, new CurlHttpFetcher(), null, $render))
            ->register(RenderBodyToPdfAction::KEY, new RenderBodyToPdfAction($attachments, $render));
    }
}
