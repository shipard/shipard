<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Sent;

use Shipard\Api\PrintDefinitionLoader;
use Shipard\Api\RecordSenderProviderLoader;
use Shipard\Api\TableLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Mail\MailServiceFactory;
use Shipard\Core\Mail\SenderResolver;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintCatalogLoader;
use Shipard\Core\Prints\PrintEmailRenderer;
use Shipard\Core\Prints\PrintRunnerFactory;
use Shipard\Core\Prints\PrintTemplatePaths;
use Shipard\Core\Prints\Twig\PrintTwigFactory;
use Shipard\Core\Render\RenderClient;
use Shipard\Module\Base\Persons\Send\RecipientResolver;
use Shipard\Module\Core\Attachments\AttachmentService;

/**
 * Wiring `RecordSendService` pro konkrétní zdroj dat — sdílí ho REST
 * i CLI `print-send`, ať se odeslání neliší podle cesty, kterou vzniklo.
 */
final class RecordSendServiceFactory
{
    /**
     * @param string $language Jazyk rozhraní — důvody u příjemců a hlášky.
     *        Jazyk zprávy samotné určuje tisk (jazyk dokumentu).
     */
    public static function create(
        DataSourceConfig $dsConfig,
        DataSourceConnection $db,
        ModulePathResolver $modules,
        string $language,
        ?ServerConfig $serverConfig = null,
    ): RecordSendService {
        $dsDir  = $dsConfig->getDataSourceDir();
        $config = self::config($dsDir, $language);
        $tables = TableLoader::load($dsConfig, $modules, $language);

        $registry     = PrintDefinitionLoader::load($dsConfig, $modules, $language);
        $renderClient = $serverConfig !== null ? RenderClient::fromServerConfig($serverConfig) : new RenderClient(null);
        $paths        = new PrintTemplatePaths($modules);
        $store        = new SentMessageStore($db);

        return new RecordSendService(
            $registry,
            PrintRunnerFactory::create($registry, $dsConfig, $db, $modules, $renderClient)->run(...),
            new PrintCatalogLoader($paths),
            new PrintEmailRenderer(
                $paths,
                new PrintTwigFactory($paths, $dsDir . '/' . PrintRunnerFactory::TWIG_CACHE_DIR),
            ),
            new RecipientResolver($db, $config),
            SenderResolver::forDataSource($db, RecordSenderProviderLoader::load($dsConfig, $modules), $config),
            new AttachmentService($db, $dsDir, $tables),
            $renderClient,
            $store,
            new SentMessageTransport($store, MailServiceFactory::create($dsConfig, $db, $serverConfig)),
            $db,
            $tables,
            $config,
        );
    }

    private static function config(string $dsDir, string $language): ?ConfigRuntime
    {
        try {
            return ConfigRuntime::load($dsDir, $language);
        } catch (\RuntimeException) {
            // Zdroj dat bez konfigurace v jazyce rozhraní: hlášky anglicky.
            return null;
        }
    }
}
