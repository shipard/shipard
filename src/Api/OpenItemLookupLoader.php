<?php

declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Accounting\AbstractOpenItemLookup;
use Shipard\Core\Accounting\NullOpenItemLookup;
use Shipard\Core\Accounting\OpenItemLookup;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;

/**
 * Sběr registrace `openItemLookup` z resolvovaných modulů → jedna instance
 * {@see OpenItemLookup}. Mirror JournalEventHandlerLoader; čte se za běhu
 * z module.jsonc, žádná kompilace do cfg.
 *
 * Jeden poskytovatel per DS: bez registrace vrací {@see NullOpenItemLookup}
 * (vše na clearing), dvě registrace jsou chyba konfigurace modulů.
 */
class OpenItemLookupLoader
{
    public static function load(
        DataSourceConfig $config,
        ModulePathResolver $resolver,
        ?\Dibi\Connection $db = null,
        ?ConfigRuntime $configRuntime = null,
    ): OpenItemLookup {
        $allModules      = ModuleLoader::loadAllModules($resolver);
        $errors          = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

        return self::fromModules($resolvedModules, $db, $configRuntime, $config);
    }

    /**
     * Varianta nad už resolvovanými moduly (DocumentEventHandlerLoader je
     * má v ruce a nemusí moduly resolvovat podruhé).
     *
     * @param iterable<ModuleDefinition> $modules
     */
    public static function fromModules(
        iterable $modules,
        ?\Dibi\Connection $db = null,
        ?ConfigRuntime $configRuntime = null,
        ?DataSourceConfig $dsConfig = null,
    ): OpenItemLookup {
        $class = null;
        $owner = null;
        foreach ($modules as $module) {
            if ($module->openItemLookup === null) {
                continue;
            }
            if ($class !== null) {
                throw new \LogicException(sprintf(
                    'openItemLookup registered by both %s (%s) and %s (%s) — only one provider per data source is allowed',
                    $owner, $class, $module->id, $module->openItemLookup,
                ));
            }
            $class = $module->openItemLookup;
            $owner = $module->id;
        }

        if ($class === null) {
            return new NullOpenItemLookup();
        }
        if (!class_exists($class)) {
            throw new \LogicException("Module '{$owner}': openItemLookup class {$class} not found");
        }

        $lookup = new $class();
        if (!$lookup instanceof OpenItemLookup) {
            throw new \LogicException("Class {$class} does not implement OpenItemLookup");
        }

        if ($lookup instanceof AbstractOpenItemLookup) {
            if ($db !== null) {
                $lookup->setDb($db);
            }
            if ($configRuntime !== null) {
                $lookup->setConfig($configRuntime);
            }
            if ($dsConfig !== null) {
                $lookup->setDsConfig($dsConfig);
            }
        }

        return $lookup;
    }
}
