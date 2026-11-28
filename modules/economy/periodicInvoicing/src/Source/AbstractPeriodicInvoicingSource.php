<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing\Source;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;

/**
 * Základ zdroje se settery závislostí (vzor AbstractInvoiceContributor):
 * registr je po vytvoření instance nastaví, zdroj se ptá DB a konfigurace.
 */
abstract class AbstractPeriodicInvoicingSource implements PeriodicInvoicingSource
{
    protected ?\Dibi\Connection $db = null;
    protected ?ConfigRuntime $config = null;
    protected ?DataSourceConfig $dsConfig = null;

    public function setDb(\Dibi\Connection $db): void
    {
        $this->db = $db;
    }

    public function setConfig(ConfigRuntime $config): void
    {
        $this->config = $config;
    }

    public function setDsConfig(DataSourceConfig $dsConfig): void
    {
        $this->dsConfig = $dsConfig;
    }

    public function navSection(): ?string
    {
        return null;
    }
}
