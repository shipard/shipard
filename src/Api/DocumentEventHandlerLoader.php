<?php

declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Accounting\OpenItemLookup;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\JournalEventDispatcher;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;

/**
 * Sběr `documentEventHandlers` registrací z resolvovaných modulů →
 * DocumentEventDispatcher. Stejný vzor jako DocumentLoader / LookupLoader:
 * žádná kompilace do cfg, čte se za běhu z module.jsonc.
 *
 * Do dispatcheru se vkládá i `openItemLookup` (#69 D3) — z týchž
 * resolvovaných modulů, pokud ho volající nepředá. Každé místo konstrukce
 * dispatcheru (web, CLI, seed, import) tak routuje bankovní úhrady shodně,
 * bez nutnosti měnit signatury.
 */
class DocumentEventHandlerLoader
{
    public static function load(
        DataSourceConfig $config,
        ModulePathResolver $resolver,
        ?\Dibi\Connection $db = null,
        ?ConfigRuntime $configRuntime = null,
        ?JournalEventDispatcher $journalEvents = null,
        ?OpenItemLookup $openItems = null,
    ): DocumentEventDispatcher {
        $allModules      = ModuleLoader::loadAllModules($resolver);
        $errors          = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

        $registrations = [];
        foreach ($resolvedModules as $module) {
            foreach ($module->documentEventHandlers as $reg) {
                $registrations[] = $reg;
            }
        }

        $openItems ??= OpenItemLookupLoader::fromModules($resolvedModules, $db, $configRuntime, $config);

        return new DocumentEventDispatcher($registrations, $db, $configRuntime, $config, $journalEvents, $openItems);
    }
}
