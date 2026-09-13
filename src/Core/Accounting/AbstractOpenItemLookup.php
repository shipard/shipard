<?php

declare(strict_types=1);

namespace Shipard\Core\Accounting;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;

/**
 * Báze pro poskytovatele `openItemLookup` — stejná trojice služeb a setterů
 * jako AbstractJournalEventHandler, injektuje je OpenItemLookupLoader při
 * instanciaci (bezparametrický konstruktor + settery).
 */
abstract class AbstractOpenItemLookup implements OpenItemLookup
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
