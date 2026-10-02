<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\Twig\PrintTwigFactory;
use Shipard\Core\Render\RenderClient;
use Shipard\Core\Settings\BrandingStorage;

/**
 * Wiring `PrintRunner` pro konkrétní DS — sdílí ho REST dispatch a CLI
 * `print-run`, ať se tisk neliší podle cesty, kterou vznikl. Bez spojení
 * do databáze (`$db` null) umí runner jen render z hotového `PrintData`.
 */
final class PrintRunnerFactory
{
    /** Kompilované Twig šablony, relativně k adresáři zdroje dat. */
    public const TWIG_CACHE_DIR = 'cache/twig';

    public static function create(
        PrintRegistry $registry,
        DataSourceConfig $dsConfig,
        ?DataSourceConnection $db,
        ModulePathResolver $modules,
        ?RenderClient $renderClient = null,
    ): PrintRunner {
        $dsDir    = $dsConfig->getDataSourceDir();
        $paths    = new PrintTemplatePaths($modules);
        $branding = new BrandingStorage($dsDir);

        // Bez render klienta runner umí JSON a HTML — PDF skončí jako
        // nenakonfigurovaná služba.
        $renderer = new PrintRenderer(
            $paths,
            new PrintTwigFactory($paths, $dsDir . '/' . self::TWIG_CACHE_DIR),
            $renderClient ?? new RenderClient(null),
            $branding,
        );

        return new PrintRunner(
            $registry,
            $db,
            static fn (string $language): ConfigRuntime => ConfigRuntime::load($dsDir, $language),
            new PrintLanguageResolver($dsConfig->getDefaultLanguage()),
            $branding,
            new PrintCatalogLoader($paths),
            $renderer,
        );
    }
}
