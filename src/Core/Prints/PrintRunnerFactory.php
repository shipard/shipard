<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Settings\BrandingStorage;

/**
 * Wiring `PrintRunner` pro konkrétní DS — sdílí ho REST dispatch a CLI
 * `print-run`, ať se tisk neliší podle cesty, kterou vznikl.
 */
final class PrintRunnerFactory
{
    public static function create(
        PrintRegistry $registry,
        DataSourceConfig $dsConfig,
        DataSourceConnection $db,
        ModulePathResolver $modules,
    ): PrintRunner {
        $dsDir = $dsConfig->getDataSourceDir();

        return new PrintRunner(
            $registry,
            $db,
            static fn (string $language): ConfigRuntime => ConfigRuntime::load($dsDir, $language),
            new PrintLanguageResolver($dsConfig->getDefaultLanguage()),
            new BrandingStorage($dsDir),
            new PrintCatalogLoader(new PrintTemplatePaths($modules)),
        );
    }
}
