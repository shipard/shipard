<?php

declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;

/**
 * Sběr `recordSenderProviders` registrací z resolvovaných modulů (#90 D39)
 * — stejný vzor jako AttachmentGuardLoader: čte se za běhu z module.jsonc.
 *
 * Výsledek je mapa `tabulka → třída`; odesílatele záznamu určuje jediný
 * poskytovatel, druhá registrace téže tabulky je chyba konfigurace.
 */
class RecordSenderProviderLoader
{
    /** @return array<string, class-string> */
    public static function load(DataSourceConfig $config, ModulePathResolver $resolver): array
    {
        $allModules      = ModuleLoader::loadAllModules($resolver);
        $errors          = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

        $providers = [];
        foreach ($resolvedModules as $module) {
            foreach ($module->recordSenderProviders as $registration) {
                $table = $registration['table'];
                if (isset($providers[$table])) {
                    throw new \RuntimeException(
                        "Record sender provider for table '{$table}' is registered by more than one module"
                        . " (again in '{$module->id}')",
                    );
                }
                $providers[$table] = $registration['class'];
            }
        }
        return $providers;
    }
}
