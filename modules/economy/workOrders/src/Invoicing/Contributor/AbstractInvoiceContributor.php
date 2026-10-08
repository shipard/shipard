<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Invoicing\Contributor;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;

/**
 * Základ přispěvatele se settery závislostí (vzor AbstractJournalContributor):
 * registr je po instanci nastaví, přispěvatel se ptá DB a konfigurace.
 */
abstract class AbstractInvoiceContributor implements InvoiceContributor
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
}
