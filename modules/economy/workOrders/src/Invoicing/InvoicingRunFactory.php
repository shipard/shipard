<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Invoicing;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Economy\WorkOrders\Invoicing\Contributor\InvoiceContributorRegistry;
use Shipard\Module\Economy\WorkOrders\WorkOrderTypes;

/**
 * Složení služby běhu z produkčního wiringu (CLI `work-orders-invoice-run`
 * a controller akcí zakázky): applier přes `DocumentApplier::create`,
 * evidence období, builder s konfigurací v jazyce dokumentu (lazy
 * `ConfigRuntime::load` per jazyk jako u tisku), typy zakázek.
 */
final class InvoicingRunFactory
{
    /**
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public static function create(
        Connection $db,
        ?ConfigRuntime $config,
        DataSourceConfig $dsConfig,
        DocumentRegistry $registry,
        array $tables,
        ?DocumentEventDispatcher $dispatcher = null,
    ): InvoicingRunService {
        if ($config === null) {
            throw new \RuntimeException('Periodická fakturace potřebuje zkompilovanou konfiguraci (ds-upgrade).');
        }
        $applier = DocumentApplier::create($db, $config, $dsConfig, $registry, $tables, $dispatcher);
        $dsDir = $dsConfig->getDataSourceDir();
        $configs = [];
        $configForLanguage = static function (string $language) use ($dsDir, &$configs): ?ConfigRuntime {
            if (!array_key_exists($language, $configs)) {
                $file = $dsDir . '/config/configuration/compiled.' . $language . '.json';
                try {
                    $configs[$language] = is_file($file) ? ConfigRuntime::load($dsDir, $language) : null;
                } catch (\Throwable) {
                    $configs[$language] = null;
                }
            }
            return $configs[$language];
        };
        $builder = new InvoiceBuilder($db, $config, $dsConfig->getCountry(), $configForLanguage);

        return new InvoicingRunService(
            $db,
            $config,
            new PeriodRepository($db),
            $builder,
            $applier,
            new WorkOrderTypes($config),
            InvoiceContributorRegistry::fromConfig($config, $db, $dsConfig),
        );
    }
}
