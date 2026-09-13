<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;

/**
 * Báze pro journalEventHandlers — stejná trojice služeb a setterů jako
 * AbstractDocumentEventHandler, injektuje je JournalEventDispatcher při
 * instanciaci; navíc dispatcher sám sebe (handlery, které samy účtují).
 */
abstract class AbstractJournalEventHandler implements JournalEventHandler
{
    protected ?\Dibi\Connection $db = null;
    protected ?ConfigRuntime $config = null;
    protected ?DataSourceConfig $dsConfig = null;

    /**
     * Dispatcher, který handler volá — pro handlery, jež samy spouštějí
     * účtování (ClearingRerouteHandler přeúčtuje transakce a engine musí
     * vyslat journalWritten pro re-derivaci ledgeru). Re-entrantní dispatch
     * je bezpečný: čistý cyklus nad memoizovanými instancemi.
     */
    protected ?JournalEventDispatcher $journalEvents = null;

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

    public function setJournalEvents(?JournalEventDispatcher $journalEvents): void
    {
        $this->journalEvents = $journalEvents;
    }

    public function onJournalWritten(string $sourceKind, int $sourceId): void
    {
    }
}
