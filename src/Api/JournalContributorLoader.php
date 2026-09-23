<?php

declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Accounting\AbstractJournalContributor;
use Shipard\Core\Accounting\JournalContributor;
use Shipard\Core\Accounting\JournalContributorSet;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;

/**
 * Sběr registrací `journalContributors` z resolvovaných modulů → jedna
 * {@see JournalContributorSet} (#79 D3b). Mirror OpenItemLookupLoader;
 * čte se za běhu z module.jsonc, žádná kompilace do cfg.
 *
 * Víc modulů smí přispívat; pořadí = pořadí resolvovaných modulů × pořadí
 * pole. Bez registrace prázdná sada. Neexistující třída nebo třída bez
 * rozhraní = chyba konfigurace modulů (LogicException).
 */
class JournalContributorLoader
{
    public static function load(
        DataSourceConfig $config,
        ModulePathResolver $resolver,
        ?\Dibi\Connection $db = null,
        ?ConfigRuntime $configRuntime = null,
    ): JournalContributorSet {
        $allModules      = ModuleLoader::loadAllModules($resolver);
        $errors          = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

        return self::fromModules($resolvedModules, $db, $configRuntime, $config);
    }

    /**
     * Varianta nad už resolvovanými moduly (loadery dispatcherů je mají
     * v ruce a nemusí moduly resolvovat podruhé).
     *
     * @param iterable<ModuleDefinition> $modules
     */
    public static function fromModules(
        iterable $modules,
        ?\Dibi\Connection $db = null,
        ?ConfigRuntime $configRuntime = null,
        ?DataSourceConfig $dsConfig = null,
    ): JournalContributorSet {
        $contributors = [];
        foreach ($modules as $module) {
            foreach ($module->journalContributors as $class) {
                if (!class_exists($class)) {
                    throw new \LogicException("Module '{$module->id}': journalContributors class {$class} not found");
                }
                $contributor = new $class();
                if (!$contributor instanceof JournalContributor) {
                    throw new \LogicException("Class {$class} does not implement JournalContributor");
                }
                if ($contributor instanceof AbstractJournalContributor) {
                    if ($db !== null) {
                        $contributor->setDb($db);
                    }
                    if ($configRuntime !== null) {
                        $contributor->setConfig($configRuntime);
                    }
                    if ($dsConfig !== null) {
                        $contributor->setDsConfig($dsConfig);
                    }
                }
                $contributors[] = $contributor;
            }
        }

        return new JournalContributorSet($contributors);
    }
}
