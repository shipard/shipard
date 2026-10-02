<?php

declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;
use Shipard\Core\Prints\PrintDefinition;
use Shipard\Core\Prints\PrintRegistry;
use Shipard\Core\Utils\JsoncParser;

/**
 * Buildí `PrintRegistry` pro konkrétní DS — analogicky
 * `ReportDefinitionLoader`. Projde resolvnuté moduly, načte JSONC soubory
 * z klíče `prints` (cesty relativně k adresáři modulu), aplikuje i18n
 * (`name:cs`) a naplní registr. Duplicitní id tisku napříč moduly = tvrdá
 * chyba při načtení.
 *
 * `$language` je jazyk názvů tisků v nabídce UI, ne jazyk tisku samotného.
 */
class PrintDefinitionLoader
{
    public static function load(
        DataSourceConfig $config,
        ModulePathResolver $resolver,
        string $language = 'en',
    ): PrintRegistry {
        $allModules      = ModuleLoader::loadAllModules($resolver);
        $errors          = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

        $registry = new PrintRegistry();
        foreach ($resolvedModules as $module) {
            if ($module->prints === []) {
                continue;
            }
            $modulePath = $resolver->getPath($module->id);
            if ($modulePath === null) {
                continue;
            }

            foreach ($module->prints as $entry) {
                $filePath     = $modulePath . '/' . $entry['file'];
                $declarations = JsoncParser::parseFile($filePath);
                if (!is_array($declarations)) {
                    throw new \RuntimeException(
                        "Module '{$module->id}': print file '{$entry['file']}' must contain an array of declarations",
                    );
                }
                foreach ($declarations as $raw) {
                    if (!is_array($raw)) {
                        throw new \RuntimeException(
                            "Module '{$module->id}': print file '{$entry['file']}' contains a non-object declaration",
                        );
                    }
                    $localized = ConfigLocalizer::localize($raw, $language);
                    $registry->add(PrintDefinition::fromArray($localized, $module->id));
                }
            }
        }

        return $registry;
    }
}
