<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

use Shipard\Core\Accounting\JournalContributorSet;
use Shipard\Core\Accounting\OpenItemLookup;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;

/**
 * Báze pro documentEventHandlers — stejná trojice služeb a setterů jako
 * Document, injektuje je DocumentEventDispatcher při instanciaci.
 */
abstract class AbstractDocumentEventHandler implements DocumentEventHandler
{
    protected ?\Dibi\Connection $db = null;
    protected ?ConfigRuntime $config = null;
    protected ?DataSourceConfig $dsConfig = null;

    /**
     * Journal dispatcher pro handlery, které konstruují účtovací engine
     * (DocsHeadsEventHandler, BankTransactionEventHandler) — engine ho použije
     * k vyslání journalWritten. Injektuje DocumentEventDispatcher.
     */
    protected ?JournalEventDispatcher $journalEvents = null;

    /**
     * Dohledání otevřeného předpisu pro handlery konstruující bankovní
     * účtovací engine (#69 D3) — poskytovatel z `openItemLookup` v module.jsonc,
     * injektuje DocumentEventDispatcher. Null = engine si vezme Null objekt.
     */
    protected ?OpenItemLookup $openItems = null;

    /**
     * Contributoři deníku pro handlery konstruující účtovací engine
     * (#79 D3b) — sada z `journalContributors` v module.jsonc, injektuje
     * DocumentEventDispatcher. Null = engine si vezme prázdnou sadu.
     */
    protected ?JournalContributorSet $journalContributors = null;

    public function setDb(\Dibi\Connection $db): void
    {
        $this->db = $db;
    }

    public function setJournalEvents(?JournalEventDispatcher $journalEvents): void
    {
        $this->journalEvents = $journalEvents;
    }

    public function setOpenItems(?OpenItemLookup $openItems): void
    {
        $this->openItems = $openItems;
    }

    public function setJournalContributors(?JournalContributorSet $journalContributors): void
    {
        $this->journalContributors = $journalContributors;
    }

    public function setConfig(ConfigRuntime $config): void
    {
        $this->config = $config;
    }

    public function setDsConfig(DataSourceConfig $dsConfig): void
    {
        $this->dsConfig = $dsConfig;
    }

    public function onBeforeSave(string $tableId, array &$data, ?array $originalData): void
    {
    }

    public function onAfterSave(string $tableId, array $data, ?array $originalData): void
    {
    }

    public function onStateChanged(string $tableId, array $data, int $oldState, int $newState): void
    {
    }

    public function onBeforeDelete(string $tableId, array $data): void
    {
    }
}
