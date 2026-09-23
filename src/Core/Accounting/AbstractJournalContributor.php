<?php

declare(strict_types=1);

namespace Shipard\Core\Accounting;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;

/**
 * Báze pro `journalContributors` — stejná trojice služeb a setterů jako
 * AbstractOpenItemLookup, injektuje je JournalContributorLoader při
 * instanciaci (bezparametrický konstruktor + settery).
 */
abstract class AbstractJournalContributor implements JournalContributor
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
