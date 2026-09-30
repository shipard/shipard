<?php

declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Form\FormRegistry;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;

class FormLoader
{
    public static function load(DataSourceConfig $config, ModulePathResolver $resolver): FormRegistry
    {
        $allModules      = ModuleLoader::loadAllModules($resolver);
        $errors          = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

        $registry = new FormRegistry(self::mergeForms($resolvedModules));
        $registry->setDsConfig($config);

        return $registry;
    }

    /**
     * Merge `forms` registrations from multiple modules per table.
     *
     * Mirrors DocumentLoader::mergeDocumentClasses. Multi-module registrations
     * for the same table are valid when using `typeColumn` polymorphism —
     * docs.core registers the default class, docs.invoicesOut adds invno →
     * IssuedInvoiceForm, docs.invoicesIn adds invni → ReceivedInvoiceForm.
     *
     * @param array<int, ModuleDefinition> $modules
     * @return list<array<string, mixed>>
     */
    public static function mergeForms(array $modules): array
    {
        /** @var array<string, array<string, mixed>> tableId → merged registration */
        $byTable = [];

        foreach ($modules as $module) {
            foreach ($module->forms as $reg) {
                $table = $reg['table'] ?? null;
                if (!is_string($table) || $table === '') {
                    continue;
                }

                if (!isset($byTable[$table])) {
                    $byTable[$table] = $reg;
                    continue;
                }

                $existing = $byTable[$table];

                if (isset($reg['typeColumn'], $existing['typeColumn'])
                    && $reg['typeColumn'] !== $existing['typeColumn']
                ) {
                    throw new \LogicException(
                        "Conflicting typeColumn for table '{$table}': "
                        . "'{$existing['typeColumn']}' vs '{$reg['typeColumn']}' (module {$module->id})",
                    );
                }

                $merged = $existing;

                if (isset($reg['typeColumn']) && !isset($merged['typeColumn'])) {
                    $merged['typeColumn'] = $reg['typeColumn'];
                }

                if (isset($reg['classes']) && is_array($reg['classes'])) {
                    $merged['classes'] = $merged['classes'] ?? [];
                    foreach ($reg['classes'] as $typeKey => $className) {
                        if (isset($merged['classes'][$typeKey])
                            && $merged['classes'][$typeKey] !== $className
                        ) {
                            throw new \LogicException(
                                "Duplicate form class registration for table '{$table}', "
                                . "type '{$typeKey}': '{$merged['classes'][$typeKey]}' vs '{$className}' "
                                . "(module {$module->id})",
                            );
                        }
                        $merged['classes'][$typeKey] = $className;
                    }
                }

                if (isset($reg['defaultClass']) && !isset($merged['defaultClass'])) {
                    $merged['defaultClass'] = $reg['defaultClass'];
                }

                if (isset($reg['class']) && !isset($merged['class']) && !isset($merged['typeColumn'])) {
                    $merged['class'] = $reg['class'];
                }

                if (isset($reg['id']) && !isset($merged['id'])) {
                    $merged['id'] = $reg['id'];
                }

                $byTable[$table] = $merged;
            }
        }

        return array_values($byTable);
    }
}
