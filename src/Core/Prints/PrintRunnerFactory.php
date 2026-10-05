<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\I18n\DocumentLanguageResolver;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\Twig\PrintTwigFactory;
use Shipard\Core\Render\RenderClient;
use Shipard\Core\Settings\BrandingStorage;
use Shipard\Core\Settings\SettingsStore;

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

        // Jedna kompilovaná konfigurace na jazyk a běh — sdílí ji builder
        // i odvození jazyka dokumentu.
        $configs = [];
        $config  = static function (string $language) use ($dsDir, &$configs): ConfigRuntime {
            try {
                return $configs[$language] ??= ConfigRuntime::load($dsDir, $language);
            } catch (\RuntimeException $e) {
                throw new PrintLanguageNotCompiledException($language, $e);
            }
        };
        $country = $dsConfig->getCountry();

        return new PrintRunner(
            $registry,
            $db,
            $config,
            new PrintLanguageResolver(
                static fn (): DocumentLanguageResolver => DocumentLanguageResolver::fromConfig(
                    $config(PrintLanguageResolver::FALLBACK),
                    $country,
                ),
            ),
            $branding,
            new PrintCatalogLoader($paths),
            $renderer,
            settings: $db === null ? null : new SettingsStore($db),
        );
    }
}
